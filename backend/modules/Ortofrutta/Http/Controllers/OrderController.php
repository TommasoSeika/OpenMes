<?php

namespace Modules\Ortofrutta\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Ortofrutta\Http\Requests\PriorityRequest;
use Modules\Ortofrutta\Http\Requests\StockOrderRequest;
use Modules\Ortofrutta\Models\Customer;
use Modules\Ortofrutta\Models\Item;
use Modules\Ortofrutta\Models\Line;
use Modules\Ortofrutta\Models\Order;
use Modules\Ortofrutta\Services\PlanService;
use Modules\Ortofrutta\Support\Demo;

/** Screen 1 — the orders of the day, as the office sees them. */
class OrderController extends OrtoController
{
    /** Orders arriving within this many minutes of the first one enter together. */
    private const WAVE_MINUTES = 70;

    public function __construct(private PlanService $plan) {}

    public function index(Request $request): Response
    {
        $day = $this->day($request);
        if (! Demo::loaded()) {
            return $this->page('Orders', ['orders' => [], 'items' => [], 'waiting' => 0, 'lines' => []], $day);
        }

        $board = $this->plan->board($day);
        $timing = [];
        foreach ($board['lines'] as $line) {
            foreach ($line['blocks'] as $block) {
                $timing[$block['id']] = ['line' => $line['code'], 'start' => $block['start'], 'end' => $block['end'], 'late' => $block['late']];
            }
        }

        $orders = $this->plan->orders($day)
            ->sortBy(fn (Order $o) => [$o->truck_at?->format('H:i') ?? '99:99', $o->priority, $o->arrived_at->format('Y-m-d H:i')])
            ->values()
            ->map(fn (Order $o) => $this->plan->presentOrder($o) + ['plan' => $timing[$o->id] ?? null])
            ->all();

        return $this->page('Orders', [
            'orders' => $orders,
            'waiting' => $board['waiting'],
            'items' => Item::query()->orderBy('code')->get()->map(fn (Item $i) => [
                'id' => $i->id, 'code' => $i->code, 'description' => $i->description,
            ]),
            'lines' => Line::query()->orderBy('sort')->pluck('name', 'code'),
        ], $day);
    }

    /**
     * "Simula arrivo ordini": the next wave of same-day orders comes in and the
     * demo clock moves to the moment the last of them arrived.
     */
    public function simulate(): RedirectResponse
    {
        $day = Demo::day();
        $pending = Order::query()->whereDate('day', $day->toDateString())->where('released', false)->orderBy('arrived_at')->get();
        if ($pending->isEmpty()) {
            return back()->with('error', 'Tutti gli ordini della giornata sono già arrivati.');
        }

        $until = $pending->first()->arrived_at->copy()->addMinutes(self::WAVE_MINUTES);
        $wave = $pending->filter(fn (Order $o) => $o->arrived_at <= $until);
        Order::query()->whereIn('id', $wave->pluck('id'))->update(['released' => true]);

        $last = $wave->max('arrived_at');
        $minutes = (int) ceil(($last->hour * 60 + $last->minute) / 5) * 5;
        $clock = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        if ($clock > Demo::clock()) {
            Demo::set('demo_clock', $clock);
        }

        $fast = $wave->filter(fn (Order $o) => $o->truck_at?->format('H:i') === '15:00')->count();

        return back()->with('success', sprintf(
            'Sono le %s: arrivati %d ordini A per A, %d per il camion delle 15:00. Sono da pianificare.',
            Demo::clock(), $wave->count(), $fast,
        ));
    }

    /** The office decides what leaves first. */
    public function priority(PriorityRequest $request, Order $order): RedirectResponse
    {
        $order->update(['priority' => (int) $request->validated('priority')]);

        return back()->with('success', sprintf('Priorità dell\'ordine %s aggiornata.', $order->number));
    }

    /** A stock row added by the production manager: no customer truck, lowest priority. */
    public function stock(StockOrderRequest $request): RedirectResponse
    {
        $day = Demo::day();
        $item = Item::query()->findOrFail((int) $request->validated('item_id'));
        $customerId = $item->customer_id ?? Customer::query()->orderBy('code')->value('id');

        $next = Order::query()->where('type', 'stock')->count() + 1;
        do {
            $number = sprintf('SC%05d', $next++);
        } while (Order::query()->where('number', $number)->exists());

        Order::query()->create([
            'number' => $number,
            'day' => $day->toDateString(),
            'customer_id' => $customerId,
            'item_id' => $item->id,
            'pallets' => (int) $request->validated('pallets'),
            'type' => 'stock',
            'arrived_at' => Demo::now(),
            'truck_at' => null,
            'priority' => 3,
            'notes' => 'Produzione a scorta',
            'released' => true,
        ]);

        return back()->with('success', sprintf('Riga di scorta %s aggiunta: è da pianificare.', $number));
    }
}
