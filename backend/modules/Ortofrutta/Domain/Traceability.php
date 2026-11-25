<?php

namespace Modules\Ortofrutta\Domain;

use DateTimeInterface;

/**
 * The rules behind traceability: how a finished product is identified, and how
 * the fruit of a work sheet is shared out among the orders it served.
 *
 * Pure functions, so they can be tested without a database.
 */
final class Traceability
{
    /**
     * The packing lot printed on every pallet of a work sheet: "L", the year,
     * the day of the year, and the number of the sheet. Sheet 1702 of
     * 24 November 2026 packs lot L26328-1702.
     */
    public static function packingLot(DateTimeInterface $day, int $sheetNumber): string
    {
        return sprintf('L%s%03d-%d', $day->format('y'), (int) $day->format('z') + 1, $sheetNumber);
    }

    /** The sheet number inside a packing lot code, or null when the text is not one. */
    public static function sheetNumber(string $code): ?int
    {
        return preg_match('/^L?\d{5}-(\d{1,9})$/i', trim($code), $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * Share out the kilograms packed from each raw lot among the orders of the
     * sheet, in proportion to their pallets.
     *
     * A sheet packs one item for one or more orders in a single run, and the
     * line does not record which carton came from which bin. So every order
     * gets the same mix of lots as the whole sheet: nothing is lost and
     * nothing is counted twice, which is what a recall needs.
     *
     * @param  array<int|string, float|int>  $packedByLot  lot => kilograms packed from it on the sheet
     * @param  array<int|string, int>  $palletsByOrder  order => pallets
     * @return array<int|string, array<int|string, float>> order => lot => kilograms
     */
    public static function allocate(array $packedByLot, array $palletsByOrder): array
    {
        $pallets = array_sum($palletsByOrder);

        $out = [];
        foreach ($palletsByOrder as $order => $count) {
            $share = $pallets > 0 ? $count / $pallets : 0.0;
            foreach ($packedByLot as $lot => $kg) {
                $out[$order][$lot] = (float) $kg * $share;
            }
        }

        return $out;
    }
}
