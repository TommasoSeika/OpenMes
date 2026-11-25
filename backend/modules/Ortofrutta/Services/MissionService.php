<?php

namespace Modules\Ortofrutta\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Ortofrutta\Domain\MissionPlanner;
use Modules\Ortofrutta\Models\Attendance;
use Modules\Ortofrutta\Models\Lot;
use Modules\Ortofrutta\Models\Mission;
use Modules\Ortofrutta\Models\Person;
use Modules\Ortofrutta\Models\WorkSheetLot;
use Modules\Ortofrutta\Support\Demo;

/**
 * The work lists of the forklift drivers: which bins every line needs, from
 * which cell, by when, who brings them, and how far each mission has got.
 *
 * The lists are generated from the line plan, shared among the drivers who are
 * in, and carried out on the terminal by scanning: the label of the bins when
 * they are picked, the code of the line when they are delivered. A delivery
 * writes the lot on the work sheet of its production, so traceability comes
 * from what was scanned and not from what somebody remembered to type.
 */
class MissionService
{
    /** Minutes before a block starts by which its fruit should be at the line. */
    public const PICK_LEAD_MINUTES = 20;

    /** Less than this is not worth a trip: it is rounding left over from the missions before. */
    private const MIN_KG = 5.0;

    public const DRIVER = 'Carrellista';

    public function __construct(private PlanService $plan, private WorkSheetService $sheets) {}

    /**
     * What the lines still need from the cells: for every order not yet
     * closed, the oldest lot of the right variety first. Fruit a mission has
     * already taken is not asked for twice, and an order whose work sheet was
     * filled in by hand asks for nothing.
     *
     * @return array{needs: list<array<string, mixed>>, short: list<array<string, mixed>>}
     */
    public function needs(CarbonImmutable $day): array
    {
        $remaining = $this->sheets->remainingByLot();
        $yields = $this->sheets->yieldByVariety();
        $lots = Lot::query()->with(['producer', 'cell'])->orderBy('received_at')->orderBy('id')->get()->groupBy('variety');

        $done = Mission::query()->whereDate('day', $day->toDateString())->whereIn('status', ['picked', 'delivered'])->get();
        $taken = $done->groupBy('order_id')->map(fn (Collection $g) => (float) $g->sum('kg'));

        $blocks = [];
        foreach ($this->plan->scheduled($day) as $entry) {
            foreach ($entry['blocks'] as $block) {
                $blocks[] = $block + ['line' => $entry['line']];
            }
        }
        usort($blocks, fn ($a, $b) => [$a['start'], $a['line']->code] <=> [$b['start'], $b['line']->code]);

        // A sheet with more fruit on it than its missions delivered was filled in by hand.
        $sheetIds = collect($blocks)->map(fn ($b) => $b['order']->work_sheet_id)->filter()->unique()->values();
        $onSheet = $sheetIds->isEmpty() ? collect()
            : WorkSheetLot::query()->whereIn('work_sheet_id', $sheetIds)->selectRaw('work_sheet_id, sum(kg_in) as kg')
                ->groupBy('work_sheet_id')->pluck('kg', 'work_sheet_id');
        $scannedOnSheet = [];
        foreach ($blocks as $block) {
            $sheetId = $block['order']->work_sheet_id;
            if ($sheetId !== null) {
                $scannedOnSheet[$sheetId] = ($scannedOnSheet[$sheetId] ?? 0.0)
                    + (float) $done->where('order_id', $block['order']->id)->where('status', 'delivered')->sum('kg');
            }
        }

        $needs = [];
        $short = [];
        foreach ($blocks as $block) {
            $order = $block['order'];
            $sheet = $order->workSheet;
            // Fruit for a closed sheet has already been brought and weighed.
            if ($sheet?->status === 'closed') {
                continue;
            }
            if ($sheet !== null && (float) ($onSheet[$sheet->id] ?? 0) - ($scannedOnSheet[$sheet->id] ?? 0.0) > 1.0) {
                continue;
            }

            $variety = $order->item->variety;
            $needed = $order->pallets * $order->item->kgPerPallet() / ($yields[$variety] ?? WorkSheetService::DEFAULT_YIELD)
                - (float) ($taken[$order->id] ?? 0);
            $due = CarbonImmutable::instance($block['start'])->subMinutes(self::PICK_LEAD_MINUTES);

            foreach ($lots[$variety] ?? [] as $lot) {
                $left = $remaining[$lot->id] ?? 0.0;
                if ($needed < self::MIN_KG || $left < 1) {
                    continue;
                }
                $take = min($left, $needed);
                $remaining[$lot->id] = $left - $take;
                $needed -= $take;
                $needs[] = [
                    'order' => $order, 'line' => $block['line'], 'lot' => $lot,
                    'bins' => $this->binsFor($lot, $take), 'kg' => round($take), 'due_at' => $due,
                ];
            }
            if ($needed >= self::MIN_KG) {
                $short[] = [
                    'order' => $order->number, 'customer' => $order->customer?->name, 'line' => $block['line']->name,
                    'variety' => $variety, 'kg' => round($needed), 'by' => $due->format('H:i'),
                ];
            }
        }

        return ['needs' => $needs, 'short' => $short];
    }

    /**
     * Rebuild the lists from the plan as it is now. Missions already picked
     * or delivered are history and stay; the ones still to do are replaced.
     *
     * @return array{created: int, short: int, drivers: int}
     */
    public function generate(CarbonImmutable $day): array
    {
        return DB::transaction(function () use ($day) {
            $date = $day->toDateString();
            Mission::query()->whereDate('day', $date)->where('status', 'todo')->delete();

            $needs = $this->needs($day);
            $number = (int) Mission::query()->whereDate('day', $date)->max('number');
            foreach ($needs['needs'] as $need) {
                Mission::query()->create([
                    'number' => ++$number,
                    'day' => $date,
                    'order_id' => $need['order']->id,
                    'line_id' => $need['line']->id,
                    'lot_id' => $need['lot']->id,
                    'cell_id' => $need['lot']->cell_id,
                    'bins' => $need['bins'],
                    'kg' => $need['kg'],
                    'due_at' => $need['due_at'],
                    'priority' => (int) $need['order']->priority,
                    'status' => 'todo',
                ]);
            }

            return ['created' => count($needs['needs']), 'short' => count($needs['short']), 'drivers' => $this->share($day)];
        });
    }

    /**
     * Share the open missions among the forklift drivers who are in today.
     *
     * @return int how many drivers there are to share them among
     */
    public function share(CarbonImmutable $day): int
    {
        $drivers = $this->drivers($day);
        $open = Mission::query()->with('line')->whereDate('day', $day->toDateString())->whereIn('status', ['todo', 'picked'])->get();

        $given = MissionPlanner::assign(
            $open->map(fn (Mission $m) => $this->shape($m))->all(),
            $drivers->map(fn (array $d) => ['id' => $d['id'], 'badge' => $d['badge'], 'until' => $d['until']])->values()->all(),
        );
        foreach ($open as $mission) {
            $driver = $given[$mission->id] ?? null;
            if ((int) $mission->person_id !== (int) $driver) {
                $mission->update(['person_id' => $driver]);
            }
        }

        return $drivers->count();
    }

    /** Give one mission to another driver. */
    public function assign(Mission $mission, Person $person): void
    {
        if ($mission->status === 'delivered') {
            throw new DomainException(sprintf('La missione %s è già stata consegnata.', $mission->code()));
        }
        if (! $this->drivers(CarbonImmutable::parse($mission->day))->has($person->id)) {
            throw new DomainException(sprintf('%s non è un carrellista presente oggi.', $person->name));
        }
        $mission->update(['person_id' => $person->id]);
    }

    /**
     * A code read on the terminal for a mission.
     *
     * @return array{ok: bool, message: string, step: ?string}
     */
    public function scan(Mission $mission, string $code): array
    {
        $mission->loadMissing(['lot.producer', 'line', 'order']);
        $result = MissionPlanner::scan(
            ['status' => $mission->status, 'lot' => $mission->lot->code, 'line' => $mission->line->code], $code,
        );
        if (! $result['ok']) {
            return ['ok' => false, 'step' => $result['step'], 'message' => $this->refusal($mission, $result, $code)];
        }

        if ($result['step'] === 'pick') {
            $mission->update(['status' => 'picked', 'picked_at' => Demo::now()]);

            return ['ok' => true, 'step' => 'pick', 'message' => sprintf(
                'Lotto %s confermato: %d bins sulle forche. Ora portali a %s.', $mission->lot->code, $mission->bins, $mission->line->name,
            )];
        }

        $sheet = DB::transaction(function () use ($mission) {
            $mission->update(['status' => 'delivered', 'delivered_at' => Demo::now()]);

            // A production already launched gets the lot on its work sheet at once.
            $sheet = $mission->order->workSheet;
            if ($sheet !== null && $sheet->status === 'open') {
                $this->sheets->addDeliveredFruit($sheet, $mission->lot_id, (float) $mission->kg);
                $this->sheets->recost($sheet);

                return $sheet;
            }

            return null;
        });

        return ['ok' => true, 'step' => 'deliver', 'message' => sprintf(
            'Consegnato a %s: missione %s chiusa.%s', $mission->line->name, $mission->code(),
            $sheet !== null
                ? sprintf(' Il lotto %s è sul foglio %d.', $mission->lot->code, $sheet->number)
                : sprintf(' Il lotto %s andrà sul foglio quando la produzione viene lanciata.', $mission->lot->code),
        )];
    }

    /** Everything the work lists screen shows for a day. */
    public function board(CarbonImmutable $day): array
    {
        $now = Demo::now();
        $missions = Mission::query()->with(['order.customer', 'order.item', 'line', 'lot.producer', 'cell', 'person'])
            ->whereDate('day', $day->toDateString())->get();
        $ordered = collect(MissionPlanner::order($missions->map(fn (Mission $m) => $this->shape($m))->all()))->pluck('id');
        $rows = $ordered->map(fn ($id) => $this->present($missions->firstWhere('id', $id), $now))->values();

        $drivers = $this->drivers($day)->map(function (array $driver) use ($rows) {
            $mine = $rows->where('person_id', $driver['id']);

            return $driver + [
                'missions' => $mine->count(),
                'open' => $mine->where('status', '!=', 'delivered')->count(),
                'bins' => (int) $mine->sum('bins'),
            ];
        })->values()->all();

        // Would generating again change anything? Then the plan has moved since the lists were made.
        $needs = $this->needs($day);
        $signature = fn (Collection $c) => $c->map(fn ($r) => $r[0].'|'.$r[1].'|'.$r[2])->sort()->values()->all();
        $wanted = $signature(collect($needs['needs'])->map(fn ($n) => [$n['order']->id, $n['lot']->id, $n['bins']]));
        $have = $signature($missions->where('status', 'todo')->map(fn (Mission $m) => [$m->order_id, $m->lot_id, $m->bins])->values());

        return [
            'day' => $day->toDateString(),
            'missions' => $rows->all(),
            'drivers' => $drivers,
            'short' => $needs['short'],
            'stale' => $wanted !== $have,
            'summary' => [
                'total' => $rows->count(),
                'todo' => $rows->where('status', 'todo')->count(),
                'picked' => $rows->where('status', 'picked')->count(),
                'delivered' => $rows->where('status', 'delivered')->count(),
                'bins_open' => (int) $rows->where('status', '!=', 'delivered')->sum('bins'),
                'late' => $rows->where('late', true)->count(),
                'unassigned' => $rows->where('status', '!=', 'delivered')->whereNull('person_id')->count(),
            ],
        ];
    }

    /** One mission, flattened for the screens. */
    public function present(Mission $mission, ?CarbonImmutable $now = null): array
    {
        $now ??= Demo::now();
        $order = $mission->order;

        return [
            'id' => $mission->id,
            'code' => $mission->code(),
            'status' => $mission->status,
            'due' => $mission->due_at->format('H:i'),
            'late' => $mission->status !== 'delivered' && $mission->due_at->lessThan($now),
            'priority' => (int) $mission->priority,
            'line_id' => $mission->line_id,
            'line' => $mission->line?->name,
            'line_code' => $mission->line?->code,
            'lot' => $mission->lot?->code,
            'variety' => $mission->lot?->variety,
            'producer' => $mission->lot?->producer?->name,
            'cell' => $mission->cell?->code,
            'building' => $mission->cell?->building,
            'bins' => (int) $mission->bins,
            'kg' => (float) $mission->kg,
            'order' => $order?->number,
            'customer' => $order?->customer?->name,
            'item' => $order?->item?->description,
            'sheet_id' => $order?->work_sheet_id,
            'person_id' => $mission->person_id,
            'person' => $mission->person?->name,
            'picked_at' => $mission->picked_at?->format('H:i'),
            'delivered_at' => $mission->delivered_at?->format('H:i'),
        ];
    }

    /**
     * The forklift drivers who are in on a day, keyed by person id.
     *
     * @return Collection<int, array{id: int, name: string, badge: string, until: ?int, reason: ?string}>
     */
    public function drivers(CarbonImmutable $day): Collection
    {
        $attendance = Attendance::query()->whereDate('day', $day->toDateString())
            ->whereIn('status', ['present', 'early_exit'])->get()->keyBy('person_id');

        return Person::query()->where('qualification', self::DRIVER)->whereIn('id', $attendance->keys())->orderBy('name')->get()
            ->mapWithKeys(function (Person $person) use ($attendance) {
                $a = $attendance[$person->id];
                $early = $a->status === 'early_exit';
                [$h, $m] = array_map('intval', explode(':', (string) $a->planned_out) + [0, 0]);

                return [$person->id => [
                    'id' => (int) $person->id,
                    'name' => $person->name,
                    'badge' => $person->badge,
                    'until' => $early ? $h * 60 + $m : null,
                    'reason' => $early ? 'Esce alle '.substr((string) $a->planned_out, 0, 5) : null,
                ]];
            });
    }

    /** A mission as MissionPlanner wants it. */
    private function shape(Mission $mission): array
    {
        return [
            'id' => $mission->id,
            'due' => $mission->due_at->hour * 60 + $mission->due_at->minute,
            'priority' => (int) $mission->priority,
            'line' => $mission->line?->code,
            'bins' => (int) $mission->bins,
            'driver' => $mission->person_id !== null ? (int) $mission->person_id : null,
        ];
    }

    /** Why a scan was refused, in the words the driver needs. */
    private function refusal(Mission $mission, array $result, string $code): string
    {
        $read = $result['read'];
        $what = match ($read['kind'] ?? null) {
            'lot' => 'l\'etichetta di un bins',
            'line' => 'il codice di una linea',
            'cell' => 'il codice di una cella',
            'badge' => 'un badge',
            default => 'un codice',
        };

        return match ($result['problem']) {
            'done' => sprintf('La missione %s è già stata consegnata.', $mission->code()),
            'unreadable' => sprintf('Codice non riconosciuto: «%s».', mb_substr(trim($code), 0, 30)),
            'not_a_bin' => sprintf('Qui serve l\'etichetta del bins: hai letto %s.', $what),
            'wrong_lot' => sprintf(
                'Lotto sbagliato: hai letto %s, la missione chiede %s (%s, %s). Lascia il bins dov\'è.',
                $read['value'], $mission->lot->code, $mission->lot->variety, $mission->lot->producer?->name,
            ),
            'not_a_line' => sprintf('Qui serve il codice della linea: hai letto %s.', $what),
            default => sprintf('Linea sbagliata: hai letto %s, questi bins vanno a %s.', $read['value'], $mission->line->name),
        };
    }

    private function binsFor(Lot $lot, float $kg): int
    {
        $perBin = $lot->bins > 0 ? (float) $lot->net_kg / $lot->bins : 300.0;

        return (int) max(1, ceil($kg / max(1.0, $perBin)));
    }
}
