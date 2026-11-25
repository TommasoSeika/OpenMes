<?php

namespace Modules\Ortofrutta\Domain;

/**
 * The forklift work lists: in which order the missions are done, who gets
 * which, and what a scan means for a mission.
 *
 * Pure functions over plain arrays, so the rules can be tested without a
 * database. Times are minutes from midnight.
 *
 * A "mission" is ['id' => 5, 'due' => 430, 'priority' => 1, 'line' => 'L1',
 * 'bins' => 6, 'driver' => null]; a "driver" is ['id' => 70, 'badge' => 'M070',
 * 'until' => null] (`until` is a time only for someone who leaves early).
 *
 * Scanned codes say what they are: "LOT:LT26-034" is the label on the bins of
 * a lot, "LINE:L1" the code at the infeed of a line, "CELL:C09" a cell door,
 * "BADGE:M070" a person.
 */
final class MissionPlanner
{
    public const KINDS = ['LOT' => 'lot', 'LINE' => 'line', 'CELL' => 'cell', 'BADGE' => 'badge'];

    /**
     * The order of work: what a line needs first comes first; among missions
     * due together, the order the office gave the highest priority; then by
     * line, so two missions for the same line stay together.
     */
    public static function order(array $missions): array
    {
        usort($missions, fn (array $a, array $b) => [$a['due'], $a['priority'] ?? 2, (string) ($a['line'] ?? ''), $a['id']]
            <=> [$b['due'], $b['priority'] ?? 2, (string) ($b['line'] ?? ''), $b['id']]);

        return $missions;
    }

    /**
     * Share the missions among the drivers who are in.
     *
     * A mission already given to a driver who is in stays with them. The
     * others go, in order of work, to whoever has the fewest bins to move so
     * far, so the lists come out even. Nobody gets a mission due after they
     * have left.
     *
     * @param  list<array>  $missions
     * @param  list<array>  $drivers
     * @return array<int|string, int|string|null> mission id => driver id, null when nobody can take it
     */
    public static function assign(array $missions, array $drivers): array
    {
        $load = [];
        foreach ($drivers as $driver) {
            $load[$driver['id']] = [0, 0];
        }

        $given = [];
        foreach ($missions as $mission) {
            $driver = $mission['driver'] ?? null;
            if ($driver !== null && isset($load[$driver])) {
                $given[$mission['id']] = $driver;
                $load[$driver][0] += (int) $mission['bins'];
                $load[$driver][1]++;
            }
        }

        foreach (self::order($missions) as $mission) {
            if (isset($given[$mission['id']])) {
                continue;
            }
            $best = null;
            foreach ($drivers as $driver) {
                if (($driver['until'] ?? null) !== null && $driver['until'] <= $mission['due']) {
                    continue;
                }
                $key = [$load[$driver['id']][0], $load[$driver['id']][1], (string) ($driver['badge'] ?? $driver['id'])];
                if ($best === null || $key < $best[1]) {
                    $best = [$driver['id'], $key];
                }
            }
            $given[$mission['id']] = $best[0] ?? null;
            if ($best !== null) {
                $load[$best[0]][0] += (int) $mission['bins'];
                $load[$best[0]][1]++;
            }
        }

        return $given;
    }

    /**
     * What a scanned code is.
     *
     * @return array{kind: string, value: string}|null null when the text is not one of the plant's codes
     */
    public static function parse(string $code): ?array
    {
        if (preg_match('/^\s*([A-Za-z]+)\s*:\s*([A-Za-z0-9._-]{1,40})\s*$/', $code, $m) !== 1) {
            return null;
        }
        $kind = self::KINDS[strtoupper($m[1])] ?? null;

        return $kind === null ? null : ['kind' => $kind, 'value' => strtoupper($m[2])];
    }

    /**
     * What a scan does to a mission.
     *
     * A mission to do wants the label of its bins: reading it means the bins
     * are on the forks. A mission picked wants the code of its line: reading
     * it means the bins are at the infeed. Anything else is refused, which is
     * the point: the wrong lot never reaches a line unnoticed.
     *
     * @param  array{status: string, lot: string, line: string}  $mission
     * @return array{ok: bool, step: ?string, problem: ?string, read: ?array}
     *                                                                        step is pick | deliver; problem is unreadable | done | not_a_bin | wrong_lot | not_a_line | wrong_line
     */
    public static function scan(array $mission, string $code): array
    {
        $read = self::parse($code);
        $refuse = fn (?string $step, string $problem) => ['ok' => false, 'step' => $step, 'problem' => $problem, 'read' => $read];

        if ($mission['status'] === 'delivered') {
            return $refuse(null, 'done');
        }
        $step = $mission['status'] === 'picked' ? 'deliver' : 'pick';
        if ($read === null) {
            return $refuse($step, 'unreadable');
        }

        if ($step === 'pick') {
            if ($read['kind'] !== 'lot') {
                return $refuse($step, 'not_a_bin');
            }
            if ($read['value'] !== strtoupper($mission['lot'])) {
                return $refuse($step, 'wrong_lot');
            }
        } else {
            if ($read['kind'] !== 'line') {
                return $refuse($step, 'not_a_line');
            }
            if ($read['value'] !== strtoupper($mission['line'])) {
                return $refuse($step, 'wrong_line');
            }
        }

        return ['ok' => true, 'step' => $step, 'problem' => null, 'read' => $read];
    }
}
