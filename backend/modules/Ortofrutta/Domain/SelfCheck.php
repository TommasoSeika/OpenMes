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

        // Crews per production. Minutes from midnight: 450 = 07:30, 600 = 10:00, 750 = 12:30, 780 = 13:00.
        $person = fn (int $id, array $lines, ?int $until = null, bool $leader = false) => [
            'id' => $id, 'lines' => $lines, 'from' => 420, 'until' => $until, 'leader' => $leader, 'badge' => 'M00'.$id,
        ];
        $staff = [
            $person(1, ['L1'], null, true),      // line leader of L1
            $person(2, ['L1', 'L2']),
            $person(3, ['L1', 'L2'], 750),       // leaves at 12:30
            $person(4, ['L2']),
        ];
        $run = fn (int $id, string $line, int $start, int $end, int $required, array $crew = []) => [
            'id' => $id, 'line' => $line, 'start' => $start, 'end' => $end, 'required' => $required, 'crew' => $crew,
        ];
        $morning = $run(10, 'L1', 450, 600, 3);  // L1 07:30–10:00, three people
        $noon = $run(11, 'L1', 600, 780, 3);     // L1 10:00–13:00, three people
        $other = $run(20, 'L2', 480, 660, 2);    // L2 08:00–11:00, two people: overlaps both
        $crews = CrewPlanner::propose([$morning, $noon, $other], $staff);
        $booked = [$run(10, 'L1', 450, 600, 3, [1, 2, 3]), $noon, $other];

        // Traceability: a sheet packs 3,000 kg of lot A and 1,000 kg of lot B for two orders, 6 and 2 pallets.
        $shares = Traceability::allocate(['A' => 3000, 'B' => 1000], ['first' => 6, 'second' => 2]);

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
            'Squadre: la prima persona proposta su una produzione è un capolinea' => [1, $crews['crews'][10][0]],
            'Squadre: una produzione riceve le persone che richiede' => [3, count($crews['crews'][10])],
            'Squadre: nessuno è proposto su due produzioni nello stesso orario' => [[4], $crews['crews'][20]],
            'Squadre: se i presenti non bastano, la produzione resta scoperta di chi manca' => [1, $crews['missing'][20]],
            'Squadre: la linea tiene le stesse persone dalla produzione prima a quella dopo' => [[1, 2], $crews['crews'][11]],
            'Squadre: chi esce alle 12:30 non è proposto per una produzione che finisce alle 13:00' => [
                false, in_array(3, $crews['crews'][11], true),
            ],
            'Squadre: chi non è abilitato sulla linea non si può assegnare' => [
                'line', CrewPlanner::refusal($staff[3], $morning, $booked)['reason'] ?? null,
            ],
            'Squadre: chi è già su un\'altra produzione in quell\'orario non si può assegnare' => [
                'busy', CrewPlanner::refusal($staff[1], $other, $booked)['reason'] ?? null,
            ],
            'Squadre: chi è uscito prima che la produzione cominci non si può assegnare' => [
                'away', CrewPlanner::refusal($staff[2], $run(12, 'L1', 780, 840, 3), $booked)['reason'] ?? null,
            ],
            'Squadre: chi esce durante la produzione si può assegnare, ma è segnalato' => [
                'partial', CrewPlanner::issues([$run(11, 'L1', 600, 780, 3, [3])], $staff)[11][3]['issue'] ?? null,
            ],
            'Lotto di confezionamento: foglio 1702 del 24 novembre 2026 = L26328-1702' => [
                'L26328-1702', Traceability::packingLot(new DateTimeImmutable('2026-11-24'), 1702),
            ],
            'Lotto di confezionamento: dal codice sulla pedana si risale al foglio' => [
                1702, Traceability::sheetNumber('L26328-1702'),
            ],
            'Tracciabilità: all\'ordine con 6 pedane su 8 vanno i tre quarti di ogni lotto' => [
                2250.0, $shares['first']['A'],
            ],
            'Tracciabilità: i kg ripartiti tra gli ordini tornano al totale del lotto' => [
                1000.0, $shares['first']['B'] + $shares['second']['B'],
            ],
        ];
    }

    private static function show(mixed $value): string
    {
        return match (true) {
            $value === null => 'nessun valore',
            is_bool($value) => $value ? 'sì' : 'no',
            is_array($value) => '['.implode(', ', $value).']',
            is_float($value) => rtrim(rtrim(number_format($value, 4, ',', '.'), '0'), ','),
            default => (string) $value,
        };
    }
}
