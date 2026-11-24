<?php

namespace Tests\Unit\Ortofrutta;

use DateTimeImmutable;
use Modules\Ortofrutta\Domain\CostCalculator;
use Modules\Ortofrutta\Domain\LinePlanner;
use Modules\Ortofrutta\Domain\SelfCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The calculation rules of the Ortofrutta module. Pure unit tests: no
 * database, no application boot, so the module does not need to be enabled.
 */
class CalculationRulesTest extends TestCase
{
    /** Every worked example of SelfCheck, one test each. */
    public static function workedExamples(): array
    {
        $cases = [];
        foreach (SelfCheck::cases() as $label => [$expected, $actual]) {
            $cases[$label] = [$expected, $actual];
        }

        return $cases;
    }

    #[DataProvider('workedExamples')]
    public function test_worked_example(mixed $expected, mixed $actual): void
    {
        if (is_float($expected) || is_float($actual)) {
            $this->assertEqualsWithDelta((float) $expected, (float) $actual, 0.00005);

            return;
        }

        $this->assertSame($expected, $actual);
    }

    /** The example from the meeting: buy at 1.00, processing costs 0.20, sell above 1.20. */
    public function test_buying_at_one_euro_and_processing_at_twenty_cents_gives_a_minimum_price_of_one_twenty(): void
    {
        $material = CostCalculator::materialCostPerKgSold(1.00, 1.0);

        $this->assertEqualsWithDelta(1.20, CostCalculator::minimumPrice($material, 0.20), 0.00001);
    }

    public function test_a_lower_yield_raises_the_minimum_price(): void
    {
        $good = CostCalculator::lotEconomics(1.00, 1000, 900, 0.20, 1.50);
        $poor = CostCalculator::lotEconomics(1.00, 1000, 700, 0.20, 1.50);

        $this->assertGreaterThan($good['minimum'], $poor['minimum']);
        $this->assertGreaterThan(0, $good['margin']);
        $this->assertLessThan(0, $poor['margin']);
    }

    public function test_a_line_that_cannot_run_a_format_is_never_proposed_for_it(): void
    {
        $day = new DateTimeImmutable('2026-11-27 07:30');
        $planner = new LinePlanner(['variety' => 25, 'format' => 15, 'packaging' => 8]);
        $lines = [
            'L1' => ['startup_minutes' => 15, 'capacities' => ['Cartone' => 6.0]],
            'L4' => ['startup_minutes' => 20, 'capacities' => ['Cestino' => 2.5]],
        ];
        $order = fn (int $id, string $format) => [
            'id' => $id, 'pallets' => 2, 'priority' => 1, 'arrived_at' => null, 'truck_at' => null,
            'item' => ['code' => 'I'.$id, 'variety' => 'Abate', 'format' => $format, 'packaging' => 'X'],
        ];

        $proposal = $planner->propose($lines, [$order(1, 'Cestino'), $order(2, 'Cartone'), $order(3, 'Vassoio')], $day);

        $this->assertSame([2], array_column($proposal['lines']['L1'], 'id'));
        $this->assertSame([1], array_column($proposal['lines']['L4'], 'id'));
        $this->assertSame([3], array_column($proposal['unplaced'], 'id'));
    }

    public function test_launched_blocks_stay_first_when_a_sequence_is_proposed(): void
    {
        $day = new DateTimeImmutable('2026-11-27 07:30');
        $planner = new LinePlanner(['variety' => 25, 'format' => 15, 'packaging' => 8]);
        $line = ['startup_minutes' => 15, 'capacities' => ['Cartone' => 6.0]];
        $order = fn (int $id, string $code, ?string $truck) => [
            'id' => $id, 'pallets' => 2, 'priority' => 1, 'arrived_at' => null,
            'truck_at' => $truck ? new DateTimeImmutable('2026-11-27 '.$truck) : null,
            'item' => ['code' => $code, 'variety' => $code, 'format' => 'Cartone', 'packaging' => 'X'],
        ];

        // Order 9 has the latest truck but is already running: it must not be moved behind the others.
        $proposal = $planner->propose(
            ['L1' => $line],
            [$order(1, 'A', '12:00'), $order(2, 'B', '15:00')],
            $day,
            ['L1' => [$order(9, 'Z', '19:30')]],
        );

        $this->assertSame([9, 1, 2], array_column($proposal['lines']['L1'], 'id'));
    }
}
