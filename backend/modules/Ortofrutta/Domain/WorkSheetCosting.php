<?php

namespace Modules\Ortofrutta\Domain;

use DateTimeInterface;

/**
 * Turns what is known about a work sheet (who worked, for how long, which lots
 * went in, what came out) into its totals and its cost per kilogram.
 *
 * The only place a sheet is costed: the demo loader, the sheet screen and the
 * margin screen all call compute(), so they cannot disagree.
 */
final class WorkSheetCosting
{
    /**
     * @param  iterable<array{hours: float|int, hourly_cost: float|int}>  $people
     * @param  iterable<array{kg_in: float|int, kg_packed: float|int, kg_waste_cider: float|int, kg_waste_industry: float|int}>  $lots
     * @param  iterable<array{quantity: float|int, per: string, unit_cost: float|int}>  $components
     * @return array{kg_in: float, kg_packed: float, kg_waste_cider: float, kg_waste_industry: float, cartons: float, pallets: float, labour_cost: float, packaging_cost: float, cost_per_kg: ?float, labour_per_kg: ?float, packaging_per_kg: ?float, yield: ?float}
     */
    public static function compute(
        iterable $people,
        iterable $lots,
        float $kgPerCarton,
        int $cartonsPerPallet,
        iterable $components,
    ): array {
        $kgIn = $kgPacked = $cider = $industry = 0.0;
        foreach ($lots as $lot) {
            $kgIn += (float) $lot['kg_in'];
            $kgPacked += (float) $lot['kg_packed'];
            $cider += (float) $lot['kg_waste_cider'];
            $industry += (float) $lot['kg_waste_industry'];
        }

        // Units produced follow from the kilograms packed: nobody types them in.
        $cartons = $kgPerCarton > 0 ? $kgPacked / $kgPerCarton : 0.0;
        $pallets = $cartonsPerPallet > 0 ? $cartons / $cartonsPerPallet : 0.0;

        $labour = CostCalculator::labourCost($people);
        $packaging = CostCalculator::packagingCost($components, $cartons, $pallets);

        return [
            'kg_in' => $kgIn,
            'kg_packed' => $kgPacked,
            'kg_waste_cider' => $cider,
            'kg_waste_industry' => $industry,
            'cartons' => $cartons,
            'pallets' => $pallets,
            'labour_cost' => $labour,
            'packaging_cost' => $packaging,
            'cost_per_kg' => CostCalculator::processingCostPerKg($labour, $packaging, $kgPacked),
            'labour_per_kg' => $kgPacked > 0 ? $labour / $kgPacked : null,
            'packaging_per_kg' => $kgPacked > 0 ? $packaging / $kgPacked : null,
            'yield' => CostCalculator::yield($kgPacked, $kgIn),
        ];
    }

    /**
     * Hours a person spent on a sheet: the part of the sheet that falls inside
     * their presence. Someone who leaves at 12:30 costs a 11:00–14:00 sheet 1.5 hours.
     */
    public static function hoursOnSheet(
        DateTimeInterface $sheetStart,
        DateTimeInterface $sheetEnd,
        ?DateTimeInterface $presentFrom = null,
        ?DateTimeInterface $presentUntil = null,
    ): float {
        $from = $sheetStart->getTimestamp();
        $until = $sheetEnd->getTimestamp();
        if ($presentFrom !== null) {
            $from = max($from, $presentFrom->getTimestamp());
        }
        if ($presentUntil !== null) {
            $until = min($until, $presentUntil->getTimestamp());
        }

        return $until > $from ? round(($until - $from) / 3600, 2) : 0.0;
    }
}
