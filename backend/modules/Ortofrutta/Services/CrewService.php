<?php

namespace Modules\Ortofrutta\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Ortofrutta\Models\Attendance;
use Modules\Ortofrutta\Models\CrewAssignment;
use Modules\Ortofrutta\Models\Line;
use Modules\Ortofrutta\Models\Person;

/**
 * Who works on which line. People come from the day's attendance: only those
 * present for the whole shift and enabled on the line can be assigned.
 */
class CrewService
{
    /** Per line: required, assigned, missing, and who could still be added. */
    public function board(CarbonImmutable $day): array
    {
        $date = $day->toDateString();
        $people = Person::query()->orderBy('name')->get()->keyBy('id');
        $attendance = Attendance::query()->whereDate('day', $date)->get()->keyBy('person_id');
        $assignments = CrewAssignment::query()->whereDate('day', $date)->get();
        $lineOf = $assignments->pluck('line_id', 'person_id');

        $lines = [];
        foreach (Line::query()->orderBy('sort')->orderBy('code')->get() as $line) {
            $assigned = $assignments->where('line_id', $line->id)
                ->map(fn ($a) => $this->presentPerson($people[$a->person_id], $attendance[$a->person_id] ?? null))
                ->sortBy([['is_leader', 'desc'], ['name', 'asc']])->values()->all();

            $candidates = [];
            $unavailable = [];
            foreach ($people as $person) {
                if (! in_array($line->code, $person->enabled_lines ?? [], true) || isset($lineOf[$person->id])) {
                    continue;
                }
                $row = $this->presentPerson($person, $attendance[$person->id] ?? null);
                if ($row['available']) {
                    $candidates[] = $row;
                } else {
                    $unavailable[] = $row;
                }
            }

            $lines[] = [
                'id' => $line->id,
                'code' => $line->code,
                'name' => $line->name,
                'description' => $line->description,
                'required' => (int) $line->required_people,
                'assigned' => $assigned,
                'missing' => max(0, (int) $line->required_people - count($assigned)),
                'candidates' => $candidates,
                'unavailable' => $unavailable,
            ];
        }

        $present = $attendance->where('status', 'present')->count();

        return [
            'day' => $date,
            'lines' => $lines,
            'summary' => [
                'people' => $people->count(),
                'present' => $present,
                'early_exit' => $attendance->where('status', 'early_exit')->count(),
                'absent' => $attendance->where('status', 'absent')->count(),
                'assigned' => $assignments->count(),
                'required' => array_sum(array_column($lines, 'required')),
            ],
            // Present people with no line: forklift drivers, quality, pallet area.
            'off_line' => $people->filter(fn (Person $p) => ($p->enabled_lines ?? []) === [])
                ->map(fn (Person $p) => $this->presentPerson($p, $attendance[$p->id] ?? null))->values()->all(),
        ];
    }

    public function assign(CarbonImmutable $day, Line $line, Person $person): void
    {
        $attendance = Attendance::query()->whereDate('day', $day->toDateString())->where('person_id', $person->id)->first();

        if (! in_array($line->code, $person->enabled_lines ?? [], true)) {
            throw new DomainException(sprintf('%s non è abilitato su %s.', $person->name, $line->name));
        }
        if ($attendance === null || $attendance->status === 'absent') {
            throw new DomainException(sprintf('%s oggi è assente%s.', $person->name, $attendance?->reason ? ' ('.mb_strtolower($attendance->reason).')' : ''));
        }
        if ($attendance->status === 'early_exit') {
            throw new DomainException(sprintf('%s oggi esce alle %s: non si può assegnare a una linea.', $person->name, substr((string) $attendance->planned_out, 0, 5)));
        }

        CrewAssignment::query()->updateOrCreate(
            ['day' => $day->toDateString(), 'person_id' => $person->id],
            ['line_id' => $line->id],
        );
    }

    public function unassign(CarbonImmutable $day, Person $person): void
    {
        CrewAssignment::query()->whereDate('day', $day->toDateString())->where('person_id', $person->id)->delete();
    }

    /**
     * Fill every line up to its requirement. Lines with the fewest possible
     * people are served first, and within a line the people who can work
     * nowhere else go first, so flexible people stay free for the other lines.
     *
     * @return array<string, int> missing people per line code
     */
    public function propose(CarbonImmutable $day): array
    {
        $date = $day->toDateString();
        $available = Attendance::query()->whereDate('day', $date)->where('status', 'present')->pluck('person_id')->flip();
        $taken = CrewAssignment::query()->whereDate('day', $date)->pluck('line_id', 'person_id');
        $people = Person::query()->orderBy('badge')->get()->filter(fn (Person $p) => isset($available[$p->id]));

        $lines = Line::query()->get()->sortBy(
            fn (Line $l) => $people->filter(fn (Person $p) => in_array($l->code, $p->enabled_lines ?? [], true))->count()
        );

        $missing = [];
        DB::transaction(function () use ($lines, $people, $taken, $date, &$missing) {
            foreach ($lines as $line) {
                $have = $taken->filter(fn ($lineId) => (int) $lineId === (int) $line->id)->count();
                /** @var Collection<int, Person> $pool */
                $pool = $people
                    ->filter(fn (Person $p) => ! isset($taken[$p->id]) && in_array($line->code, $p->enabled_lines ?? [], true))
                    ->sortBy(fn (Person $p) => [$p->qualification === 'Capolinea' ? 0 : 1, count($p->enabled_lines ?? []), $p->badge])
                    ->values();

                foreach ($pool as $person) {
                    if ($have >= (int) $line->required_people) {
                        break;
                    }
                    CrewAssignment::query()->create(['day' => $date, 'line_id' => $line->id, 'person_id' => $person->id]);
                    $taken[$person->id] = $line->id;
                    $have++;
                }
                $missing[$line->code] = max(0, (int) $line->required_people - $have);
            }
        });

        return $missing;
    }

    private function presentPerson(Person $person, ?Attendance $attendance): array
    {
        $status = $attendance?->status ?? 'absent';

        return [
            'id' => $person->id,
            'badge' => $person->badge,
            'name' => $person->name,
            'group' => $person->group_name,
            'qualification' => $person->qualification,
            'is_leader' => $person->qualification === 'Capolinea',
            'enabled_lines' => $person->enabled_lines ?? [],
            'status' => $status,
            'available' => $status === 'present',
            'reason' => match ($status) {
                'present' => null,
                'early_exit' => 'Esce alle '.substr((string) $attendance?->planned_out, 0, 5),
                default => $attendance?->reason ?: 'Assente',
            },
        ];
    }
}
