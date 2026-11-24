<?php

namespace Modules\Ortofrutta\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Ortofrutta\Http\Requests\SimulatePriceRequest;
use Modules\Ortofrutta\Models\Customer;
use Modules\Ortofrutta\Models\Lot;
use Modules\Ortofrutta\Models\PriceListEntry;
use Modules\Ortofrutta\Models\Producer;
use Modules\Ortofrutta\Services\MarginService;
use Modules\Ortofrutta\Support\Demo;

/** Screen 5 — margins per sheet, lot, producer and customer, and the price simulator. */
class MarginController extends OrtoController
{
    public function __construct(private MarginService $margins) {}

    public function index(Request $request): Response
    {
        $filters = [
            'producer_id' => $request->integer('produttore') ?: null,
            'customer_id' => $request->integer('cliente') ?: null,
            'lot_id' => $request->integer('lotto') ?: null,
        ];

        if (! Demo::loaded()) {
            return $this->page('Margins', ['report' => null, 'filters' => $filters, 'options' => null, 'discounts' => []]);
        }

        $week = $this->margins->week(Demo::day());

        return $this->page('Margins', [
            'report' => $this->margins->report($filters),
            'filters' => $filters,
            'options' => [
                'producers' => Producer::query()->orderBy('name')->get(['id', 'name']),
                'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
                'lots' => Lot::query()->orderBy('code')->get(['id', 'code']),
            ],
            // Weeks on promotion from the price list, to try in the simulator.
            'discounts' => PriceListEntry::query()->where('discount_pct', '>', 0)
                ->join('orto_customers as c', 'c.id', '=', 'orto_price_list.customer_id')
                ->selectRaw('c.name as customer, orto_price_list.week, max(orto_price_list.discount_pct) as discount_pct')
                ->groupBy('c.name', 'orto_price_list.week')->orderBy('orto_price_list.week')->orderBy('c.name')->get()
                ->map(fn ($r) => [
                    'customer' => $r->customer, 'week' => $r->week, 'discount_pct' => (float) $r->discount_pct,
                    'current' => $r->week === $week,
                ]),
        ]);
    }

    public function simulate(SimulatePriceRequest $request): JsonResponse
    {
        $d = $request->validated();

        return response()->json($this->margins->simulate(
            (float) $d['purchase_price'], (float) $d['yield_pct'], (float) $d['processing'], (float) $d['discount_pct'],
            isset($d['list_price']) && $d['list_price'] !== null ? (float) $d['list_price'] : null,
        ));
    }
}
