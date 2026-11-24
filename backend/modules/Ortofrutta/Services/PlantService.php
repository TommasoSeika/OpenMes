<?php

namespace Modules\Ortofrutta\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Ortofrutta\Models\Cell;
use Modules\Ortofrutta\Models\Lot;
use Modules\Ortofrutta\Models\Producer;
use Modules\Ortofrutta\Support\Demo;

/**
 * The plant as the shift leader sees it: what is in every cold-storage cell,
 * what each line is doing, what has to leave with each truck, and which bins
 * the forklift drivers have to bring.
 */
class PlantService
{
    /** Minutes before a block starts by which its fruit should be at the line. */
    private const PICK_LEAD_MINUTES = 20;

    public function __construct(private PlanService $plan, private WorkSheetService $sheets) {}

    /**
     * Every cell with the lots it holds and how full it is.
     *
     * @return list<array<string, mixed>>
     */
    public function cells(): array
    {
        $remaining = $this->sheets->remainingByLot();
        $lots = Lot::query()->with('producer')->orderBy('received_at')->get()->groupBy('cell_id');

        return Cell::query()->orderBy('code')->get()->map(function (Cell $cell) use ($lots, $remaining) {
            $rows = [];
            $bins = 0;
            $kg = 0.0;
            foreach ($lots[$cell->id] ?? [] as $lot) {
                $left = $remaining[$lot->id] ?? 0.0;
                if ($left < 1) {
                    continue;
                }
                $lotBins = $this->binsFor($lot, $left);
                $bins += $lotBins;
                $kg += $left;
                $rows[] = [
                    'id' => $lot->id,
                    'code' => $lot->code,
                    'producer' => $lot->producer?->name,
                    'variety' => $lot->variety,
                    'received' => $lot->received_at->format('d/m/Y'),
                    'kg' => round($left),
                    'bins' => $lotBins,
                ];
            }

            return [
                'id' => $cell->id,
                'code' => $cell->code,
                'building' => $cell->building,
                'block' => $cell->block,
                'row' => (int) $cell->row,
                'col' => (int) $cell->col,
                'capacity_bins' => (int) $cell->capacity_bins,
                'temperature_c' => $cell->temperature_c !== null ? (float) $cell->temperature_c : null,
                'bins' => $bins,
                'kg' => round($kg),
                'fill' => $cell->capacity_bins > 0 ? min(1, round($bins / $cell->capacity_bins, 2)) : 0,
                'varieties' => array_values(array_unique(array_column($rows, 'variety'))),
                'lots' => $rows,
            ];
        })->all();
    }

    /** What each line is doing at the demo clock, and what comes next. */
    public function lines(array $board): array
    {
        $now = Demo::clock();

        return array_map(function (array $line) use ($now) {
            $current = null;
            $next = null;
            foreach ($line['blocks'] as $block) {
                if ($block['start'] <= $now && $now < $block['end']) {
                    $current = $block;
                } elseif ($block['start'] > $now && $next === null) {
                    $next = $block;
                }
            }

            return [
                'id' => $line['id'],
                'code' => $line['code'],
                'name' => $line['name'],
                'description' => $line['description'],
                'required_people' => $line['required_people'],
                'assigned_people' => $line['assigned_people'],
                'understaffed' => $line['understaffed'],
                'blocks' => count($line['blocks']),
                'pallets' => $line['pallets'],
                'end' => $line['end'],
                'late_count' => $line['late_count'],
                'current' => $current,
                'next' => $next,
            ];
        }, $board['lines']);
    }

    /**
     * Bins to bring to the lines, generated from the plan: oldest lot of the
     * right variety first. Read-only: nothing is reserved or moved.
     *
     * @return list<array<string, mixed>>
     */
    public function missions(CarbonImmutable $day): array
    {
        $remaining = $this->sheets->remainingByLot();
        $yields = $this->sheets->yieldByVariety();
        $lots = Lot::query()->with(['producer', 'cell'])->orderBy('received_at')->orderBy('id')->get()->groupBy('variety');

        $blocks = [];
        foreach ($this->plan->scheduled($day) as $entry) {
            foreach ($entry['blocks'] as $block) {
                // Fruit for a closed sheet has already been brought and weighed.
                if ($block['order']->workSheet?->status === 'closed') {
                    continue;
                }
                $blocks[] = $block + ['line' => $entry['line']];
            }
        }
        usort($blocks, fn ($a, $b) => [$a['start'], $a['line']->code] <=> [$b['start'], $b['line']->code]);

        $missions = [];
        foreach ($blocks as $block) {
            $order = $block['order'];
            $variety = $order->item->variety;
            $needed = $order->pallets * $order->item->kgPerPallet() / ($yields[$variety] ?? WorkSheetService::DEFAULT_YIELD);
            foreach ($lots[$variety] ?? [] as $lot) {
                $left = $remaining[$lot->id] ?? 0.0;
                if ($needed < 1 || $left < 1) {
                    continue;
                }
                $take = min($left, $needed);
                $remaining[$lot->id] = $left - $take;
                $needed -= $take;
                $missions[] = [
                    'by' => $block['start']->modify(sprintf('-%d minutes', self::PICK_LEAD_MINUTES))->format('H:i'),
                    'line' => $block['line']->name,
                    'line_code' => $block['line']->code,
                    'order' => $order->number,
                    'customer' => $order->customer?->name,
                    'item_code' => $order->item->code,
                    'variety' => $variety,
                    'lot' => $lot->code,
                    'producer' => $lot->producer?->name,
                    'cell' => $lot->cell?->code,
                    'building' => $lot->cell?->building,
                    'bins' => $this->binsFor($lot, $take),
                    'kg' => round($take),
                    'launched' => $order->work_sheet_id !== null,
                    'short' => false,
                ];
            }
            if ($needed >= 1) {
                $missions[] = [
                    'by' => $block['start']->format('H:i'), 'line' => $block['line']->name, 'line_code' => $block['line']->code,
                    'order' => $order->number, 'customer' => $order->customer?->name, 'item_code' => $order->item->code,
                    'variety' => $variety, 'lot' => null, 'producer' => null, 'cell' => null, 'building' => null,
                    'bins' => 0, 'kg' => round($needed), 'launched' => $order->work_sheet_id !== null, 'short' => true,
                ];
            }
        }

        return $missions;
    }

    /** The cell to propose for a delivery: one that already holds the variety and has room, else an empty one. */
    public function suggestCell(string $variety, int $bins): ?Cell
    {
        $cells = collect($this->cells());
        $match = $cells->first(fn ($c) => $c['varieties'] === [$variety] && $c['bins'] + $bins <= $c['capacity_bins'])
            ?? $cells->first(fn ($c) => $c['bins'] === 0 && $bins <= $c['capacity_bins']);

        return $match ? Cell::query()->find($match['id']) : null;
    }

    /**
     * Record a delivery weighed at the gate and put it in a cell.
     *
     * @param  array{producer_id: int, variety: string, gross_kg: float, bins: int, bin_tare_kg: float, cell_id: int, purchase_price_kg: float}  $data
     */
    public function receive(array $data): Lot
    {
        $tare = round($data['bins'] * $data['bin_tare_kg'], 1);
        $next = (int) Lot::query()->count() + 1;
        do {
            $code = sprintf('LT%s-%03d', Demo::day()->format('y'), $next++);
        } while (Lot::query()->where('code', $code)->exists());

        return Lot::query()->create([
            'code' => $code,
            'producer_id' => $data['producer_id'],
            'variety' => $data['variety'],
            'received_at' => Demo::now(),
            'gross_kg' => $data['gross_kg'],
            'tare_kg' => $tare,
            'net_kg' => round($data['gross_kg'] - $tare, 1),
            'bins' => $data['bins'],
            'cell_id' => $data['cell_id'],
            'purchase_price_kg' => $data['purchase_price_kg'],
        ]);
    }

    /** Deliveries, newest first, with what is left of each. */
    public function deliveries(): array
    {
        $remaining = $this->sheets->remainingByLot();

        return Lot::query()->with(['producer', 'cell'])->orderByDesc('received_at')->orderByDesc('id')->get()
            ->map(fn (Lot $lot) => [
                'id' => $lot->id,
                'code' => $lot->code,
                'received' => $lot->received_at->format('d/m/Y H:i'),
                'producer' => $lot->producer?->name,
                'producer_code' => $lot->producer?->code,
                'variety' => $lot->variety,
                'gross_kg' => round($lot->gross_kg),
                'tare_kg' => round($lot->tare_kg),
                'net_kg' => round($lot->net_kg),
                'bins' => (int) $lot->bins,
                'cell' => $lot->cell?->code,
                'building' => $lot->cell?->building,
                'purchase_price_kg' => (float) $lot->purchase_price_kg,
                'remaining_kg' => round($remaining[$lot->id] ?? 0),
            ])->all();
    }

    /** @return Collection<int, Producer> */
    public function producers(): Collection
    {
        return Producer::query()->orderBy('name')->get(['id', 'code', 'name']);
    }

    /** Bins that hold a given weight of a lot, from the lot's own average bin weight. */
    private function binsFor(Lot $lot, float $kg): int
    {
        $perBin = $lot->bins > 0 ? (float) $lot->net_kg / $lot->bins : 300.0;

        return (int) max(1, ceil($kg / max(1.0, $perBin)));
    }
}
