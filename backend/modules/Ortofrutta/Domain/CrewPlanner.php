<?php

namespace Modules\Ortofrutta\Domain;

/**
 * Who can work on which production, and a proposed crew for each one.
 *
 * Pure functions over plain arrays, so the rules can be tested without a
 * database. Times are minutes from midnight.
 *
 * A "production" is one run of a line (the consecutive orders of the same
 * item, one work sheet):
 *
 *   ['id' => 12, 'line' => 'L1', 'start' => 450, 'end' => 525, 'required' => 22,
 *    'crew' => [7, 9], 'fixed' => false]
 *
 * A "person" is someone who is in today (people absent for the day are simply
 * not in the list):
 *
 *   ['id' => 7, 'lines' => ['L1', 'L2'], 'from' => 420, 'until' => 750,
 *    'leader' => false, 'badge' => 'M007']
 *
 * `until` is null for someone who stays for the whole shift, however late the
 * lines finish; it is a time only for someone who leaves early.
 */
final class CrewPlanner
{
    /** Two productions that are running at the same moment. */
    public static function overlaps(array $a, array $b): bool
    {
        return $a['start'] < $b['end'] && $b['start'] < $a['end'];
    }

    /** In for at least part of the production. */
    public static function attends(array $person, array $production): bool
    {
        $from = $person['from'] ?? 0;
        $until = $person['until'] ?? PHP_INT_MAX;

        return $from < $production['end'] && $until > $production['start'];
    }

    /** In from the start of the production to its end. */
    public static function covers(array $person, array $production): bool
    {
        $from = $person['from'] ?? 0;
        $until = $person['until'] ?? PHP_INT_MAX;

        return $from <= $production['start'] && $until >= $production['end'];
    }

    /**
     * Why a person cannot join a production, or null when they can.
     *
     * @param  list<array>  $productions  every production of the day, with its crew
     * @return array{reason: string, with?: int|string}|null reason is line | away | busy;
     *                                                       `with` is the production they are already on
     */
    public static function refusal(array $person, array $production, array $productions): ?array
    {
        if (! in_array($production['line'], $person['lines'] ?? [], true)) {
            return ['reason' => 'line'];
        }
        if (! self::attends($person, $production)) {
            return ['reason' => 'away'];
        }
        foreach ($productions as $other) {
            if ($other['id'] !== $production['id']
                && in_array($person['id'], $other['crew'] ?? [], true)
                && self::overlaps($other, $production)) {
                return ['reason' => 'busy', 'with' => $other['id']];
            }
        }

        return null;
    }

    /**
     * What is wrong with the crews as they are: people booked on two
     * productions at once, people who leave before the end, people who are
     * not in at all.
     *
     * @param  list<array>  $productions
     * @param  list<array>  $people
     * @return array<int|string, array<int, array{issue: string, with?: int|string}>> production id => person id => issue
     */
    public static function issues(array $productions, array $people): array
    {
        $people = array_column($people, null, 'id');
        $issues = [];
        foreach ($productions as $production) {
            foreach ($production['crew'] ?? [] as $personId) {
                $person = $people[$personId] ?? null;
                if ($person === null || ! self::attends($person, $production)) {
                    $issues[$production['id']][$personId] = ['issue' => 'away'];

                    continue;
                }
                foreach ($productions as $other) {
                    if ($other['id'] !== $production['id']
                        && in_array($personId, $other['crew'] ?? [], true)
                        && self::overlaps($other, $production)) {
                        $issues[$production['id']][$personId] = ['issue' => 'busy', 'with' => $other['id']];

                        continue 2;
                    }
                }
                if (! self::covers($person, $production)) {
                    $issues[$production['id']][$personId] = ['issue' => 'partial'];
                }
            }
        }

        return $issues;
    }

    /**
     * Fill every production up to its requirement, earliest first.
     *
     * People already on a production stay there. The others are chosen so
     * that a line keeps the same faces from one production to the next: first
     * who was on the production before on that line, then who has already
     * worked on that line today, then who can work on the fewest lines (so the
     * flexible ones stay free for the lines that need them). A crew gets a
     * line leader first when it has none. Nobody is put on a production they
     * would have to leave before the end, or on two productions at once.
     *
     * @param  list<array>  $productions
     * @param  list<array>  $people
     * @return array{crews: array<int|string, list<int>>, missing: array<int|string, int>}
     */
    public static function propose(array $productions, array $people): array
    {
        $people = array_column($people, null, 'id');

        $eligible = [];
        foreach ($productions as $production) {
            $eligible[$production['line']] ??= count(array_filter(
                $people, fn (array $p) => in_array($production['line'], $p['lines'] ?? [], true),
            ));
        }
        // Earliest first; among productions starting together, the line with the fewest possible people.
        usort($productions, fn (array $a, array $b) => [$a['start'], $eligible[$a['line']], $a['end'], (string) $a['id']]
            <=> [$b['start'], $eligible[$b['line']], $b['end'], (string) $b['id']]);

        $crews = [];
        $busy = [];
        foreach ($productions as $production) {
            $crews[$production['id']] = array_values(array_unique($production['crew'] ?? []));
            foreach ($crews[$production['id']] as $personId) {
                $busy[$personId][] = $production;
            }
        }

        $previousOnLine = [];
        $workedOn = [];
        $missing = [];
        foreach ($productions as $production) {
            $id = $production['id'];
            $line = $production['line'];

            if (! ($production['fixed'] ?? false)) {
                $before = $previousOnLine[$line] ?? [];
                $candidates = [];
                foreach ($people as $personId => $person) {
                    if (in_array($personId, $crews[$id], true)
                        || ! in_array($line, $person['lines'] ?? [], true)
                        || ! self::covers($person, $production)) {
                        continue;
                    }
                    foreach ($busy[$personId] ?? [] as $other) {
                        if (self::overlaps($other, $production)) {
                            continue 2;
                        }
                    }
                    $candidates[] = $person + ['rank' => [
                        in_array($personId, $before, true) ? 0 : 1,
                        isset($workedOn[$personId][$line]) ? 0 : 1,
                        count($person['lines'] ?? []),
                        (string) ($person['badge'] ?? $personId),
                    ]];
                }
                usort($candidates, fn (array $a, array $b) => $a['rank'] <=> $b['rank']);

                $hasLeader = false;
                foreach ($crews[$id] as $personId) {
                    $hasLeader = $hasLeader || ($people[$personId]['leader'] ?? false);
                }

                $take = function (array $person) use (&$crews, &$busy, $production, $id) {
                    $crews[$id][] = $person['id'];
                    $busy[$person['id']][] = $production;
                };

                // A line leader first, then everyone else; spare leaders only when nobody else is left.
                $rest = [];
                foreach ($candidates as $person) {
                    if (! $hasLeader && ($person['leader'] ?? false) && count($crews[$id]) < $production['required']) {
                        $take($person);
                        $hasLeader = true;
                    } else {
                        $rest[] = $person;
                    }
                }
                usort($rest, fn (array $a, array $b) => [(int) ($a['leader'] ?? false), $a['rank']] <=> [(int) ($b['leader'] ?? false), $b['rank']]);
                foreach ($rest as $person) {
                    if (count($crews[$id]) >= $production['required']) {
                        break;
                    }
                    $take($person);
                }
            }

            foreach ($crews[$id] as $personId) {
                $workedOn[$personId][$line] = true;
            }
            $previousOnLine[$line] = $crews[$id];
            $missing[$id] = max(0, (int) $production['required'] - count($crews[$id]));
        }

        return ['crews' => $crews, 'missing' => $missing];
    }
}
