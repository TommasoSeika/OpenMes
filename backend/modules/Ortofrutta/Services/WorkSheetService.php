<?php

namespace Modules\Ortofrutta\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Ortofrutta\Domain\WorkSheetCosting;
use Modules\Ortofrutta\Models\Attendance;
use Modules\Ortofrutta\Models\Line;
use Modules\Ortofrutta\Models\Lot;
use Modules\Ortofrutta\Models\Order;
use Modules\Ortofrutta\Models\Person;
use Modules\Ortofrutta\Models\ProductionCrew;
use Modules\Ortofrutta\Models\WorkSheet;
use Modules\Ortofrutta\Models\WorkSheetLot;
use Modules\Ortofrutta\Models\WorkSheetPerson;
use Modules\Ortofrutta\Support\Demo;

/**
 * Work sheets: created from the plan, filled in with lots and kilograms,
 * costed every time they are saved. Nobody types a cost.
 */
class WorkSheetService
{
    /** Yield assumed for a variety that has no closed sheet yet. */
    public const DEFAULT_YIELD = 0.88;

    public function nextNumber(): int
    {
        return ((int) WorkSheet::query()->max('number') ?: 1600) + 1;
    }

    /**
     * One sheet for a launch: the consecutive blocks of the same item.
     *
     * @param  list<array>  $blocks  blocks timed by LinePlanner, each with its 'order'
     */
    public function createFromPlan(Line $line, CarbonImmutable $day, array $blocks): WorkSheet
    {
        return DB::transaction(function () use ($line, $day, $blocks) {
            $first = $blocks[0];
            $last = $blocks[count($blocks) - 1];

            $sheet = WorkSheet::query()->create([
                'number' => $this->nextNumber(),
                'day' => $day->toDateString(),
                'line_id' => $line->id,
                'item_id' => $first['order']->item_id,
                'started_at' => $first['start'],
                'ended_at' => $last['end'],
                'pallets' => array_sum(array_column($blocks, 'pallets')),
                'status' => 'open',
            ]);

            Order::query()->whereIn('id', array_column($blocks, 'id'))->update(['work_sheet_id' => $sheet->id]);
            $this->syncCrew($sheet);
            $this->recost($sheet);

            return $sheet;
        });
    }

    /**
     * The crew of the sheet is the crew organised for its production: the
     * people booked on its orders. Hours are the part of the sheet each
     * person was present for.
     */
    public function syncCrew(WorkSheet $sheet): void
    {
        $day = CarbonImmutable::parse($sheet->day);
        $personIds = ProductionCrew::query()
            ->whereIn('order_id', Order::query()->where('work_sheet_id', $sheet->id)->select('id'))
            ->distinct()->pluck('person_id');
        $people = Person::query()->whereIn('id', $personIds)->get();
        $attendance = Attendance::query()->whereDate('day', $day->toDateString())
            ->whereIn('person_id', $personIds)->get()->keyBy('person_id');

        WorkSheetPerson::query()->where('work_sheet_id', $sheet->id)->delete();
        $rows = [];
        foreach ($people as $person) {
            $a = $attendance[$person->id] ?? null;
            $in = $a?->clock_in ?: $a?->planned_in;
            $out = $a?->clock_out ?: $a?->planned_out;
            $rows[] = [
                'work_sheet_id' => $sheet->id,
                'person_id' => $person->id,
                'hours' => WorkSheetCosting::hoursOnSheet(
                    $sheet->started_at,
                    $sheet->ended_at,
                    $in ? Demo::at($day, substr((string) $in, 0, 5)) : null,
                    $out ? Demo::at($day, substr((string) $out, 0, 5)) : null,
                ),
                'hourly_cost' => (float) $person->hourly_cost,
            ];
        }
        WorkSheetPerson::query()->insert($rows);
    }

    /**
     * Save what the line manager enters at the end of the run.
     *
     * @param  array{started_at?: string, ended_at?: string, lots: list<array{lot_id: int, kg_in: float, kg_packed: float, kg_waste_cider: float, kg_waste_industry: float}>}  $data
     */
    public function save(WorkSheet $sheet, array $data): WorkSheet
    {
        if ($sheet->status === 'closed') {
            throw new DomainException('Il foglio è chiuso e non si può più modificare.');
        }

        return DB::transaction(function () use ($sheet, $data) {
            $day = CarbonImmutable::parse($sheet->day);
            $times = [];
            foreach (['started_at', 'ended_at'] as $field) {
                if (! empty($data[$field])) {
                    $times[$field] = Demo::at($day, $data[$field]);
                }
            }
            if ($times !== []) {
                $sheet->update($times);
                $sheet->refresh();
                if ($sheet->ended_at <= $sheet->started_at) {
                    throw new DomainException('L\'ora di fine deve essere dopo l\'ora di inizio.');
                }
                $this->syncCrew($sheet);
            }

            WorkSheetLot::query()->where('work_sheet_id', $sheet->id)->delete();
            foreach ($data['lots'] ?? [] as $row) {
                $lot = Lot::query()->findOrFail($row['lot_id']);
                $available = $this->remainingKg($lot);
                if ((float) $row['kg_in'] > $available + 0.5) {
                    throw new DomainException(sprintf(
                        'Il lotto %s ha solo %s kg in cella.', $lot->code, number_format($available, 0, ',', '.'),
                    ));
                }
                WorkSheetLot::query()->create([
                    'work_sheet_id' => $sheet->id,
                    'lot_id' => $lot->id,
                    'kg_in' => (float) $row['kg_in'],
                    'kg_packed' => (float) $row['kg_packed'],
                    'kg_waste_cider' => (float) $row['kg_waste_cider'],
                    'kg_waste_industry' => (float) $row['kg_waste_industry'],
                ]);
            }

            return $this->recost($sheet);
        });
    }

    public function close(WorkSheet $sheet): WorkSheet
    {
        $sheet = $this->recost($sheet);
        if ((float) $sheet->kg_packed <= 0) {
            throw new DomainException('Per chiudere il foglio servono i kg confezionati di almeno un lotto.');
        }
        $sheet->update(['status' => 'closed', 'closed_at' => Demo::now()]);

        return $sheet;
    }

    /** Recompute totals and costs from the rows and write them on the sheet. */
    public function recost(WorkSheet $sheet): WorkSheet
    {
        $costing = $this->costing($sheet);
        $sheet->update([
            'kg_in' => $costing['kg_in'],
            'kg_packed' => $costing['kg_packed'],
            'kg_waste_cider' => $costing['kg_waste_cider'],
            'kg_waste_industry' => $costing['kg_waste_industry'],
            'labour_cost' => round($costing['labour_cost'], 2),
            'packaging_cost' => round($costing['packaging_cost'], 2),
            'cost_per_kg' => $costing['cost_per_kg'] !== null ? round($costing['cost_per_kg'], 4) : null,
        ]);

        return $sheet->refresh();
    }

    /** The costing of a sheet from its current rows. */
    public function costing(WorkSheet $sheet): array
    {
        $sheet->loadMissing(['item.components']);

        return WorkSheetCosting::compute(
            WorkSheetPerson::query()->where('work_sheet_id', $sheet->id)->get(['hours', 'hourly_cost'])->toArray(),
            WorkSheetLot::query()->where('work_sheet_id', $sheet->id)
                ->get(['kg_in', 'kg_packed', 'kg_waste_cider', 'kg_waste_industry'])->toArray(),
            (float) $sheet->item->kg_per_carton,
            (int) $sheet->item->cartons_per_pallet,
            $sheet->item->components->map(fn ($c) => [
                'quantity' => (float) $c->quantity, 'per' => $c->per, 'unit_cost' => (float) $c->unit_cost,
            ])->all(),
        );
    }

    /** Kilograms of a lot still in its cell, optionally ignoring one sheet's own rows. */
    public function remainingKg(Lot $lot, ?int $exceptSheetId = null): float
    {
        $used = WorkSheetLot::query()->where('lot_id', $lot->id)
            ->when($exceptSheetId, fn ($q) => $q->where('work_sheet_id', '!=', $exceptSheetId))
            ->sum('kg_in');

        return max(0.0, (float) $lot->net_kg - (float) $used);
    }

    /**
     * Kilograms left per lot id.
     *
     * @return array<int, float>
     */
    public function remainingByLot(): array
    {
        $used = WorkSheetLot::query()->selectRaw('lot_id, sum(kg_in) as kg')->groupBy('lot_id')->pluck('kg', 'lot_id');

        return Lot::query()->get(['id', 'net_kg'])
            ->mapWithKeys(fn (Lot $l) => [$l->id => max(0.0, (float) $l->net_kg - (float) ($used[$l->id] ?? 0))])->all();
    }

    /**
     * Yield per variety from the closed sheets, used to estimate how much fruit a run needs.
     *
     * @return array<string, float>
     */
    public function yieldByVariety(): array
    {
        return DB::table('orto_work_sheet_lots as wl')
            ->join('orto_lots as l', 'l.id', '=', 'wl.lot_id')
            ->selectRaw('l.variety, sum(wl.kg_packed) as packed, sum(wl.kg_in) as kg_in')
            ->groupBy('l.variety')->get()
            ->filter(fn ($r) => (float) $r->kg_in > 0)
            ->mapWithKeys(fn ($r) => [$r->variety => (float) $r->packed / (float) $r->kg_in])->all();
    }

    /**
     * The oldest lots of the right variety that cover the sheet, as a starting point.
     *
     * @return list<array<string, mixed>>
     */
    public function suggestLots(WorkSheet $sheet): array
    {
        $sheet->loadMissing('item');
        $yield = $this->yieldByVariety()[$sheet->item->variety] ?? self::DEFAULT_YIELD;
        $needed = $sheet->pallets * $sheet->item->kgPerPallet() / $yield;
        $remaining = $this->remainingByLot();

        $rows = [];
        foreach (Lot::query()->with(['producer', 'cell'])->where('variety', $sheet->item->variety)->orderBy('received_at')->get() as $lot) {
            $left = $remaining[$lot->id] ?? 0.0;
            if ($left < 1 || $needed < 1) {
                continue;
            }
            $take = round(min($left, $needed));
            $needed -= $take;
            $rows[] = $this->presentLot($lot, $left) + ['kg_in' => $take];
        }

        return $rows;
    }

    public function presentLot(Lot $lot, float $remainingKg): array
    {
        return [
            'lot_id' => $lot->id,
            'code' => $lot->code,
            'producer' => $lot->producer?->name,
            'variety' => $lot->variety,
            'cell' => $lot->cell?->code,
            'remaining_kg' => round($remainingKg),
            'purchase_price_kg' => (float) $lot->purchase_price_kg,
        ];
    }
}
