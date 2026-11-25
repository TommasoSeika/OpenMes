<?php

namespace Modules\Ortofrutta\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Ortofrutta\Domain\CrewPlanner;
use Modules\Ortofrutta\Models\Attendance;
use Modules\Ortofrutta\Models\Person;
use Modules\Ortofrutta\Models\ProductionCrew;

/**
 * Who works on which production.
 *
 * The shift leader organises people production by production: a line needs a
 * different crew for a different format, lines stop and start during the day,
 * and someone who is free on one line can cover another. People come from the
 * day's attendance; the rules (who can join, who clashes, the proposed crews)
 * are in CrewPlanner.
 */
class CrewService
{
    public function __construct(private PlanService $plan, private WorkSheetService $sheets) {}

    /** Everything the crews screen shows for a day. */
    public function board(CarbonImmutable $day): array
    {
        $people = Person::query()->orderBy('name')->get()->keyBy('id');
        $attendance = $this->attendance($day);
        $roster = $this->roster($people, $attendance);
        $productions = $this->plan->productions($day);
        $shapes = array_map(fn (array $p) => $this->shape($p), $productions);
        $issues = CrewPlanner::issues($shapes, $roster);
        $byKey = array_column($productions, null, 'key');

        $rows = [];
        $short = 0;
        foreach ($productions as $production) {
            $crew = [];
            $hasLeader = false;
            foreach ($production['crew'] as $personId) {
                $issue = $issues[$production['key']][$personId] ?? null;
                $person = $people[$personId] ?? null;
                $hasLeader = $hasLeader || $person?->qualification === 'Capolinea';
                $crew[] = [
                    'id' => $personId,
                    // Closed productions keep their crew as it was: nothing to flag.
                    'issue' => $production['closed'] ? null : ($issue['issue'] ?? null),
                    'note' => $production['closed'] || $issue === null ? null
                        : $this->issueNote($issue, $attendance[$personId] ?? null, $byKey),
                ];
            }
            $missing = max(0, $production['required'] - count($crew));
            $short += ! $production['closed'] && $missing > 0 ? 1 : 0;

            $sheet = $production['sheet'];
            $rows[] = [
                'key' => $production['key'],
                'line_id' => $production['line']->id,
                'line_code' => $production['line']->code,
                'line' => $production['line']->name,
                'item_code' => $production['item']->code,
                'item' => $production['item']->description,
                'variety' => $production['item']->variety,
                'size' => $production['item']->size,
                'format' => $production['item']->format,
                'pallets' => $production['pallets'],
                'customers' => collect($production['orders'])->map(fn ($o) => $o->customer?->name)->filter()->unique()->values()->all(),
                'orders' => collect($production['orders'])->pluck('number')->all(),
                'truck' => collect($production['orders'])->map(fn ($o) => $o->truck_at?->format('H:i'))->filter()->min(),
                'start' => $production['start']->format('H:i'),
                'end' => $production['end']->format('H:i'),
                'start_min' => $this->minutes($production['start']),
                'end_min' => $this->minutes($production['end']),
                'late' => $production['late'],
                'status' => $production['closed'] ? 'closed' : ($sheet !== null ? 'running' : 'planned'),
                'sheet_id' => $sheet?->id,
                'sheet_number' => $sheet?->number,
                'required' => $production['required'],
                'crew' => $crew,
                'missing' => $missing,
                'has_leader' => $hasLeader,
            ];
        }

        $busy = collect($productions)->flatMap(fn (array $p) => $p['crew'])->unique();

        return [
            'day' => $day->toDateString(),
            'lines' => $this->plan->lines()->map(fn ($l) => [
                'id' => $l->id, 'code' => $l->code, 'name' => $l->name, 'description' => $l->description,
            ])->values()->all(),
            'productions' => $rows,
            'people' => $people->map(fn (Person $p) => $this->presentPerson($p, $attendance[$p->id] ?? null))->values()->all(),
            'summary' => [
                'people' => $people->count(),
                'present' => $attendance->where('status', 'present')->count(),
                // Entries on the time clock this morning: what the crews are made from.
                'clocked_in' => $attendance->whereNotNull('clock_in')->count(),
                'early_exit' => $attendance->where('status', 'early_exit')->count(),
                'absent' => $people->count() - $attendance->whereIn('status', ['present', 'early_exit'])->count(),
                'productions' => count($rows),
                'short' => $short,
                'covered' => count($rows) - $short,
                // In today, enabled on at least one line, and on no production at all.
                'idle' => collect($roster)->filter(fn (array $p) => $p['lines'] !== [] && ! $busy->contains($p['id']))->count(),
            ],
        ];
    }

    public function assign(CarbonImmutable $day, int $key, Person $person): array
    {
        [$production, $productions] = $this->find($day, $key);
        $attendance = $this->attendance($day);
        $entry = $this->roster(collect([$person->id => $person]), $attendance)[0] ?? null;

        if ($entry === null) {
            $reason = $attendance[$person->id]?->reason ?? null;
            throw new DomainException(sprintf('%s oggi è assente%s.', $person->name, $reason ? ' ('.mb_strtolower($reason).')' : ''));
        }

        $refusal = CrewPlanner::refusal($entry, $this->shape($production), array_map(fn (array $p) => $this->shape($p), $productions));
        if ($refusal !== null) {
            throw new DomainException(match ($refusal['reason']) {
                'line' => sprintf('%s non è abilitato su %s.', $person->name, $production['line']->name),
                'away' => sprintf(
                    '%s esce alle %s: la produzione comincia alle %s.',
                    $person->name, $this->clock($entry['until']), $production['start']->format('H:i'),
                ),
                default => sprintf(
                    '%s è già su %s nello stesso orario.', $person->name,
                    $this->label(array_column($productions, null, 'key')[$refusal['with']] ?? null),
                ),
            });
        }

        DB::transaction(function () use ($production, $person) {
            foreach ($production['order_ids'] as $orderId) {
                ProductionCrew::query()->firstOrCreate(['order_id' => $orderId, 'person_id' => $person->id]);
            }
        });
        $this->refreshSheet($production);

        return $production;
    }

    public function unassign(CarbonImmutable $day, int $key, Person $person): array
    {
        [$production] = $this->find($day, $key);
        ProductionCrew::query()->whereIn('order_id', $production['order_ids'])->where('person_id', $person->id)->delete();
        $this->refreshSheet($production);

        return $production;
    }

    /** Take everybody off a production. */
    public function clear(CarbonImmutable $day, int $key): array
    {
        [$production] = $this->find($day, $key);
        ProductionCrew::query()->whereIn('order_id', $production['order_ids'])->delete();
        $this->refreshSheet($production);

        return $production;
    }

    /**
     * Bring over the crew of the production before on the same line: whoever
     * of them can also work this one.
     *
     * @return array{production: array, added: int, left: int} left = people of the crew before who could not come
     */
    public function copyPrevious(CarbonImmutable $day, int $key): array
    {
        [$production, $productions] = $this->find($day, $key);

        $previous = null;
        foreach ($productions as $other) {
            if ($other['line']->id === $production['line']->id && $other['start'] < $production['start']
                && ($previous === null || $other['start'] > $previous['start'])) {
                $previous = $other;
            }
        }
        if ($previous === null) {
            throw new DomainException(sprintf('È la prima produzione di %s: non c\'è una squadra da cui copiare.', $production['line']->name));
        }

        $roster = array_column($this->roster(Person::query()->whereIn('id', $previous['crew'])->get()->keyBy('id'), $this->attendance($day)), null, 'id');
        $shapes = array_map(fn (array $p) => $this->shape($p), $productions);
        $target = $this->shape($production);

        $added = 0;
        $left = 0;
        DB::transaction(function () use ($previous, $production, $roster, $shapes, $target, &$added, &$left) {
            foreach ($previous['crew'] as $personId) {
                if (in_array($personId, $production['crew'], true)) {
                    continue;
                }
                $entry = $roster[$personId] ?? null;
                if ($entry === null || CrewPlanner::refusal($entry, $target, $shapes) !== null) {
                    $left++;

                    continue;
                }
                foreach ($production['order_ids'] as $orderId) {
                    ProductionCrew::query()->firstOrCreate(['order_id' => $orderId, 'person_id' => $personId]);
                }
                $added++;
            }
        });
        $this->refreshSheet($production);

        return ['production' => $production, 'added' => $added, 'left' => $left];
    }

    /**
     * Fill every production that is not closed up to its requirement.
     *
     * @return list<array{label: string, missing: int}> the productions still short of people
     */
    public function propose(CarbonImmutable $day): array
    {
        $productions = $this->plan->productions($day);
        $roster = $this->roster(Person::query()->orderBy('badge')->get()->keyBy('id'), $this->attendance($day));
        $proposal = CrewPlanner::propose(
            array_map(fn (array $p) => $this->shape($p) + ['fixed' => $p['closed']], $productions),
            $roster,
        );

        $touched = [];
        DB::transaction(function () use ($productions, $proposal, &$touched) {
            foreach ($productions as $production) {
                if ($production['closed']) {
                    continue;
                }
                // Bookings that no longer hold after the plan changed: wrong line, not in, two places at once.
                $gone = $proposal['released'][$production['key']] ?? [];
                if ($gone !== []) {
                    ProductionCrew::query()->whereIn('order_id', $production['order_ids'])->whereIn('person_id', $gone)->delete();
                }
                $new = array_diff($proposal['crews'][$production['key']] ?? [], $production['crew']);
                foreach ($new as $personId) {
                    foreach ($production['order_ids'] as $orderId) {
                        ProductionCrew::query()->firstOrCreate(['order_id' => $orderId, 'person_id' => $personId]);
                    }
                }
                if ($new !== [] || $gone !== []) {
                    $touched[] = $production;
                }
            }
        });
        foreach ($touched as $production) {
            $this->refreshSheet($production);
        }

        $short = [];
        foreach ($productions as $production) {
            $missing = $proposal['missing'][$production['key']] ?? 0;
            if (! $production['closed'] && $missing > 0) {
                $short[] = ['label' => $this->label($production), 'missing' => $missing];
            }
        }

        return $short;
    }

    /** "Linea 4 delle 10:40" — how a production is named in a message. */
    public function label(?array $production): string
    {
        return $production === null ? 'un\'altra produzione'
            : sprintf('%s delle %s', $production['line']->name, $production['start']->format('H:i'));
    }

    /**
     * A production of the day that can still be changed, with all the others.
     *
     * @return array{0: array, 1: list<array>}
     */
    private function find(CarbonImmutable $day, int $key): array
    {
        $productions = $this->plan->productions($day);
        foreach ($productions as $production) {
            if (in_array($key, $production['order_ids'], true)) {
                if ($production['closed']) {
                    throw new DomainException('La produzione è chiusa: la sua squadra non si può più cambiare.');
                }

                return [$production, $productions];
            }
        }

        throw new DomainException('La produzione non è più nel piano di oggi: ricarica la pagina.');
    }

    /** An open work sheet follows its crew: people, hours and cost. */
    private function refreshSheet(array $production): void
    {
        $sheet = $production['sheet'];
        if ($sheet !== null && $sheet->status === 'open') {
            $this->sheets->syncCrew($sheet);
            $this->sheets->recost($sheet);
        }
    }

    /** A production as CrewPlanner wants it. */
    private function shape(array $production): array
    {
        return [
            'id' => $production['key'],
            'line' => $production['line']->code,
            'start' => $this->minutes($production['start']),
            'end' => $this->minutes($production['end']),
            'required' => $production['required'],
            'crew' => $production['crew'],
        ];
    }

    /** @return Collection<int, Attendance> keyed by person id */
    private function attendance(CarbonImmutable $day): Collection
    {
        return Attendance::query()->whereDate('day', $day->toDateString())->get()->keyBy('person_id');
    }

    /**
     * The people who are in today, as CrewPlanner wants them. Somebody in for
     * the whole shift has no end time: they stay until the lines finish.
     *
     * @param  Collection<int, Person>  $people
     * @param  Collection<int, Attendance>  $attendance
     * @return list<array{id: int, lines: list<string>, from: int, until: ?int, leader: bool, badge: string}>
     */
    private function roster(Collection $people, Collection $attendance): array
    {
        $roster = [];
        foreach ($people as $person) {
            $a = $attendance[$person->id] ?? null;
            if ($a === null || ! in_array($a->status, ['present', 'early_exit'], true)) {
                continue;
            }
            $roster[] = [
                'id' => (int) $person->id,
                'lines' => $person->enabled_lines ?? [],
                'from' => $this->timeToMinutes($a->planned_in) ?? 0,
                'until' => $a->status === 'early_exit' ? $this->timeToMinutes($a->planned_out) : null,
                'leader' => $person->qualification === 'Capolinea',
                'badge' => $person->badge,
            ];
        }

        return $roster;
    }

    private function presentPerson(Person $person, ?Attendance $attendance): array
    {
        $status = $attendance?->status ?? 'absent';
        $in = in_array($status, ['present', 'early_exit'], true);

        return [
            'id' => $person->id,
            'badge' => $person->badge,
            'name' => $person->name,
            'group' => $person->group_name,
            'qualification' => $person->qualification,
            'is_leader' => $person->qualification === 'Capolinea',
            'enabled_lines' => $person->enabled_lines ?? [],
            'status' => $status,
            'clock_in' => $in && $attendance->clock_in ? substr((string) $attendance->clock_in, 0, 5) : null,
            'from' => $in ? ($this->timeToMinutes($attendance->planned_in) ?? 0) : null,
            'until' => $status === 'early_exit' ? $this->timeToMinutes($attendance->planned_out) : null,
            'reason' => match ($status) {
                'present' => null,
                'early_exit' => 'Esce alle '.substr((string) $attendance?->planned_out, 0, 5),
                default => $attendance?->reason ?: 'Assente',
            },
        ];
    }

    private function issueNote(array $issue, ?Attendance $attendance, array $byKey): string
    {
        return match ($issue['issue']) {
            'line' => 'Non è abilitato su questa linea',
            'busy' => 'Anche su '.$this->label($byKey[$issue['with']] ?? null),
            'partial' => 'Esce alle '.substr((string) $attendance?->planned_out, 0, 5),
            default => $attendance?->status === 'early_exit'
                ? 'Esce alle '.substr((string) $attendance->planned_out, 0, 5).': non c\'è'
                : ($attendance?->reason ?: 'Assente'),
        };
    }

    private function minutes(CarbonImmutable $moment): int
    {
        return $moment->hour * 60 + $moment->minute;
    }

    private function timeToMinutes(?string $time): ?int
    {
        if ($time === null || $time === '') {
            return null;
        }
        [$h, $m] = array_map('intval', explode(':', $time) + [0, 0]);

        return $h * 60 + $m;
    }

    private function clock(?int $minutes): string
    {
        return $minutes === null ? '—' : sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
