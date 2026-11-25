<?php

namespace Modules\Ortofrutta\Services;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Ortofrutta\Domain\CostCalculator;
use Modules\Ortofrutta\Domain\Traceability;
use Modules\Ortofrutta\Models\Customer;
use Modules\Ortofrutta\Models\Lot;
use Modules\Ortofrutta\Models\Order;
use Modules\Ortofrutta\Models\Producer;
use Modules\Ortofrutta\Models\WorkSheetLot;

/**
 * Traceability, in both directions.
 *
 * Back: from what a customer received (an order) to the work sheet that
 * packed it, the raw lots that went into it, the deliveries weighed at the
 * gate and the producers. Forward: from a raw lot to every work sheet that
 * used it and every customer it reached — what a recall needs.
 *
 * Nothing is stored for this: the chain is order → work sheet → lots of the
 * sheet → lot → producer, which the other screens already fill in.
 */
class TraceService
{
    /** Rows shown for a search; the total is reported next to them. */
    public const LIMIT = 150;

    /** Customers, producers and the days covered, for the search form. */
    public function options(): array
    {
        return [
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Customer $c) => ['id' => $c->id, 'name' => $c->name])->all(),
            'producers' => Producer::query()->orderBy('name')->get(['id', 'code', 'name'])
                ->map(fn (Producer $p) => ['id' => $p->id, 'name' => $p->name, 'code' => $p->code])->all(),
            'first_day' => $this->date(Order::query()->min('day')),
            'last_day' => $this->date(Order::query()->max('day')),
        ];
    }

    /**
     * Orders matching the search, most recent first.
     *
     * @param  array{q?: ?string, cliente?: ?int, produttore?: ?int, dal?: ?string, al?: ?string}  $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function orders(array $filters): array
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $like = $this->like($q);
        // "1702" or the packing lot printed on the pallet, "L26328-1702".
        $sheetNumber = Traceability::sheetNumber($q) ?? (ctype_digit($q) && strlen($q) <= 9 ? (int) $q : null);

        $query = Order::query()
            ->where('released', true)
            ->when($filters['cliente'] ?? null, fn ($w, $id) => $w->where('customer_id', $id))
            ->when($filters['dal'] ?? null, fn ($w, $day) => $w->whereDate('day', '>=', $day))
            ->when($filters['al'] ?? null, fn ($w, $day) => $w->whereDate('day', '<=', $day))
            ->when($filters['produttore'] ?? null, fn ($w, $id) => $w->whereHas(
                'workSheet.lots.lot', fn ($lot) => $lot->where('producer_id', $id),
            ))
            ->when($q !== '', fn ($w) => $w->where(function ($w) use ($like, $sheetNumber) {
                $w->where('number', 'ilike', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', $like))
                    ->orWhereHas('item', fn ($i) => $i->where(
                        fn ($x) => $x->where('description', 'ilike', $like)->orWhere('code', 'ilike', $like),
                    ))
                    ->orWhereHas('workSheet.lots.lot', fn ($lot) => $lot->where(
                        fn ($x) => $x->where('code', 'ilike', $like)
                            ->orWhereHas('producer', fn ($p) => $p->where('name', 'ilike', $like)),
                    ));
                if ($sheetNumber !== null) {
                    $w->orWhereHas('workSheet', fn ($s) => $s->where('number', $sheetNumber));
                }
            }));

        $total = (clone $query)->count();
        $rows = $query->with(['customer', 'item', 'line', 'workSheet.lots.lot.producer'])
            ->orderByDesc('day')->orderByDesc('truck_at')->orderBy('number')
            ->limit(self::LIMIT)->get()
            ->map(fn (Order $o) => $this->orderRow($o))->all();

        return ['rows' => $rows, 'total' => $total];
    }

    /** From an order back to the producers. */
    public function order(Order $order): array
    {
        $order->loadMissing([
            'customer', 'item', 'line',
            'workSheet.line', 'workSheet.orders.customer', 'workSheet.people.person',
            'workSheet.lots.lot.producer', 'workSheet.lots.lot.cell',
        ]);
        $item = $order->item;
        $sheet = $order->workSheet;

        $head = $this->orderRow($order) + [
            'channel' => $order->customer?->channel,
            'type' => $order->type,
            'size' => $item->size,
            'format' => $item->format,
            'packaging' => $item->packaging,
            'cartons' => (int) $order->pallets * (int) $item->cartons_per_pallet,
            'arrived' => $order->arrived_at->format('d/m/Y H:i'),
            'truck_full' => $order->truck_at?->format('d/m/Y H:i'),
        ];
        if ($sheet === null) {
            return ['order' => $head, 'sheet' => null, 'lots' => [], 'producers' => [], 'crew' => []];
        }

        $pallets = $sheet->orders->mapWithKeys(fn (Order $o) => [$o->id => (int) $o->pallets])->all();
        $packed = $sheet->lots->mapWithKeys(fn (WorkSheetLot $r) => [$r->lot_id => (float) $r->kg_packed])->all();
        $mine = Traceability::allocate($packed, $pallets)[$order->id] ?? [];
        $kgForOrder = array_sum($mine);

        $lots = $sheet->lots->sortBy(fn (WorkSheetLot $r) => $r->lot->received_at)->values()->map(fn (WorkSheetLot $r) => [
            'lot_id' => $r->lot_id,
            'kg_in' => (float) $r->kg_in,
            'kg_packed' => (float) $r->kg_packed,
            'kg_waste' => (float) $r->kg_waste_cider + (float) $r->kg_waste_industry,
            'yield' => CostCalculator::yield((float) $r->kg_packed, (float) $r->kg_in),
            'kg_for_order' => round($mine[$r->lot_id] ?? 0.0, 1),
            // Days the fruit spent in the cell before this run.
            'days_stored' => (int) $r->lot->received_at->copy()->startOfDay()->diffInDays($sheet->day->copy()->startOfDay()),
        ] + $this->lotCard($r->lot))->all();

        $producers = collect($lots)->groupBy('producer')->map(fn ($group, $name) => [
            'name' => $name,
            'code' => $group[0]['producer_code'],
            'locality' => $group[0]['locality'],
            'is_member' => $group[0]['is_member'],
            'lots' => $group->pluck('code')->all(),
            'kg_packed' => round($group->sum('kg_packed'), 1),
            'kg_for_order' => round($group->sum('kg_for_order'), 1),
            'share' => $kgForOrder > 0 ? $group->sum('kg_for_order') / $kgForOrder : null,
        ])->sortByDesc('kg_for_order')->values()->all();

        $crew = $sheet->people->map(fn ($p) => [
            'name' => $p->person?->name,
            'qualification' => $p->person?->qualification,
            'is_leader' => $p->person?->qualification === 'Capolinea',
            'hours' => (float) $p->hours,
        ])->sortBy([['is_leader', 'desc'], ['name', 'asc']])->values()->all();

        return [
            'order' => $head,
            'sheet' => [
                'id' => $sheet->id,
                'number' => $sheet->number,
                'packing_lot' => Traceability::packingLot($sheet->day, $sheet->number),
                'status' => $sheet->status,
                'line' => $sheet->line?->name,
                'line_description' => $sheet->line?->description,
                'day_label' => $this->dayLabel($sheet->day, true),
                'start' => $sheet->started_at->format('H:i'),
                'end' => $sheet->ended_at->format('H:i'),
                'pallets' => (int) $sheet->pallets,
                'kg_in' => (float) $sheet->kg_in,
                'kg_packed' => (float) $sheet->kg_packed,
                'kg_waste_cider' => (float) $sheet->kg_waste_cider,
                'kg_waste_industry' => (float) $sheet->kg_waste_industry,
                'yield' => CostCalculator::yield((float) $sheet->kg_packed, (float) $sheet->kg_in),
                'share' => array_sum($pallets) > 0 ? $order->pallets / array_sum($pallets) : null,
                'kg_for_order' => round($kgForOrder, 1),
                'people' => count($crew),
                'leader' => collect($crew)->firstWhere('is_leader', true)['name'] ?? null,
                'orders' => $sheet->orders->sortBy('position')->values()->map(fn (Order $o) => [
                    'id' => $o->id, 'number' => $o->number, 'customer' => $o->customer?->name,
                    'pallets' => (int) $o->pallets, 'mine' => $o->id === $order->id,
                ])->all(),
            ],
            'lots' => $lots,
            'producers' => $producers,
            'crew' => $crew,
        ];
    }

    /**
     * Raw lots matching the search, most recent delivery first, with how much
     * of each has been worked and how many customers it reached.
     *
     * @param  array{q?: ?string, cliente?: ?int, produttore?: ?int}  $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function lots(array $filters): array
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $like = $this->like($q);

        $lots = Lot::query()->with(['producer', 'cell'])
            ->when($filters['produttore'] ?? null, fn ($w, $id) => $w->where('producer_id', $id))
            ->when($filters['cliente'] ?? null, fn ($w, $id) => $w->whereHas(
                'sheetLots.workSheet.orders', fn ($o) => $o->where('customer_id', $id),
            ))
            ->when($q !== '', fn ($w) => $w->where(
                fn ($x) => $x->where('code', 'ilike', $like)->orWhere('variety', 'ilike', $like)
                    ->orWhereHas('producer', fn ($p) => $p->where('name', 'ilike', $like)),
            ))
            ->orderByDesc('received_at')->orderBy('code')->get();

        $used = DB::table('orto_work_sheet_lots')
            ->selectRaw('lot_id, sum(kg_in) as kg_in, count(distinct work_sheet_id) as sheets')
            ->groupBy('lot_id')->get()->keyBy('lot_id');
        $reached = DB::table('orto_work_sheet_lots as wl')
            ->join('orto_orders as o', 'o.work_sheet_id', '=', 'wl.work_sheet_id')
            ->selectRaw('wl.lot_id, count(distinct o.customer_id) as customers')
            ->groupBy('wl.lot_id')->pluck('customers', 'lot_id');

        $rows = $lots->map(fn (Lot $lot) => [
            'id' => $lot->id,
            'code' => $lot->code,
            'producer' => $lot->producer?->name,
            'variety' => $lot->variety,
            'received' => $lot->received_at->format('d/m/Y'),
            'net_kg' => (float) $lot->net_kg,
            'kg_worked' => (float) ($used[$lot->id]->kg_in ?? 0),
            'kg_left' => max(0.0, (float) $lot->net_kg - (float) ($used[$lot->id]->kg_in ?? 0)),
            'sheets' => (int) ($used[$lot->id]->sheets ?? 0),
            'customers' => (int) ($reached[$lot->id] ?? 0),
        ])->all();

        return ['rows' => $rows, 'total' => count($rows)];
    }

    /** From a raw lot forward to the customers. */
    public function lot(Lot $lot): array
    {
        $lot->loadMissing(['producer', 'cell']);
        $rows = WorkSheetLot::query()
            ->with(['workSheet.line', 'workSheet.item', 'workSheet.orders.customer'])
            ->where('lot_id', $lot->id)->get()
            ->sortBy(fn (WorkSheetLot $r) => $r->workSheet->started_at)->values();

        $sheets = [];
        $deliveries = [];
        $allocated = 0.0;
        foreach ($rows as $row) {
            $sheet = $row->workSheet;
            $packingLot = Traceability::packingLot($sheet->day, $sheet->number);
            $sheets[] = [
                'id' => $sheet->id,
                'number' => $sheet->number,
                'packing_lot' => $packingLot,
                'status' => $sheet->status,
                'day_label' => $this->dayLabel($sheet->day),
                'line' => $sheet->line?->name,
                'item' => $sheet->item?->description,
                'start' => $sheet->started_at->format('H:i'),
                'end' => $sheet->ended_at->format('H:i'),
                'kg_in' => (float) $row->kg_in,
                'kg_packed' => (float) $row->kg_packed,
                'kg_waste' => (float) $row->kg_waste_cider + (float) $row->kg_waste_industry,
                'yield' => CostCalculator::yield((float) $row->kg_packed, (float) $row->kg_in),
            ];

            $shares = Traceability::allocate(
                [$lot->id => (float) $row->kg_packed],
                $sheet->orders->mapWithKeys(fn (Order $o) => [$o->id => (int) $o->pallets])->all(),
            );
            foreach ($sheet->orders->sortBy('position') as $order) {
                $kg = $shares[$order->id][$lot->id] ?? 0.0;
                $allocated += $kg;
                $deliveries[] = [
                    'order_id' => $order->id,
                    'number' => $order->number,
                    'day' => $order->day->toDateString(),
                    'day_label' => $this->dayLabel($order->day),
                    'customer' => $order->customer?->name,
                    'pallets' => (int) $order->pallets,
                    'truck' => $order->truck_at?->format('H:i'),
                    'sheet_number' => $sheet->number,
                    'packing_lot' => $packingLot,
                    'item' => $sheet->item?->description,
                    'kg' => round($kg, 1),
                ];
            }
        }

        $customers = collect($deliveries)->groupBy('customer')->map(fn ($group, $name) => [
            'name' => $name,
            'orders' => $group->count(),
            'pallets' => $group->sum('pallets'),
            'kg' => round($group->sum('kg'), 1),
            'first' => $group->min('day'),
            'last' => $group->max('day'),
        ])->sortByDesc('kg')->values()->map(fn (array $c) => [
            'first' => $this->dayLabel(Carbon::parse($c['first'])), 'last' => $this->dayLabel(Carbon::parse($c['last'])),
        ] + $c)->all();

        $kgIn = (float) $rows->sum('kg_in');
        $kgPacked = (float) $rows->sum('kg_packed');

        return [
            'lot' => $this->lotCard($lot) + [
                'lot_id' => $lot->id,
                'kg_worked' => $kgIn,
                'kg_packed' => $kgPacked,
                'kg_waste' => (float) $rows->sum('kg_waste_cider') + (float) $rows->sum('kg_waste_industry'),
                'kg_left' => max(0.0, (float) $lot->net_kg - $kgIn),
                'yield' => CostCalculator::yield($kgPacked, $kgIn),
                // Packed on a sheet with no order behind it: still in the warehouse.
                'kg_unassigned' => round(max(0.0, $kgPacked - $allocated), 1),
            ],
            'sheets' => $sheets,
            'deliveries' => $deliveries,
            'customers' => $customers,
        ];
    }

    /** The first order of a work sheet: where a link from the sheet lands. */
    public function firstOrderOfSheet(int $sheetId): ?Order
    {
        return Order::query()->where('work_sheet_id', $sheetId)->orderBy('position')->orderBy('id')->first();
    }

    private function orderRow(Order $order): array
    {
        $sheet = $order->workSheet;

        return [
            'id' => $order->id,
            'day' => $order->day->toDateString(),
            'day_label' => $this->dayLabel($order->day),
            'number' => $order->number,
            'customer' => $order->customer?->name,
            'item_code' => $order->item->code,
            'item' => $order->item->description,
            'variety' => $order->item->variety,
            'pallets' => (int) $order->pallets,
            'kg' => round($order->pallets * $order->item->kgPerPallet()),
            'truck' => $order->truck_at?->format('H:i'),
            // packed: its sheet is closed | running: sheet open | planned: on a line | waiting: not yet planned
            'status' => match (true) {
                $sheet?->status === 'closed' => 'packed',
                $sheet !== null => 'running',
                $order->line_id !== null => 'planned',
                default => 'waiting',
            },
            'line' => $order->line?->name,
            'sheet_id' => $sheet?->id,
            'sheet_number' => $sheet?->number,
            'packing_lot' => $sheet ? Traceability::packingLot($sheet->day, $sheet->number) : null,
            'producers' => $sheet
                ? $sheet->lots->map(fn (WorkSheetLot $r) => $r->lot?->producer?->name)->filter()->unique()->values()->all()
                : [],
        ];
    }

    /** What is known about a raw lot from the weighbridge. */
    private function lotCard(Lot $lot): array
    {
        return [
            'code' => $lot->code,
            'producer' => $lot->producer?->name,
            'producer_code' => $lot->producer?->code,
            'locality' => $lot->producer?->locality,
            'is_member' => (bool) $lot->producer?->is_member,
            'variety' => $lot->variety,
            'received' => $lot->received_at->format('d/m/Y H:i'),
            'gross_kg' => (float) $lot->gross_kg,
            'tare_kg' => (float) $lot->tare_kg,
            'net_kg' => (float) $lot->net_kg,
            'bins' => (int) $lot->bins,
            'cell' => $lot->cell?->code,
            'building' => $lot->cell?->building,
        ];
    }

    private function dayLabel(DateTimeInterface $day, bool $long = false): string
    {
        return Carbon::instance($day)->locale('it')->isoFormat($long ? 'dddd D MMMM YYYY' : 'ddd D MMM');
    }

    private function date(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->toDateString() : null;
    }

    /** A "contains" pattern with the wildcards of the text itself made literal. */
    private function like(string $text): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text).'%';
    }
}
