<?php

namespace Modules\Ortofrutta\Http\Controllers;

use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Ortofrutta\Http\Requests\StoreDeliveryRequest;
use Modules\Ortofrutta\Models\Cell;
use Modules\Ortofrutta\Models\Item;
use Modules\Ortofrutta\Providers\OrtofruttaServiceProvider;
use Modules\Ortofrutta\Services\CrewService;
use Modules\Ortofrutta\Services\DemoLoader;
use Modules\Ortofrutta\Services\PlanService;
use Modules\Ortofrutta\Services\PlantService;
use Modules\Ortofrutta\Support\Demo;

/**
 * The plant map, the deliveries weighed at the gate and the demo controls.
 */
class PlantController extends OrtoController
{
    public function __construct(private PlantService $plant, private PlanService $plan) {}

    /** Home: both buildings, with cells, lines and the packing area. */
    public function index(CrewService $crews): Response
    {
        if (! Demo::loaded()) {
            return $this->page('Plant', ['cells' => [], 'lines' => [], 'trucks' => [], 'summary' => null]);
        }

        $day = Demo::day();
        $board = $this->plan->board($day);
        $cells = $this->plant->cells();

        return $this->page('Plant', [
            'cells' => $cells,
            'lines' => $this->plant->lines($board),
            'trucks' => $board['trucks'],
            'summary' => [
                'orders' => array_sum(array_map(fn ($l) => count($l['blocks']), $board['lines'])) + count($board['backlog']),
                'pallets' => array_sum(array_column($board['lines'], 'pallets')) + array_sum(array_column($board['backlog'], 'pallets')),
                'backlog' => count($board['backlog']),
                'waiting' => $board['waiting'],
                'late' => $board['late_count'],
                'kg_in_cells' => array_sum(array_column($cells, 'kg')),
                'bins_in_cells' => array_sum(array_column($cells, 'bins')),
                'crew' => $crews->board($day)['summary'],
            ],
        ]);
    }

    public function deliveries(): Response
    {
        return $this->page('Deliveries', [
            'deliveries' => Demo::loaded() ? $this->plant->deliveries() : [],
            'producers' => $this->plant->producers(),
            'varieties' => Item::query()->distinct()->orderBy('variety')->pluck('variety'),
            'cells' => Demo::loaded() ? array_map(fn ($c) => [
                'id' => $c['id'], 'code' => $c['code'], 'building' => $c['building'], 'bins' => $c['bins'],
                'capacity_bins' => $c['capacity_bins'], 'varieties' => $c['varieties'],
            ], $this->plant->cells()) : [],
        ]);
    }

    public function storeDelivery(StoreDeliveryRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $cell = collect($this->plant->cells())->firstWhere('id', (int) $data['cell_id']);
        if ($cell && $cell['bins'] + (int) $data['bins'] > $cell['capacity_bins']) {
            return back()->withErrors(['cell_id' => sprintf(
                'La cella %s ha posto per %d bins, il carico ne ha %d.',
                $cell['code'], max(0, $cell['capacity_bins'] - $cell['bins']), $data['bins'],
            )]);
        }
        if ((float) $data['gross_kg'] <= (int) $data['bins'] * (float) $data['bin_tare_kg']) {
            return back()->withErrors(['gross_kg' => 'Il peso lordo deve essere maggiore della tara dei bins.']);
        }

        $lot = $this->plant->receive($data);

        return back()->with('success', sprintf(
            'Conferimento registrato: lotto %s, %s kg netti in cella %s.',
            $lot->code, number_format($lot->net_kg, 0, ',', '.'), Cell::query()->find($lot->cell_id)?->code,
        ));
    }

    /** The screens used to live under /admin/ortofrutta: send old links to the new address. */
    public function moved(Request $request, string $path = ''): RedirectResponse
    {
        $query = $request->getQueryString();

        return redirect(rtrim(OrtofruttaServiceProvider::BASE.'/'.$path, '/').($query ? '?'.$query : ''));
    }

    /** "Ripristina dati demo": rebuild everything from the CSV files. */
    public function reset(DemoLoader $loader): RedirectResponse
    {
        try {
            $counts = $loader->load();
        } catch (DomainException|\RuntimeException $e) {
            return back()->with('error', 'Dati demo non caricati: '.$e->getMessage());
        }

        return redirect(OrtofruttaServiceProvider::BASE)->with('success', sprintf(
            'Dati demo ripristinati: %d ordini, %d fogli di lavorazione, %d lotti, %d persone. Sono le %s.',
            $counts['orders'], $counts['sheets'], $counts['lots'], $counts['people'], Demo::clock(),
        ));
    }
}
