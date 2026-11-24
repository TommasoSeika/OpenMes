<?php

namespace Modules\Ortofrutta\Domain;

use DateTimeImmutable;

/**
 * The calculation rules, checked on worked examples.
 *
 * `php artisan ortofrutta:verifica` runs this in the running application, where
 * PHPUnit is not installed. The same cases are PHPUnit tests in
 * tests/Unit/Ortofrutta, which is what continuous integration runs.
 */
final class SelfCheck
{
    /**
     * @return list<array{label: string, ok: bool, expected: string, actual: string}>
     */
    public static function run(): array
    {
        $results = [];
        foreach (self::cases() as $label => [$expected, $actual]) {
            $ok = is_float($expected) || is_float($actual)
                ? abs((float) $expected - (float) $actual) < 0.00005
                : $expected === $actual;
            $results[] = [
                'label' => $label,
                'ok' => $ok,
                'expected' => self::show($expected),
                'actual' => self::show($actual),
            ];
        }

        return $results;
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}> label => [expected, actual]
     */
    public static function cases(): array
    {
        $day = new DateTimeImmutable('2026-11-27 07:30');
        $at = fn (string $time) => new DateTimeImmutable('2026-11-27 '.$time);

        $planner = new LinePlanner(['variety' => 25, 'format' => 15, 'packaging' => 8]);
        $line = ['startup_minutes' => 15, 'capacities' => ['Cartone' => 6.0, 'Cassetta' => 5.0]];
        $abateCarton = ['code' => 'A', 'variety' => 'Abate', 'format' => 'Cartone', 'packaging' => 'Marchio'];
        $abateNeutral = ['code' => 'B', 'variety' => 'Abate', 'format' => 'Cartone', 'packaging' => 'Neutro'];
        $abateCrate = ['code' => 'C', 'variety' => 'Abate', 'format' => 'Cassetta', 'packaging' => 'Legno'];
        $apple = ['code' => 'D', 'variety' => 'Mela', 'format' => 'Cartone', 'packaging' => 'Marchio'];
        $abateBigger = ['code' => 'E', 'variety' => 'Abate', 'format' => 'Cartone', 'packaging' => 'Marchio'];

        $block = fn (int $id, array $item, int $pallets, ?string $truck = null, ?string $arrived = null) => [
            'id' => $id, 'item' => $item, 'pallets' => $pallets, 'priority' => 1,
            'truck_at' => $truck ? $at($truck) : null, 'arrived_at' => $arrived ? $at($arrived) : null,
        ];

        // One block: 6 pallets at 6 pallets/hour, plus 15 minutes of start-up.
        $single = $planner->schedule($line, [$block(1, $abateCarton, 6)], $day);

        // Two orders, both for the 12:00 truck. In this order both make it...
        $good = $planner->schedule($line, [$block(1, $abateCarton, 12, '12:00'), $block(2, $abateNeutral, 12, '12:00')], $day);
        // ...with a variety changeover in between, the second one does not.
        $bad = $planner->schedule($line, [
            $block(1, $abateCarton, 12, '12:00'), $block(3, $apple, 1), $block(2, $abateNeutral, 12, '12:00'),
        ], $day);

        // Moving an order to the front changes the end time of everything after it.
        $before = $planner->schedule($line, [$block(1, $abateCarton, 6), $block(2, $abateCrate, 5)], $day);
        $after = $planner->schedule($line, [$block(2, $abateCrate, 5), $block(1, $abateCarton, 6)], $day);

        // An order that arrives at 10:00 cannot start at 07:30.
        $waiting = $planner->schedule($line, [$block(1, $abateCarton, 6, null, '10:00')], $day);

        // The proposal puts equal items together and keeps every truck.
        $proposal = $planner->propose(['L1' => $line], [
            $block(1, $abateCarton, 4, '15:00'), $block(2, $apple, 4, '15:00'),
            $block(3, $abateCarton, 4, '15:00'), $block(4, $apple, 4, '15:00'),
        ], $day);
        $sequence = implode('', array_map(fn ($b) => $b['item']['code'], $proposal['lines']['L1']));
        $proposed = $planner->schedule($line, $proposal['lines']['L1'], $day);

        $people = array_fill(0, 22, ['hours' => 2, 'hourly_cost' => 18.0]);
        $bom = [
            ['quantity' => 1, 'per' => 'carton', 'unit_cost' => 0.50],
            ['quantity' => 2, 'per' => 'carton', 'unit_cost' => 0.05],
            ['quantity' => 1, 'per' => 'pallet', 'unit_cost' => 8.00],
        ];
        $sheet = WorkSheetCosting::compute(
            $people,
            [
                ['kg_in' => 6000, 'kg_packed' => 5400, 'kg_waste_cider' => 350, 'kg_waste_industry' => 220],
                ['kg_in' => 2000, 'kg_packed' => 1400, 'kg_waste_cider' => 400, 'kg_waste_industry' => 190],
            ],
            13.0, 48, $bom,
        );

        return [
            'Prezzo minimo: acquisto 1,00 € + lavorazione 0,20 € a resa piena = 1,20 €' => [
                1.20, CostCalculator::minimumPrice(CostCalculator::materialCostPerKgSold(1.00, 1.0), 0.20),
            ],
            'Costo manodopera: 22 persone × 2 ore × 18 €/ora = 792 €' => [792.0, CostCalculator::labourCost($people)],
            'Costo imballi: distinta × unità prodotte (100 colli su 2 pedane) = 76 €' => [
                76.0, CostCalculator::packagingCost($bom, 100, 2),
            ],
            'Costo di lavorazione: (792 € + 208 €) ÷ 5.000 kg = 0,20 €/kg' => [
                0.20, CostCalculator::processingCostPerKg(792.0, 208.0, 5000),
            ],
            'Costo di lavorazione: senza kg confezionati non c\'è un €/kg' => [
                null, CostCalculator::processingCostPerKg(792.0, 208.0, 0),
            ],
            'Resa: 900 kg confezionati ÷ 1.000 kg entrati = 90%' => [0.90, CostCalculator::yield(900, 1000)],
            'Costo materia: 1,00 € ÷ resa 80% = 1,25 € per kg venduto' => [
                1.25, CostCalculator::materialCostPerKgSold(1.00, 0.80),
            ],
            'Prezzo netto: listino 1,60 € con sconto 10% = 1,44 €' => [1.44, CostCalculator::netPrice(1.60, 10)],
            'Margine: 1,50 € − 1,25 € materia − 0,20 € lavorazione = 0,05 €/kg' => [
                0.05, CostCalculator::marginPerKg(1.50, 1.25, 0.20),
            ],
            'Prezzo di listino minimo: 1,20 € netti con sconto 10% = 1,3333 €' => [
                1.3333, round((float) CostCalculator::minimumListPrice(1.20, 10), 4),
            ],
            'Foglio: kg entrati = somma dei lotti (8.000 kg)' => [8000.0, $sheet['kg_in']],
            'Foglio: resa = 6.800 ÷ 8.000 = 85%' => [0.85, $sheet['yield']],
            'Foglio: il costo €/kg è (manodopera + imballi) ÷ kg confezionati' => [
                ($sheet['labour_cost'] + $sheet['packaging_cost']) / 6800, $sheet['cost_per_kg'],
            ],
            'Ore sul foglio: chi esce alle 12:30 conta 1,5 ore su un foglio 11:00–14:00' => [
                1.5, WorkSheetCosting::hoursOnSheet($at('11:00'), $at('14:00'), $at('07:00'), $at('12:30')),
            ],
            'Durata blocco: 6 pedane a 6 pedane/ora + 15 minuti di avvio = fine alle 08:45' => [
                '08:45', $single[0]['end']->format('H:i'),
            ],
            'Cambio varietà: 25 minuti' => [25, $planner->changeover($abateCarton, $apple)['minutes']],
            'Cambio formato: 15 minuti' => [15, $planner->changeover($abateCarton, $abateCrate)['minutes']],
            'Cambio imballo: 8 minuti' => [8, $planner->changeover($abateCarton, $abateNeutral)['minutes']],
            'Cambio di solo calibro: nessun fermo' => [0, $planner->changeover($abateCarton, $abateBigger)['minutes']],
            'Più cambi insieme: conta il più pesante (varietà)' => [
                'variety', $planner->changeover($abateCrate, $apple)['type'],
            ],
            'Camion: in sequenza i due ordini delle 12:00 chiudono in tempo' => [
                false, $good[0]['late'] || $good[1]['late'],
            ],
            'Camion: dopo un cambio in più il blocco finisce oltre le 12:00' => [true, $bad[2]['late']],
            // Order 1 is first in $before and second in $after; order 2 the other way round.
            'Spostare un ordine ricalcola le ore di fine della linea' => [
                true, $before[0]['end']->format('H:i') !== $after[1]['end']->format('H:i')
                    && $before[1]['end']->format('H:i') !== $after[0]['end']->format('H:i'),
            ],
            'Un ordine arrivato alle 10:00 non parte prima delle 10:00' => ['10:00', $waiting[0]['start']->format('H:i')],
            'Proponi sequenza: articoli uguali vicini' => [true, in_array($sequence, ['AADD', 'DDAA'], true)],
            'Proponi sequenza: nessun blocco oltre il camion' => [
                0, count(array_filter($proposed, fn ($b) => $b['late'])),
            ],
        ];
    }

    private static function show(mixed $value): string
    {
        return match (true) {
            $value === null => 'nessun valore',
            is_bool($value) => $value ? 'sì' : 'no',
            is_float($value) => rtrim(rtrim(number_format($value, 4, ',', '.'), '0'), ','),
            default => (string) $value,
        };
    }
}
