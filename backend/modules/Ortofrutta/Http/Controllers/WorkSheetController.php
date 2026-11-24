<?php

namespace Modules\Ortofrutta\Http\Controllers;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Ortofrutta\Domain\CostCalculator;
use Modules\Ortofrutta\Http\Requests\UpdateWorkSheetRequest;
use Modules\Ortofrutta\Models\Lot;
use Modules\Ortofrutta\Models\WorkSheet;
use Modules\Ortofrutta\Services\WorkSheetService;
use Modules\Ortofrutta\Support\Demo;

/** Screen 4 — work sheets: lots in, kilograms out, cost per kilogram on save. */
class WorkSheetController extends OrtoController
{
    public function __construct(private WorkSheetService $sheets) {}

    public function index(Request $request): Response
    {
        $day = $this->day($request);

        $rows = Demo::loaded()
            ? WorkSheet::query()->with(['line', 'item', 'orders.customer'])->withCount('people')
                ->whereDate('day', $day->toDateString())->orderBy('started_at')->orderBy('number')->get()
                ->map(fn (WorkSheet $s) => $this->summary($s))->all()
            : [];

        return $this->page('WorkSheets', ['sheets' => $rows], $day);
    }

    public function show(WorkSheet $sheet): Response
    {
        $sheet->load(['line', 'item.components', 'orders.customer', 'people.person', 'lots.lot.producer', 'lots.lot.cell']);
        $costing = $this->sheets->costing($sheet);
        $remaining = $this->sheets->remainingByLot();

        $lots = $sheet->lots->map(fn ($row) => [
            'lot_id' => $row->lot_id,
            'code' => $row->lot->code,
            'producer' => $row->lot->producer?->name,
            'cell' => $row->lot->cell?->code,
            'purchase_price_kg' => (float) $row->lot->purchase_price_kg,
            // What is left in the cell, counting back what this sheet itself took.
            'remaining_kg' => round(($remaining[$row->lot_id] ?? 0) + (float) $row->kg_in),
            'kg_in' => (float) $row->kg_in,
            'kg_packed' => (float) $row->kg_packed,
            'kg_waste_cider' => (float) $row->kg_waste_cider,
            'kg_waste_industry' => (float) $row->kg_waste_industry,
            'yield' => CostCalculator::yield((float) $row->kg_packed, (float) $row->kg_in),
        ])->values()->all();

        $available = Lot::query()->with(['producer', 'cell'])->where('variety', $sheet->item->variety)->orderBy('received_at')->get()
            ->filter(fn (Lot $l) => ($remaining[$l->id] ?? 0) >= 1 || $sheet->lots->contains('lot_id', $l->id))
            ->map(fn (Lot $l) => $this->sheets->presentLot($l, ($remaining[$l->id] ?? 0) + (float) ($sheet->lots->firstWhere('lot_id', $l->id)?->kg_in ?? 0)))
            ->values()->all();

        $day = CarbonImmutable::parse($sheet->day);

        return $this->page('WorkSheet', [
            'sheet' => $this->summary($sheet) + [
                'kg_per_carton' => (float) $sheet->item->kg_per_carton,
                'cartons_per_pallet' => (int) $sheet->item->cartons_per_pallet,
                'kg_per_pallet' => $sheet->item->kgPerPallet(),
                'planned_kg' => round($sheet->pallets * $sheet->item->kgPerPallet()),
                'orders' => $sheet->orders->map(fn ($o) => [
                    'number' => $o->number, 'customer' => $o->customer?->name, 'pallets' => (int) $o->pallets,
                    'truck' => $o->truck_at?->format('H:i'), 'type' => $o->type,
                ])->values()->all(),
            ],
            'crew' => $sheet->people->map(fn ($p) => [
                'name' => $p->person?->name, 'qualification' => $p->person?->qualification,
                'hours' => (float) $p->hours, 'hourly_cost' => (float) $p->hourly_cost,
                'cost' => round((float) $p->hours * (float) $p->hourly_cost, 2),
            ])->sortBy('name')->values()->all(),
            'components' => $sheet->item->components->map(fn ($c) => [
                'component' => $c->component, 'quantity' => (float) $c->quantity, 'per' => $c->per,
                'unit_cost' => (float) $c->unit_cost,
                'cost' => round((float) $c->quantity * ($c->per === 'pallet' ? $costing['pallets'] : $costing['cartons']) * (float) $c->unit_cost, 2),
            ])->values()->all(),
            'lots' => $lots,
            'available_lots' => $available,
            'suggested_lots' => $sheet->status === 'open' && $lots === [] ? $this->sheets->suggestLots($sheet) : [],
            'costing' => $costing,
        ], $day);
    }

    public function update(UpdateWorkSheetRequest $request, WorkSheet $sheet): RedirectResponse
    {
        try {
            $sheet = $this->sheets->save($sheet, $request->validated());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $sheet->cost_per_kg !== null
            ? sprintf('Foglio %d salvato: costo di lavorazione %s €/kg.', $sheet->number, number_format($sheet->cost_per_kg, 3, ',', '.'))
            : sprintf('Foglio %d salvato.', $sheet->number));
    }

    public function close(WorkSheet $sheet): RedirectResponse
    {
        try {
            $sheet = $this->sheets->close($sheet);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', sprintf(
            'Foglio %d chiuso: %s kg confezionati, costo di lavorazione %s €/kg.',
            $sheet->number, number_format($sheet->kg_packed, 0, ',', '.'), number_format($sheet->cost_per_kg, 3, ',', '.'),
        ));
    }

    private function summary(WorkSheet $sheet): array
    {
        return [
            'id' => $sheet->id,
            'number' => $sheet->number,
            'day' => CarbonImmutable::parse($sheet->day)->toDateString(),
            'status' => $sheet->status,
            'line' => $sheet->line?->name,
            'line_code' => $sheet->line?->code,
            'item_code' => $sheet->item?->code,
            'item' => $sheet->item?->description,
            'variety' => $sheet->item?->variety,
            'start' => $sheet->started_at->format('H:i'),
            'end' => $sheet->ended_at->format('H:i'),
            'hours' => round($sheet->started_at->diffInMinutes($sheet->ended_at) / 60, 2),
            'pallets' => (int) $sheet->pallets,
            'people' => $sheet->people_count ?? $sheet->people->count(),
            'customers' => $sheet->orders->map(fn ($o) => $o->customer?->name)->filter()->unique()->values()->all(),
            'kg_in' => $sheet->kg_in,
            'kg_packed' => $sheet->kg_packed,
            'yield' => CostCalculator::yield((float) $sheet->kg_packed, (float) $sheet->kg_in),
            'labour_cost' => $sheet->labour_cost,
            'packaging_cost' => $sheet->packaging_cost,
            'cost_per_kg' => $sheet->cost_per_kg,
        ];
    }
}
