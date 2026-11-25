<?php

namespace Tests\Unit\Ortofrutta;

use Modules\Ortofrutta\Domain\CrewPlanner;
use Modules\Ortofrutta\Domain\Traceability;
use PHPUnit\Framework\TestCase;

/**
 * Crews organised per production, and the traceability rules. Pure unit tests:
 * no database, no application boot. The worked examples of SelfCheck are run
 * by CalculationRulesTest; these are the properties that must hold whatever
 * the day looks like.
 */
class CrewAndTraceRulesTest extends TestCase
{
    /** A day with four lines, productions back to back and a mixed workforce. */
    private function day(): array
    {
        $people = [];
        $add = function (array $lines, bool $leader = false, ?int $until = null) use (&$people) {
            $id = count($people) + 1;
            $people[] = ['id' => $id, 'lines' => $lines, 'from' => 420, 'until' => $until, 'leader' => $leader, 'badge' => sprintf('M%03d', $id)];
        };
        foreach (['L1', 'L2', 'L4', 'L6'] as $line) {
            $add([$line], true);
        }
        for ($i = 0; $i < 10; $i++) {
            $add(['L1', 'L2']);
            $add(['L2', 'L6']);
            $add(['L4'], false, $i === 0 ? 750 : null);
        }

        $productions = [];
        foreach (['L1' => 8, 'L2' => 6, 'L4' => 7, 'L6' => 4] as $line => $required) {
            foreach ([[450, 560], [560, 700], [700, 850], [850, 990]] as $i => [$start, $end]) {
                // Lines do not change over at the same minute: shift each one a little.
                $shift = ord($line[1]) % 4 * 10;
                $productions[] = [
                    'id' => $line.'-'.$i, 'line' => $line, 'start' => $start + $shift, 'end' => $end + $shift,
                    'required' => $required, 'crew' => [],
                ];
            }
        }

        return [$productions, $people];
    }

    public function test_nobody_is_ever_proposed_on_two_productions_at_once(): void
    {
        [$productions, $people] = $this->day();
        $crews = CrewPlanner::propose($productions, $people)['crews'];

        $byId = array_column($productions, null, 'id');
        foreach ($people as $person) {
            $mine = array_values(array_filter($byId, fn ($p) => in_array($person['id'], $crews[$p['id']], true)));
            foreach ($mine as $i => $a) {
                foreach (array_slice($mine, $i + 1) as $b) {
                    $this->assertFalse(CrewPlanner::overlaps($a, $b), "Person {$person['id']} is on {$a['id']} and {$b['id']} at once");
                }
            }
        }
    }

    public function test_a_proposed_crew_is_enabled_on_the_line_and_stays_until_the_end(): void
    {
        [$productions, $people] = $this->day();
        $crews = CrewPlanner::propose($productions, $people)['crews'];
        $byId = array_column($people, null, 'id');

        foreach ($productions as $production) {
            $this->assertLessThanOrEqual($production['required'], count($crews[$production['id']]));
            foreach ($crews[$production['id']] as $personId) {
                $this->assertContains($production['line'], $byId[$personId]['lines']);
                $this->assertTrue(CrewPlanner::covers($byId[$personId], $production));
            }
        }
    }

    public function test_a_line_with_enough_people_is_full_and_one_without_is_short_by_what_is_missing(): void
    {
        [$productions, $people] = $this->day();
        $result = CrewPlanner::propose($productions, $people);

        // L1 needs 8: its leader and ten people who can work there.
        $this->assertSame(0, $result['missing']['L1-0']);
        // L4 needs 7 and has a leader and ten specialists, one of whom leaves at 12:30: still full all day.
        $this->assertSame(0, $result['missing']['L4-3']);
        // The person who leaves at 12:30 is never on a production that ends after.
        $early = array_values(array_filter($people, fn ($p) => $p['until'] === 750))[0]['id'];
        foreach ($productions as $production) {
            if ($production['end'] > 750) {
                $this->assertNotContains($early, $result['crews'][$production['id']]);
            }
        }
    }

    public function test_people_already_on_a_production_stay_and_a_closed_one_is_left_alone(): void
    {
        $people = [
            ['id' => 1, 'lines' => ['L1'], 'from' => 420, 'until' => null, 'leader' => true, 'badge' => 'M001'],
            ['id' => 2, 'lines' => ['L1'], 'from' => 420, 'until' => null, 'leader' => false, 'badge' => 'M002'],
            ['id' => 3, 'lines' => ['L1'], 'from' => 420, 'until' => null, 'leader' => false, 'badge' => 'M003'],
        ];
        $productions = [
            // Closed with one person only: it is history, nobody is added.
            ['id' => 'closed', 'line' => 'L1', 'start' => 450, 'end' => 540, 'required' => 3, 'crew' => [2], 'fixed' => true],
            // The shift leader already put person 3 here.
            ['id' => 'open', 'line' => 'L1', 'start' => 540, 'end' => 660, 'required' => 2, 'crew' => [3]],
        ];

        $result = CrewPlanner::propose($productions, $people);

        $this->assertSame([2], $result['crews']['closed']);
        $this->assertSame(2, $result['missing']['closed']);
        $this->assertSame([3, 1], $result['crews']['open']);
        $this->assertSame(0, $result['missing']['open']);
    }

    public function test_the_kilograms_of_every_lot_are_shared_out_exactly(): void
    {
        $packed = ['LT-1' => 4321.5, 'LT-2' => 987.3, 'LT-3' => 15.0];
        $pallets = [101 => 5, 102 => 3, 103 => 1];

        $shares = Traceability::allocate($packed, $pallets);

        foreach ($packed as $lot => $kg) {
            $this->assertEqualsWithDelta($kg, array_sum(array_column($shares, $lot)), 0.0001);
        }
        // Each order gets the same mix of lots as the whole sheet.
        $this->assertEqualsWithDelta(5 / 9, array_sum($shares[101]) / array_sum($packed), 0.0001);
    }

    public function test_a_sheet_without_pallets_shares_out_nothing(): void
    {
        $this->assertSame([101 => ['LT-1' => 0.0]], Traceability::allocate(['LT-1' => 500], [101 => 0]));
        $this->assertSame([], Traceability::allocate(['LT-1' => 500], []));
    }

    public function test_a_packing_lot_is_read_back_to_its_sheet_and_other_text_is_not(): void
    {
        $this->assertSame(1702, Traceability::sheetNumber(' l26328-1702 '));
        $this->assertNull(Traceability::sheetNumber('OV48321'));
        $this->assertNull(Traceability::sheetNumber('LT26-012'));
    }
}
