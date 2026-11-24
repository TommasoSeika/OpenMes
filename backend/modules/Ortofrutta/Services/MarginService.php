<?php

namespace Modules\Ortofrutta\Services;

use Carbon\CarbonImmutable;
use Modules\Ortofrutta\Domain\CostCalculator;
use Modules\Ortofrutta\Models\PriceListEntry;
use Modules\Ortofrutta\Models\WorkSheet;

/**
 * Processing cost and margin per sheet, lot, producer and customer.
 *
 * Built from the closed work sheets only. The processing cost per kilogram of
 * a sheet is the figure WorkSheetCosting wrote on it, so this screen shows the
 * same €/kg as the sheet itself.
 */
class MarginService
{
    /**
     * @param  array{from?: ?string, to?: ?string, producer_id?: ?int, customer_id?: ?int, lot_id?: ?int}  $filters
     */
    public function report(array $filters = []): array
    {
        $sheets = WorkSheet::query()
            ->with(['line', 'item', 'lots.lot.producer', 'orders.customer'])
            ->where('status', 'closed')
            ->where('kg_packed', '>', 0)
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('day', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('day', '<=', $to))
            ->orderBy('number')
            ->get();

        $prices = $this->prices();

        $sheetRows = [];
        $lotAgg = [];
        $producerAgg = [];
        $customerAgg = [];

        foreach ($sheets as $sheet) {
            $processing = $sheet->cost_per_kg;
            $week = $this->week(CarbonImmutable::parse($sheet->day));

            // Net price of the sheet: its orders' prices after discount, weighted by pallets.
            $palletsPriced = 0;
            $revenueWeight = 0.0;
            $orderPrices = [];
            foreach ($sheet->orders as $order) {
                $entry = $prices[$order->customer_id][$sheet->item_id][$week] ?? null;
                if ($entry === null) {
                    continue;
                }
                $net = CostCalculator::netPrice($entry['price'], $entry['discount']);
                $orderPrices[$order->id] = $net;
                $palletsPriced += $order->pallets;
                $revenueWeight += $net * $order->pallets;
            }
            $netPrice = $palletsPriced > 0 ? $revenueWeight / $palletsPriced : null;

            // Raw fruit paid for, per kilogram sold, for the sheet as a whole.
            $fruitCost = 0.0;
            foreach ($sheet->lots as $row) {
                $fruitCost += (float) $row->kg_in * (float) $row->lot->purchase_price_kg;
            }
            $sheetMaterial = $fruitCost / (float) $sheet->kg_packed;

            $matchesFilter = false;
            foreach ($sheet->lots as $row) {
                $lot = $row->lot;
                if (($filters['producer_id'] ?? null) && (int) $lot->producer_id !== (int) $filters['producer_id']) {
                    continue;
                }
                if (($filters['lot_id'] ?? null) && (int) $lot->id !== (int) $filters['lot_id']) {
                    continue;
                }
                if ((float) $row->kg_packed <= 0) {
                    continue;
                }
                $matchesFilter = true;
                $e = CostCalculator::lotEconomics(
                    (float) $lot->purchase_price_kg, (float) $row->kg_in, (float) $row->kg_packed, $processing, $netPrice,
                );
                $this->add($lotAgg, $lot->id, [
                    'label' => $lot->code, 'sub' => $lot->producer?->name, 'variety' => $lot->variety,
                    'purchase_price' => (float) $lot->purchase_price_kg,
                ], $row, $e);
                $this->add($producerAgg, $lot->producer_id, [
                    'label' => $lot->producer?->name, 'sub' => $lot->producer?->code, 'variety' => null, 'purchase_price' => null,
                ], $row, $e);
            }

            $customerFilter = $filters['customer_id'] ?? null;
            $hasCustomer = ! $customerFilter || $sheet->orders->contains(fn ($o) => (int) $o->customer_id === (int) $customerFilter);
            $lotFiltered = ($filters['producer_id'] ?? null) || ($filters['lot_id'] ?? null);
            if (($lotFiltered && ! $matchesFilter) || ! $hasCustomer) {
                continue;
            }

            // Customers share the sheet's kilograms in proportion to their pallets.
            $totalPallets = max(1, (int) $sheet->orders->sum('pallets'));
            foreach ($sheet->orders as $order) {
                if ($customerFilter && (int) $order->customer_id !== (int) $customerFilter) {
                    continue;
                }
                if (! isset($orderPrices[$order->id])) {
                    continue;
                }
                $kg = (float) $sheet->kg_packed * $order->pallets / $totalPallets;
                $key = $order->customer_id;
                $customerAgg[$key] ??= ['label' => $order->customer?->name, 'kg' => 0.0, 'revenue' => 0.0, 'material' => 0.0, 'processing' => 0.0];
                $customerAgg[$key]['kg'] += $kg;
                $customerAgg[$key]['revenue'] += $kg * $orderPrices[$order->id];
                $customerAgg[$key]['material'] += $kg * $sheetMaterial;
                $customerAgg[$key]['processing'] += $kg * (float) $processing;
            }

            $yield = CostCalculator::yield((float) $sheet->kg_packed, (float) $sheet->kg_in);
            $sheetRows[] = [
                'id' => $sheet->id,
                'number' => $sheet->number,
                'day' => CarbonImmutable::parse($sheet->day)->format('d/m'),
                'line' => $sheet->line?->code,
                'item_code' => $sheet->item?->code,
                'variety' => $sheet->item?->variety,
                'customers' => $sheet->orders->map(fn ($o) => $o->customer?->name)->filter()->unique()->values()->all(),
                'kg_in' => round((float) $sheet->kg_in),
                'kg_packed' => round((float) $sheet->kg_packed),
                'yield' => $yield,
                'labour_cost' => (float) $sheet->labour_cost,
                'packaging_cost' => (float) $sheet->packaging_cost,
                'processing' => $processing,
                'material' => $sheetMaterial,
                'net_price' => $netPrice,
                'margin' => $netPrice !== null && $processing !== null
                    ? CostCalculator::marginPerKg($netPrice, $sheetMaterial, (float) $processing) : null,
            ];
        }

        $lots = $this->finish($lotAgg);
        $producers = $this->finish($producerAgg);

        $customers = [];
        foreach ($customerAgg as $id => $c) {
            if ($c['kg'] <= 0) {
                continue;
            }
            $customers[] = [
                'id' => $id,
                'label' => $c['label'],
                'kg_packed' => round($c['kg']),
                'net_price' => $c['revenue'] / $c['kg'],
                'material' => $c['material'] / $c['kg'],
                'processing' => $c['processing'] / $c['kg'],
                'margin' => ($c['revenue'] - $c['material'] - $c['processing']) / $c['kg'],
                'margin_total' => $c['revenue'] - $c['material'] - $c['processing'],
            ];
        }
        usort($customers, fn ($a, $b) => $b['margin'] <=> $a['margin']);

        $kg = array_sum(array_column($sheetRows, 'kg_packed'));
        $processingTotal = array_sum(array_map(fn ($s) => $s['labour_cost'] + $s['packaging_cost'], $sheetRows));

        return [
            'sheets' => $sheetRows,
            'lots' => $lots,
            'producers' => $producers,
            'customers' => $customers,
            'totals' => [
                'sheets' => count($sheetRows),
                'kg_packed' => $kg,
                'processing' => $kg > 0 ? $processingTotal / $kg : null,
                'margin' => $this->weighted($producers, 'margin'),
                'yield' => $this->weighted($producers, 'yield', 'kg_in'),
            ],
        ];
    }

    /**
     * The price simulator: what must a kilogram sell for, given what the fruit
     * cost, how much of it survives the line and the discount of the period.
     *
     * @return array{yield: float, material: ?float, processing: float, minimum_net: ?float, minimum_list: ?float, net_price: ?float, margin: ?float}
     */
    public function simulate(float $purchasePrice, float $yieldPct, float $processing, float $discountPct, ?float $listPrice = null): array
    {
        $yield = $yieldPct / 100;
        $material = CostCalculator::materialCostPerKgSold($purchasePrice, $yield);
        $minimum = $material !== null ? CostCalculator::minimumPrice($material, $processing) : null;
        $net = $listPrice !== null ? CostCalculator::netPrice($listPrice, $discountPct) : null;

        return [
            'yield' => $yield,
            'material' => $material,
            'processing' => $processing,
            'minimum_net' => $minimum,
            'minimum_list' => $minimum !== null ? CostCalculator::minimumListPrice($minimum, $discountPct) : null,
            'net_price' => $net,
            'margin' => $net !== null && $material !== null ? CostCalculator::marginPerKg($net, $material, $processing) : null,
        ];
    }

    /** ISO week of a day, as the price list writes it: 2026-W48. */
    public function week(CarbonImmutable $day): string
    {
        return sprintf('%d-W%02d', $day->isoWeekYear, $day->isoWeek);
    }

    /** @return array<int, array<int, array<string, array{price: float, discount: float}>>> customer → item → week */
    private function prices(): array
    {
        $out = [];
        foreach (PriceListEntry::query()->get() as $p) {
            $out[$p->customer_id][$p->item_id][$p->week] = ['price' => (float) $p->price_kg, 'discount' => (float) $p->discount_pct];
        }

        return $out;
    }

    /** Accumulate one sheet-lot row into a lot or producer total. */
    private function add(array &$agg, int|string $key, array $meta, object $row, array $economics): void
    {
        $agg[$key] ??= $meta + [
            'id' => $key, 'kg_in' => 0.0, 'kg_packed' => 0.0, 'waste_cider' => 0.0, 'waste_industry' => 0.0,
            'fruit_cost' => 0.0, 'processing_cost' => 0.0, 'revenue' => 0.0, 'kg_priced' => 0.0, 'sheets' => 0,
        ];
        $packed = (float) $row->kg_packed;
        $agg[$key]['kg_in'] += (float) $row->kg_in;
        $agg[$key]['kg_packed'] += $packed;
        $agg[$key]['waste_cider'] += (float) $row->kg_waste_cider;
        $agg[$key]['waste_industry'] += (float) $row->kg_waste_industry;
        $agg[$key]['fruit_cost'] += $packed * (float) $economics['material'];
        $agg[$key]['processing_cost'] += $packed * (float) $economics['processing'];
        if ($economics['net_price'] !== null) {
            $agg[$key]['revenue'] += $packed * $economics['net_price'];
            $agg[$key]['kg_priced'] += $packed;
        }
        $agg[$key]['sheets']++;
    }

    /** Totals → figures per kilogram, best margin first. */
    private function finish(array $agg): array
    {
        $rows = [];
        foreach ($agg as $a) {
            if ($a['kg_packed'] <= 0) {
                continue;
            }
            $material = $a['fruit_cost'] / $a['kg_packed'];
            $processing = $a['processing_cost'] / $a['kg_packed'];
            $net = $a['kg_priced'] > 0 ? $a['revenue'] / $a['kg_priced'] : null;
            $rows[] = [
                'id' => $a['id'],
                'label' => $a['label'],
                'sub' => $a['sub'],
                'variety' => $a['variety'],
                'purchase_price' => $a['purchase_price'],
                'sheets' => $a['sheets'],
                'kg_in' => round($a['kg_in']),
                'kg_packed' => round($a['kg_packed']),
                'waste_cider' => round($a['waste_cider']),
                'waste_industry' => round($a['waste_industry']),
                'yield' => CostCalculator::yield($a['kg_packed'], $a['kg_in']),
                'material' => $material,
                'processing' => $processing,
                'minimum' => CostCalculator::minimumPrice($material, $processing),
                'net_price' => $net,
                'margin' => $net !== null ? CostCalculator::marginPerKg($net, $material, $processing) : null,
                'margin_total' => $net !== null ? ($net - $material - $processing) * $a['kg_packed'] : null,
            ];
        }
        usort($rows, fn ($a, $b) => ($b['margin'] ?? -INF) <=> ($a['margin'] ?? -INF));

        return $rows;
    }

    private function weighted(array $rows, string $field, string $weight = 'kg_packed'): ?float
    {
        $sum = 0.0;
        $total = 0.0;
        foreach ($rows as $row) {
            if ($row[$field] === null) {
                continue;
            }
            $sum += $row[$field] * $row[$weight];
            $total += $row[$weight];
        }

        return $total > 0 ? $sum / $total : null;
    }
}
