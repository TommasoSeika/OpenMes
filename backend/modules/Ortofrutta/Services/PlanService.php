<?php

namespace Modules\Ortofrutta\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Ortofrutta\Domain\LinePlanner;
use Modules\Ortofrutta\Models\ChangeoverRule;
use Modules\Ortofrutta\Models\Item;
use Modules\Ortofrutta\Models\Line;
use Modules\Ortofrutta\Models\Order;
use Modules\Ortofrutta\Models\ProductionCrew;
use Modules\Ortofrutta\Models\WorkSheet;
use Modules\Ortofrutta\Models\WorkSheetPerson;
use Modules\Ortofrutta\Support\Demo;

/**
 * The line plan of a day, read from and written to the database. The rules
 * themselves (durations, changeovers, proposed sequence) are in LinePlanner.
 */
class PlanService
{
    private ?LinePlanner $planner = null;

    public function planner(): LinePlanner
    {
        if ($this->planner !== null) {
            return $this->planner;
        }

        $standard = [];
        $specific = [];
        $codes = Item::query()->pluck('code', 'id');
        foreach (ChangeoverRule::query()->get() as $rule) {
            if ($rule->from_item_id && $rule->to_item_id) {
                $specific[$codes[$rule->from_item_id].'>'.$codes[$rule->to_item_id]] = (int) $rule->minutes;
            } else {
                $standard[$rule->type] = (int) $rule->minutes;
            }
        }

        return $this->planner = new LinePlanner($standard, $specific);
    }

    /** @return Collection<int, Line> */
    public function lines(): Collection
    {
        return Line::query()->with('capacities')->orderBy('sort')->orderBy('code')->get();
    }

    /** A line as LinePlanner wants it. */
    public function definition(Line $line): array
    {
        return [
            'startup_minutes' => (int) $line->startup_minutes,
            'capacities' => $line->capacities->mapWithKeys(fn ($c) => [$c->format => (float) $c->pallets_per_hour])->all(),
        ];
    }

    /** An order as LinePlanner wants it. */
    public function block(Order $order): array
    {
        $item = $order->item;

        return [
            'id' => $order->id,
            'item' => [
                'code' => $item->code,
                'variety' => $item->variety,
                'format' => $item->format,
                'packaging' => $item->packaging,
            ],
            'pallets' => (int) $order->pallets,
            'arrived_at' => $order->arrived_at?->toImmutable(),
            'truck_at' => $order->truck_at?->toImmutable(),
            'priority' => (int) $order->priority,
            'order' => $order,
        ];
    }

    /** Released orders of a day, with what the screens need. */
    public function orders(CarbonImmutable $day): Collection
    {
        return Order::query()
            ->with(['item', 'customer', 'workSheet'])
            ->whereDate('day', $day->toDateString())
            ->where('released', true)
            ->orderBy('position')
            ->orderBy('arrived_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Every line of a day with its blocks timed.
     *
     * @return array<int, array{line: Line, blocks: list<array>}> keyed by line id
     */
    public function scheduled(CarbonImmutable $day): array
    {
        $byLine = $this->orders($day)->whereNotNull('line_id')->groupBy('line_id');
        $start = Demo::dayStart($day);

        $out = [];
        foreach ($this->lines() as $line) {
            $blocks = ($byLine[$line->id] ?? collect())->sortBy('position')->values()->map(fn (Order $o) => $this->block($o))->all();
            $out[$line->id] = [
                'line' => $line,
                'blocks' => $this->planner()->schedule($this->definition($line), $blocks, $start),
            ];
        }

        return $out;
    }

    /**
     * The productions of a day: on every line, the consecutive orders of the
     * same item, which run together and become one work sheet. This is what a
     * crew is organised for.
     *
     * A production is known by its first order (`key`). Before the launch its
     * times are those of the plan; after, those written on its work sheet. Its
     * crew is the people booked on its orders, or — once the sheet is closed —
     * the people who are on the sheet, which no longer changes.
     *
     * @param  array<int, array{line: Line, blocks: list<array>}>|null  $scheduled  the result of scheduled(), when already at hand
     * @return list<array{key: int, order_ids: list<int>, orders: list<Order>, line: Line, item: Item, pallets: int, start: CarbonImmutable, end: CarbonImmutable, late: bool, sheet: ?WorkSheet, closed: bool, required: int, crew: list<int>}>
     */
    public function productions(CarbonImmutable $day, ?array $scheduled = null): array
    {
        $productions = [];
        foreach ($scheduled ?? $this->scheduled($day) as $entry) {
            $current = null;
            $previousKey = null;
            foreach ($entry['blocks'] as $b) {
                /** @var Order $order */
                $order = $b['order'];
                $key = $order->work_sheet_id ? 'sheet-'.$order->work_sheet_id : 'item-'.$b['item']['code'];
                if ($key !== $previousKey) {
                    if ($current !== null) {
                        $productions[] = $current;
                    }
                    $previousKey = $key;
                    $current = [
                        'key' => $order->id, 'order_ids' => [], 'orders' => [], 'line' => $entry['line'],
                        'item' => $order->item, 'pallets' => 0, 'start' => CarbonImmutable::instance($b['start']),
                        'end' => CarbonImmutable::instance($b['end']), 'late' => false, 'sheet' => $order->workSheet,
                    ];
                }
                $current['order_ids'][] = $order->id;
                $current['orders'][] = $order;
                $current['pallets'] += (int) $order->pallets;
                $current['end'] = CarbonImmutable::instance($b['end']);
                $current['late'] = $current['late'] || $b['late'];
            }
            if ($current !== null) {
                $productions[] = $current;
            }
        }

        $closedSheets = [];
        $orderIds = [];
        foreach ($productions as $production) {
            if ($production['sheet']?->status === 'closed') {
                $closedSheets[] = $production['sheet']->id;
            } else {
                array_push($orderIds, ...$production['order_ids']);
            }
        }
        $onSheets = $closedSheets === [] ? collect()
            : WorkSheetPerson::query()->whereIn('work_sheet_id', $closedSheets)->get(['work_sheet_id', 'person_id'])->groupBy('work_sheet_id');
        $onOrders = $orderIds === [] ? collect()
            : ProductionCrew::query()->whereIn('order_id', $orderIds)->get(['order_id', 'person_id'])->groupBy('order_id');

        foreach ($productions as $i => $production) {
            $sheet = $production['sheet'];
            $closed = $sheet?->status === 'closed';
            if ($sheet !== null) {
                $production['start'] = $sheet->started_at->toImmutable();
                $production['end'] = $sheet->ended_at->toImmutable();
            }
            if ($closed) {
                $crew = ($onSheets[$sheet->id] ?? collect())->pluck('person_id');
            } else {
                $crew = collect($production['order_ids'])->flatMap(fn (int $id) => ($onOrders[$id] ?? collect())->pluck('person_id'));
            }
            $productions[$i] = $production + [
                'closed' => $closed,
                'required' => $production['line']->requiredFor($production['item']->format),
                'crew' => $crew->map(fn ($id) => (int) $id)->unique()->values()->all(),
            ];
        }

        return $productions;
    }

    /** The whole plan of a day, ready for the screen. */
    public function board(CarbonImmutable $day): array
    {
        $orders = $this->orders($day);
        $scheduled = $this->scheduled($day);

        // Per production, by its first order: how many people it has and needs.
        $staffing = [];
        $byLine = [];
        foreach ($this->productions($day, $scheduled) as $production) {
            $row = [
                'key' => $production['key'], 'people' => count($production['crew']), 'required' => $production['required'],
                'closed' => $production['closed'],
            ];
            $staffing[$production['key']] = $row;
            $byLine[$production['line']->id][] = $row;
        }

        $lines = [];
        $late = 0;
        foreach ($scheduled as $lineId => $entry) {
            /** @var Line $line */
            $line = $entry['line'];
            $blocks = [];
            $launch = -1;
            $previousKey = null;
            $firstOpenLaunch = null;
            foreach ($entry['blocks'] as $b) {
                /** @var Order $order */
                $order = $b['order'];
                // Consecutive blocks of the same item run as one launch: one work sheet.
                $key = $order->work_sheet_id ? 'sheet-'.$order->work_sheet_id : 'item-'.$b['item']['code'];
                if ($key !== $previousKey) {
                    $launch++;
                    $previousKey = $key;
                    if (! $order->work_sheet_id && $firstOpenLaunch === null) {
                        $firstOpenLaunch = $launch;
                    }
                }
                $late += $b['late'] ? 1 : 0;
                $blocks[] = $this->presentOrder($order) + [
                    'setup_type' => $b['setup_type'],
                    'setup_minutes' => $b['setup_minutes'],
                    'run_minutes' => $b['run_minutes'],
                    'wait_minutes' => $b['wait_minutes'],
                    'start' => $b['start']->format('H:i'),
                    'end' => $b['end']->format('H:i'),
                    'late' => $b['late'],
                    'late_minutes' => $b['late_minutes'],
                    'launch' => $launch,
                    // Set on the first block of a production only.
                    'crew_people' => $staffing[$order->id]['people'] ?? null,
                    'crew_required' => $staffing[$order->id]['required'] ?? null,
                ];
            }

            // The line is read through its productions still to be closed: the
            // one running or next gives the numbers, any short one raises the flag.
            $open = array_values(array_filter($byLine[$line->id] ?? [], fn (array $p) => ! $p['closed']));
            $short = count(array_filter($open, fn (array $p) => $p['people'] < $p['required']));
            $lines[] = [
                'id' => $line->id,
                'code' => $line->code,
                'name' => $line->name,
                'description' => $line->description,
                'building' => $line->building,
                'formats' => $line->capacities->map(fn ($c) => ['format' => $c->format, 'pallets_per_hour' => (float) $c->pallets_per_hour])->values()->all(),
                'startup_minutes' => (int) $line->startup_minutes,
                'required_people' => $open[0]['required'] ?? (int) $line->required_people,
                'assigned_people' => $open[0]['people'] ?? 0,
                'understaffed' => $short > 0,
                'open_productions' => count($open),
                'short_productions' => $short,
                'end' => $blocks !== [] ? end($blocks)['end'] : null,
                'pallets' => array_sum(array_column($blocks, 'pallets')),
                'late_count' => count(array_filter($blocks, fn ($b) => $b['late'])),
                'next_launch' => $firstOpenLaunch,
                'blocks' => $blocks,
            ];
        }

        $backlog = $orders->whereNull('line_id')->values()->map(fn (Order $o) => $this->presentOrder($o))->all();

        return [
            'day' => $day->toDateString(),
            'day_start' => Demo::DAY_START,
            'lines' => $lines,
            'backlog' => $backlog,
            'trucks' => $this->trucks($orders, $lines),
            'late_count' => $late,
            'waiting' => Order::query()->whereDate('day', $day->toDateString())->where('released', false)->count(),
        ];
    }

    /** One order, flattened for the screens. */
    public function presentOrder(Order $order): array
    {
        $item = $order->item;
        $sameDay = $order->arrived_at->isSameDay($order->day);

        return [
            'id' => $order->id,
            'number' => $order->number,
            'customer' => $order->customer?->name,
            'item_code' => $item->code,
            'variety' => $item->variety,
            'size' => $item->size,
            'format' => $item->format,
            'packaging' => $item->packaging,
            'pallets' => (int) $order->pallets,
            'kg' => round($order->pallets * $item->kgPerPallet()),
            'type' => $order->type,
            'arrived' => ($sameDay ? '' : 'ieri ').$order->arrived_at->format('H:i'),
            'truck' => $order->truck_at?->format('H:i'),
            'priority' => (int) $order->priority,
            'notes' => $order->notes,
            'line_id' => $order->line_id,
            'locked' => $order->work_sheet_id !== null,
            'sheet_id' => $order->work_sheet_id,
            'sheet_number' => $order->workSheet?->number,
            'sheet_status' => $order->workSheet?->status,
        ];
    }

    /** Per truck: what has to leave, how much is planned, how much is at risk. */
    private function trucks(Collection $orders, array $lines): array
    {
        $late = [];
        foreach ($lines as $line) {
            foreach ($line['blocks'] as $block) {
                if ($block['late']) {
                    $late[$block['id']] = true;
                }
            }
        }

        return $orders->filter(fn (Order $o) => $o->truck_at !== null)
            ->groupBy(fn (Order $o) => $o->truck_at->format('H:i'))
            ->map(fn (Collection $group, string $time) => [
                'time' => $time,
                'orders' => $group->count(),
                'pallets' => (int) $group->sum('pallets'),
                'unplanned' => $group->whereNull('line_id')->count(),
                'late' => $group->filter(fn (Order $o) => isset($late[$o->id]))->count(),
                'packed' => $group->filter(fn (Order $o) => $o->workSheet?->status === 'closed')->count(),
            ])
            ->sortKeys()->values()->all();
    }

    /**
     * Put an order on a line at a position, or back in the backlog.
     * Everything after it moves down; launched blocks stay where they are.
     */
    public function move(Order $order, ?Line $line, int $position): void
    {
        if ($order->work_sheet_id !== null) {
            throw new DomainException('L\'ordine è già stato lanciato: il suo foglio di lavorazione è aperto.');
        }
        if ($line !== null && $line->capacityFor($order->item->format) === null) {
            throw new DomainException(sprintf('%s non lavora il formato %s.', $line->name, $order->item->format));
        }

        DB::transaction(function () use ($order, $line, $position) {
            $day = $order->day->toDateString();
            $from = $order->line_id;

            if ($line === null) {
                $order->update(['line_id' => null, 'position' => null]);
            } else {
                $sequence = Order::query()->whereDate('day', $day)->where('line_id', $line->id)
                    ->where('id', '!=', $order->id)->orderBy('position')->orderBy('id')->get();
                $locked = $sequence->whereNotNull('work_sheet_id')->count();
                $position = max($locked, min($position, $sequence->count()));
                $sequence->splice($position, 0, [$order]);
                foreach ($sequence->values() as $i => $o) {
                    $o->update(['line_id' => $line->id, 'position' => $i]);
                }
            }

            if ($from !== null && $from !== $line?->id) {
                $this->renumber($day, $from);
            }
        });
    }

    /** Replace the sequence of everything not yet launched with the proposed one. */
    public function propose(CarbonImmutable $day): array
    {
        $orders = $this->orders($day);
        $lines = $this->lines();

        $definitions = [];
        $locked = [];
        foreach ($lines as $line) {
            $definitions[$line->code] = $this->definition($line);
            $locked[$line->code] = $orders->where('line_id', $line->id)->whereNotNull('work_sheet_id')
                ->sortBy('position')->values()->map(fn (Order $o) => $this->block($o))->all();
        }
        $free = $orders->whereNull('work_sheet_id')->values()->map(fn (Order $o) => $this->block($o))->all();

        $proposal = $this->planner()->propose($definitions, $free, Demo::dayStart($day), $locked);

        DB::transaction(function () use ($proposal, $lines) {
            foreach ($lines as $line) {
                foreach ($proposal['lines'][$line->code] as $i => $block) {
                    $block['order']->update(['line_id' => $line->id, 'position' => $i]);
                }
            }
            foreach ($proposal['unplaced'] as $block) {
                $block['order']->update(['line_id' => null, 'position' => null]);
            }
        });

        return ['placed' => count($free) - count($proposal['unplaced']), 'unplaced' => count($proposal['unplaced'])];
    }

    /**
     * Launch the next group of a line: its blocks get a work sheet, pre-filled
     * from the plan, and can no longer be moved.
     */
    public function launch(Line $line, CarbonImmutable $day): WorkSheet
    {
        $entry = $this->scheduled($day)[$line->id] ?? null;
        $group = [];
        foreach ($entry['blocks'] ?? [] as $block) {
            if ($block['order']->work_sheet_id !== null) {
                continue;
            }
            if ($group !== [] && $group[0]['item']['code'] !== $block['item']['code']) {
                break;
            }
            $group[] = $block;
        }
        if ($group === []) {
            throw new DomainException(sprintf('Su %s non ci sono ordini da lanciare.', $line->name));
        }

        return app(WorkSheetService::class)->createFromPlan($line, $day, $group);
    }

    private function renumber(string $day, int $lineId): void
    {
        $sequence = Order::query()->whereDate('day', $day)->where('line_id', $lineId)->orderBy('position')->orderBy('id')->get();
        foreach ($sequence as $i => $o) {
            if ($o->position !== $i) {
                $o->update(['position' => $i]);
            }
        }
    }
}
