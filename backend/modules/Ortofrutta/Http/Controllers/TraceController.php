<?php

namespace Modules\Ortofrutta\Http\Controllers;

use Inertia\Response;
use Modules\Ortofrutta\Http\Requests\TraceRequest;
use Modules\Ortofrutta\Models\Lot;
use Modules\Ortofrutta\Models\Order;
use Modules\Ortofrutta\Services\TraceService;
use Modules\Ortofrutta\Support\Demo;

/**
 * Screen 5 — traceability: from what a customer received back to the
 * producers, and from a raw lot forward to the customers it reached.
 */
class TraceController extends OrtoController
{
    public function __construct(private TraceService $trace) {}

    public function index(TraceRequest $request): Response
    {
        $filters = $request->validated();
        $byLot = ($filters['vista'] ?? null) === 'lotto'
            || (! empty($filters['lotto']) && empty($filters['ordine']) && empty($filters['foglio']) && empty($filters['vista']));

        $props = [
            'mode' => $byLot ? 'lotto' : 'cliente',
            'filters' => [
                'q' => $filters['q'] ?? '',
                'cliente' => $filters['cliente'] ?? null,
                'produttore' => $filters['produttore'] ?? null,
                'dal' => $filters['dal'] ?? null,
                'al' => $filters['al'] ?? null,
            ],
            'options' => null,
            'results' => ['rows' => [], 'total' => 0],
            'selected' => null,
            'detail' => null,
        ];
        if (! Demo::loaded()) {
            return $this->page('Trace', $props);
        }
        $props['options'] = $this->trace->options();

        if ($byLot) {
            $props['results'] = $this->trace->lots($filters);
            // The lot asked for, or the first one of the list that has been worked.
            $id = $filters['lotto'] ?? collect($props['results']['rows'])->firstWhere('sheets', '>', 0)['id'] ?? null;
            $lot = $id ? Lot::query()->find($id) : null;
            $props['selected'] = $lot?->id;
            $props['detail'] = $lot ? $this->trace->lot($lot) : null;

            return $this->page('Trace', $props);
        }

        $props['results'] = $this->trace->orders($filters);
        $order = match (true) {
            ! empty($filters['ordine']) => Order::query()->find($filters['ordine']),
            ! empty($filters['foglio']) => $this->trace->firstOrderOfSheet((int) $filters['foglio']),
            default => null,
        };
        // Nothing asked for: open on the first order of the list that has been packed.
        $order ??= ($id = collect($props['results']['rows'])->firstWhere('status', 'packed')['id'] ?? null)
            ? Order::query()->find($id) : null;
        $props['selected'] = $order?->id;
        $props['detail'] = $order ? $this->trace->order($order) : null;

        return $this->page('Trace', $props);
    }
}
