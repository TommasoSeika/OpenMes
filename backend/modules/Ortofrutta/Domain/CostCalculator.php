<?php

namespace Modules\Ortofrutta\Domain;

/**
 * The calculation rules of the fruit packing house, in one place.
 *
 * Every screen that shows a cost, a yield or a margin calls these methods, so
 * the same work sheet always gives the same €/kg wherever it is displayed.
 * Pure functions: no database, no framework, no rounding. Callers round for
 * display only.
 *
 *   labour cost            = Σ hours on the sheet × hourly cost, per person
 *   packaging cost         = packaging bill of materials × units produced
 *   processing cost €/kg   = (labour + packaging) ÷ kg packed
 *   yield                  = kg packed ÷ kg in
 *   material cost €/kg     = purchase price ÷ yield
 *   margin €/kg            = net selling price − material − processing
 *   minimum price          = material + processing
 */
final class CostCalculator
{
    /**
     * @param  iterable<array{hours: float|int, hourly_cost: float|int}>  $people
     */
    public static function labourCost(iterable $people): float
    {
        $total = 0.0;
        foreach ($people as $person) {
            $total += (float) $person['hours'] * (float) $person['hourly_cost'];
        }

        return $total;
    }

    /**
     * Each bill-of-materials row is counted per carton or per pallet.
     *
     * @param  iterable<array{quantity: float|int, per: string, unit_cost: float|int}>  $components
     */
    public static function packagingCost(iterable $components, float $cartons, float $pallets): float
    {
        $total = 0.0;
        foreach ($components as $component) {
            $units = $component['per'] === 'pallet' ? $pallets : $cartons;
            $total += (float) $component['quantity'] * $units * (float) $component['unit_cost'];
        }

        return $total;
    }

    /** Null when nothing was packed: a cost per kg of zero kilograms has no meaning. */
    public static function processingCostPerKg(float $labourCost, float $packagingCost, float $kgPacked): ?float
    {
        if ($kgPacked <= 0) {
            return null;
        }

        return ($labourCost + $packagingCost) / $kgPacked;
    }

    /** Kilograms packed out of kilograms brought to the line, between 0 and 1. */
    public static function yield(float $kgPacked, float $kgIn): ?float
    {
        if ($kgIn <= 0) {
            return null;
        }

        return $kgPacked / $kgIn;
    }

    /**
     * What one sold kilogram cost in raw fruit. A producer whose fruit yields
     * 70% costs more per sold kilogram than one at 90%, at the same purchase
     * price: the waste is paid for but not sold.
     */
    public static function materialCostPerKgSold(float $purchasePricePerKg, ?float $yield): ?float
    {
        if ($yield === null || $yield <= 0) {
            return null;
        }

        return $purchasePricePerKg / $yield;
    }

    /** List price after the period discount, given as a percentage (10 = 10%). */
    public static function netPrice(float $listPricePerKg, float $discountPct = 0.0): float
    {
        return $listPricePerKg * (1 - $discountPct / 100);
    }

    public static function marginPerKg(float $netPricePerKg, float $materialCostPerKg, float $processingCostPerKg): float
    {
        return $netPricePerKg - $materialCostPerKg - $processingCostPerKg;
    }

    /** Below this net price the kilogram is sold at a loss. */
    public static function minimumPrice(float $materialCostPerKg, float $processingCostPerKg): float
    {
        return $materialCostPerKg + $processingCostPerKg;
    }

    /**
     * The list price that still leaves the minimum net price once the period
     * discount is taken off. Null for a 100% discount.
     */
    public static function minimumListPrice(float $minimumNetPrice, float $discountPct): ?float
    {
        if ($discountPct >= 100) {
            return null;
        }

        return $minimumNetPrice / (1 - $discountPct / 100);
    }

    /**
     * The whole chain for one lot on one work sheet, as the screens show it.
     *
     * @return array{yield: ?float, material: ?float, processing: ?float, minimum: ?float, net_price: ?float, margin: ?float}
     */
    public static function lotEconomics(
        float $purchasePricePerKg,
        float $kgIn,
        float $kgPacked,
        ?float $processingCostPerKg,
        ?float $netPricePerKg,
    ): array {
        $yield = self::yield($kgPacked, $kgIn);
        $material = self::materialCostPerKgSold($purchasePricePerKg, $yield);
        $known = $material !== null && $processingCostPerKg !== null;

        return [
            'yield' => $yield,
            'material' => $material,
            'processing' => $processingCostPerKg,
            'minimum' => $known ? self::minimumPrice($material, $processingCostPerKg) : null,
            'net_price' => $netPricePerKg,
            'margin' => $known && $netPricePerKg !== null
                ? self::marginPerKg($netPricePerKg, $material, $processingCostPerKg)
                : null,
        ];
    }
}
