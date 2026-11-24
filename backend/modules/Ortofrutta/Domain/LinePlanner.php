<?php

namespace Modules\Ortofrutta\Domain;

use DateTimeImmutable;

/**
 * The line plan: how long a block takes, when it ends, whether it makes its
 * truck, and a proposed sequence.
 *
 * Pure functions over plain arrays, so the rules can be tested without a
 * database. A "block" is one order on a line:
 *
 *   ['id' => 12, 'item' => ['code','variety','format','packaging'], 'pallets' => 6,
 *    'arrived_at' => ?DateTimeImmutable, 'truck_at' => ?DateTimeImmutable, 'priority' => 1]
 *
 * A "line" is ['startup_minutes' => 15, 'capacities' => ['Cartone 40x60' => 6.0, ...]].
 *
 * Duration of a block = pallets ÷ line capacity for the format
 *                     + start-up (first block of the day) or changeover from the block before.
 */
final class LinePlanner
{
    /** Changeover types, heaviest first: when several apply, the first one counts. */
    public const TYPES = ['variety', 'format', 'packaging'];

    /** Weight of one minute past the truck, against one minute of anything else. */
    private const LATE_WEIGHT = 1000;

    /** Extra weight of a minute of stopped line, so a tie goes to fewer changeovers. */
    private const SETUP_WEIGHT = 3;

    /**
     * @param  array<string, int>  $standard  minutes of stop per changeover type
     * @param  array<string, int>  $specific  minutes for one item pair, keyed "FROM>TO"
     */
    public function __construct(private array $standard = [], private array $specific = []) {}

    /**
     * The stop between two consecutive items.
     *
     * @return array{type: ?string, minutes: int}
     */
    public function changeover(?array $from, array $to): array
    {
        if ($from === null || $from['code'] === $to['code']) {
            return ['type' => null, 'minutes' => 0];
        }

        foreach (self::TYPES as $type) {
            if (($from[$type] ?? null) !== ($to[$type] ?? null)) {
                $minutes = $this->specific[$from['code'].'>'.$to['code']] ?? ($this->standard[$type] ?? 0);

                return ['type' => $type, 'minutes' => (int) $minutes];
            }
        }

        // Same variety, format and packaging (only the size differs): no stop.
        return ['type' => null, 'minutes' => 0];
    }

    /**
     * Start, end and warnings of every block of a line, in the given order.
     *
     * @return list<array<string, mixed>> the blocks, each with setup_type, setup_minutes,
     *                                    run_minutes, wait_minutes, start, end, late, late_minutes
     */
    public function schedule(array $line, array $blocks, DateTimeImmutable $dayStart): array
    {
        $timed = $this->timeline($line, $blocks, $dayStart);

        $out = [];
        foreach ($blocks as $i => $block) {
            $t = $timed[$i];
            $out[] = $block + [
                'setup_type' => $t['setup_type'],
                'setup_minutes' => $t['setup'],
                'run_minutes' => $t['run'],
                'wait_minutes' => $t['wait'],
                'start' => $dayStart->modify(sprintf('+%d minutes', $t['start'])),
                'end' => $dayStart->modify(sprintf('+%d minutes', $t['end'])),
                'late' => $t['late'] > 0,
                'late_minutes' => $t['late'],
            ];
        }

        return $out;
    }

    /**
     * Propose a sequence for a day.
     *
     * Orders are taken by truck time, then by the office's priority, and each
     * one is inserted where it costs least: no block past its truck first,
     * then finishing early (high-priority orders count more), then fewer
     * changeovers. On every line the blocks stay in truck order; within the
     * same truck the cheapest position wins, which puts equal items next to
     * each other and never makes a line wait for an order that could run later.
     *
     * @param  array<string, array>  $lines  line definitions keyed by code
     * @param  list<array>  $orders  blocks to place
     * @param  array<string, list<array>>  $locked  blocks already launched, per line: they stay first
     * @return array{lines: array<string, list<array>>, unplaced: list<array>}
     */
    public function propose(array $lines, array $orders, DateTimeImmutable $dayStart, array $locked = []): array
    {
        $sequences = [];
        $lockedCount = [];
        foreach ($lines as $code => $line) {
            $sequences[$code] = array_values($locked[$code] ?? []);
            $lockedCount[$code] = count($sequences[$code]);
        }

        usort($orders, fn (array $a, array $b) => $this->sortKey($a, $dayStart) <=> $this->sortKey($b, $dayStart));

        $unplaced = [];
        foreach ($orders as $order) {
            $best = null;
            foreach ($lines as $code => $line) {
                if (! isset($line['capacities'][$order['item']['format']])) {
                    continue;
                }
                $sequence = $sequences[$code];
                $before = $this->cost($line, $sequence, $dayStart);
                $key = $this->bucket($order, $dayStart);
                $count = count($sequence);
                for ($p = $lockedCount[$code]; $p <= $count; $p++) {
                    $fitsAfter = $p === $lockedCount[$code] || $this->bucket($sequence[$p - 1], $dayStart) <= $key;
                    $fitsBefore = $p === $count || $this->bucket($sequence[$p], $dayStart) >= $key;
                    if (! $fitsAfter || ! $fitsBefore) {
                        continue;
                    }
                    $candidate = $sequence;
                    array_splice($candidate, $p, 0, [$order]);
                    $delta = $this->cost($line, $candidate, $dayStart) - $before;
                    if ($best === null || $delta < $best['delta']) {
                        $best = ['delta' => $delta, 'line' => $code, 'sequence' => $candidate];
                    }
                }
            }
            if ($best === null) {
                $unplaced[] = $order;

                continue;
            }
            $sequences[$best['line']] = $best['sequence'];
        }

        return ['lines' => $sequences, 'unplaced' => $unplaced];
    }

    /**
     * Minutes from the start of the day for every block: set-up, run, wait, start, end, lateness.
     *
     * @return list<array{setup_type: ?string, setup: int, run: int, wait: int, start: int, end: int, late: int}>
     */
    private function timeline(array $line, array $blocks, DateTimeImmutable $dayStart): array
    {
        $out = [];
        $cursor = 0;
        $previous = null;
        foreach ($blocks as $block) {
            if ($previous === null) {
                $setupType = 'startup';
                $setup = (int) ($line['startup_minutes'] ?? 0);
            } else {
                $change = $this->changeover($previous, $block['item']);
                $setupType = $change['type'];
                $setup = $change['minutes'];
            }

            $capacity = (float) ($line['capacities'][$block['item']['format']] ?? 0);
            $run = $capacity > 0 ? (int) ceil($block['pallets'] / $capacity * 60) : 0;

            // A block cannot start before its order has arrived.
            $arrived = max(0, $this->minutes($block['arrived_at'] ?? null, $dayStart) ?? 0);
            $start = max($cursor, $arrived);
            $end = $start + $setup + $run;
            $truck = $this->minutes($block['truck_at'] ?? null, $dayStart);

            $out[] = [
                'setup_type' => $setupType,
                'setup' => $setup,
                'run' => $run,
                'wait' => $start - $cursor,
                'start' => $start,
                'end' => $end,
                'late' => $truck !== null && $end > $truck ? $end - $truck : 0,
            ];

            $cursor = $end;
            $previous = $block['item'];
        }

        return $out;
    }

    /**
     * What a sequence costs: lateness first, then the sum of end times weighted
     * by priority (1 counts three times, 3 once), then stops.
     */
    private function cost(array $line, array $blocks, DateTimeImmutable $dayStart): int
    {
        $cost = 0;
        foreach ($this->timeline($line, $blocks, $dayStart) as $i => $t) {
            $priority = max(1, min(3, (int) ($blocks[$i]['priority'] ?? 2)));
            $cost += self::LATE_WEIGHT * $t['late'] + (4 - $priority) * $t['end'];
            if ($i > 0) {
                $cost += self::SETUP_WEIGHT * $t['setup'];
            }
        }

        return $cost;
    }

    /** Truck time in minutes; an order with no truck goes last. Lines stay in this order. */
    private function bucket(array $block, DateTimeImmutable $dayStart): int
    {
        return $this->minutes($block['truck_at'] ?? null, $dayStart) ?? PHP_INT_MAX;
    }

    /**
     * The order in which blocks are placed: truck, priority, arrival.
     *
     * @return array{0: int, 1: int, 2: int, 3: string}
     */
    private function sortKey(array $block, DateTimeImmutable $dayStart): array
    {
        return [
            $this->bucket($block, $dayStart),
            (int) ($block['priority'] ?? 2),
            $this->minutes($block['arrived_at'] ?? null, $dayStart) ?? 0,
            (string) $block['id'],
        ];
    }

    private function minutes(?DateTimeImmutable $moment, DateTimeImmutable $dayStart): ?int
    {
        if ($moment === null) {
            return null;
        }

        return (int) floor(($moment->getTimestamp() - $dayStart->getTimestamp()) / 60);
    }
}
