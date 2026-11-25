<?php

namespace Tests\Unit\Ortofrutta;

use Modules\Ortofrutta\Domain\MissionPlanner;
use PHPUnit\Framework\TestCase;

/**
 * The forklift work lists and the scans that carry them out. Pure unit tests:
 * no database, no application boot. The worked examples of SelfCheck are run
 * by CalculationRulesTest; these are the properties that must hold whatever
 * the day looks like.
 */
class MissionRulesTest extends TestCase
{
    /** Forty missions over a morning, for four lines. */
    private function missions(): array
    {
        $missions = [];
        for ($i = 1; $i <= 40; $i++) {
            $missions[] = [
                'id' => $i, 'due' => 430 + ($i * 37) % 300, 'priority' => 1 + $i % 3,
                'line' => 'L'.[1, 2, 4, 6][$i % 4], 'bins' => 2 + ($i * 7) % 9, 'driver' => null,
            ];
        }

        return $missions;
    }

    private function drivers(int $count): array
    {
        return array_map(fn (int $i) => ['id' => 60 + $i, 'badge' => 'M0'.(60 + $i), 'until' => null], range(1, $count));
    }

    public function test_every_mission_gets_a_driver_and_the_lists_come_out_even(): void
    {
        $missions = $this->missions();
        $given = MissionPlanner::assign($missions, $this->drivers(6));

        $this->assertCount(40, $given);
        $this->assertNotContains(null, $given);

        $bins = [];
        foreach ($missions as $mission) {
            $bins[$given[$mission['id']]] = ($bins[$given[$mission['id']]] ?? 0) + $mission['bins'];
        }
        $this->assertCount(6, $bins);
        // Each mission goes to whoever has least: two drivers never differ by more than one mission's bins.
        $this->assertLessThanOrEqual(max(array_column($missions, 'bins')), max($bins) - min($bins));
    }

    public function test_a_mission_already_given_stays_with_its_driver(): void
    {
        $missions = $this->missions();
        $missions[0]['driver'] = 66;
        $missions[1]['driver'] = 66;
        // Given to somebody who is not in today: it is shared out again.
        $missions[2]['driver'] = 99;

        $given = MissionPlanner::assign($missions, $this->drivers(6));

        $this->assertSame(66, $given[1]);
        $this->assertSame(66, $given[2]);
        $this->assertNotSame(99, $given[3]);
        $this->assertNotNull($given[3]);
    }

    public function test_with_nobody_in_the_missions_stay_without_a_driver(): void
    {
        $given = MissionPlanner::assign($this->missions(), []);

        $this->assertSame([null], array_values(array_unique($given)));
    }

    public function test_the_order_of_work_is_by_time_then_priority(): void
    {
        $ordered = MissionPlanner::order($this->missions());

        for ($i = 1; $i < count($ordered); $i++) {
            $this->assertLessThanOrEqual(
                [$ordered[$i]['due'], $ordered[$i]['priority']],
                [$ordered[$i - 1]['due'], $ordered[$i - 1]['priority']],
            );
        }
    }

    public function test_a_scan_only_ever_moves_a_mission_one_step_forward(): void
    {
        $mission = ['status' => 'todo', 'lot' => 'LT26-034', 'line' => 'L4'];

        // The line's code does nothing until the bins have been read.
        $this->assertFalse(MissionPlanner::scan($mission, 'LINE:L4')['ok']);
        $pick = MissionPlanner::scan($mission, 'LOT:LT26-034');
        $this->assertTrue($pick['ok']);
        $this->assertSame('pick', $pick['step']);

        $mission['status'] = 'picked';
        // Reading the bins again does not deliver them.
        $this->assertSame('not_a_line', MissionPlanner::scan($mission, 'LOT:LT26-034')['problem']);
        $deliver = MissionPlanner::scan($mission, 'LINE:L4');
        $this->assertTrue($deliver['ok']);
        $this->assertSame('deliver', $deliver['step']);

        $mission['status'] = 'delivered';
        $this->assertSame('done', MissionPlanner::scan($mission, 'LINE:L4')['problem']);
    }

    public function test_codes_say_what_they_are(): void
    {
        $this->assertSame(['kind' => 'lot', 'value' => 'LT26-034'], MissionPlanner::parse(' lot:lt26-034 '));
        $this->assertSame(['kind' => 'line', 'value' => 'L1'], MissionPlanner::parse('LINE:L1'));
        $this->assertSame(['kind' => 'cell', 'value' => 'C09'], MissionPlanner::parse('CELL:C09'));
        $this->assertNull(MissionPlanner::parse('LT26-034'));
        $this->assertNull(MissionPlanner::parse('PALLET:123'));
        $this->assertNull(MissionPlanner::parse(''));
    }
}
