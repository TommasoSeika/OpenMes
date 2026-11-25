<?php

namespace Modules\Ortofrutta\Http\Controllers;

use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Ortofrutta\Http\Requests\AssignMissionRequest;
use Modules\Ortofrutta\Models\Cell;
use Modules\Ortofrutta\Models\Line;
use Modules\Ortofrutta\Models\Lot;
use Modules\Ortofrutta\Models\Mission;
use Modules\Ortofrutta\Models\Person;
use Modules\Ortofrutta\Services\MissionService;
use Modules\Ortofrutta\Services\WorkSheetService;
use Modules\Ortofrutta\Support\Demo;
use Modules\Ortofrutta\Support\Qr;

/**
 * The work lists of the forklift drivers: generated from the plan, shared
 * among the drivers who are in, followed while they are carried out.
 */
class MissionController extends OrtoController
{
    public function __construct(private MissionService $missions) {}

    public function index(Request $request): Response
    {
        $day = $this->day($request);

        return $this->page('Missions', [
            'board' => Demo::loaded() ? $this->missions->board($day) : null,
        ], $day);
    }

    public function generate(): RedirectResponse
    {
        $result = $this->missions->generate(Demo::day());
        if ($result['created'] === 0) {
            return back()->with('success', 'Liste di lavoro aggiornate: non ci sono altri bins da portare alle linee.');
        }

        $text = sprintf(
            'Liste di lavoro generate: %d missioni da fare, divise tra %d carrellisti presenti.',
            $result['created'], $result['drivers'],
        );

        return $result['short'] > 0
            ? back()->with('error', $text.sprintf(' Per %d ordini non c\'è abbastanza frutta in cella.', $result['short']))
            : back()->with('success', $text);
    }

    public function assign(AssignMissionRequest $request, Mission $mission): RedirectResponse
    {
        $person = Person::query()->findOrFail($request->validated('person_id'));

        try {
            $this->missions->assign($mission, $person);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', sprintf('Missione %s passata a %s.', $mission->code(), $person->name));
    }

    /** The labels to print: one code per line, per cell and per lot in the cells. */
    public function labels(WorkSheetService $sheets): Response
    {
        if (! Demo::loaded()) {
            return $this->page('Labels', ['lines' => [], 'cells' => [], 'lots' => []]);
        }

        $remaining = $sheets->remainingByLot();

        return $this->page('Labels', [
            'lines' => Line::query()->orderBy('sort')->get()->map(fn (Line $l) => [
                'code' => 'LINE:'.$l->code, 'title' => $l->name, 'lines' => [$l->description, $l->building],
                'qr' => Qr::dataUri('LINE:'.$l->code),
            ])->all(),
            'cells' => Cell::query()->orderBy('code')->get()->map(fn (Cell $c) => [
                'code' => 'CELL:'.$c->code, 'title' => 'Cella '.$c->code, 'lines' => [$c->building, $c->block],
                'qr' => Qr::dataUri('CELL:'.$c->code),
            ])->all(),
            'lots' => Lot::query()->with(['producer', 'cell'])->orderBy('code')->get()
                ->filter(fn (Lot $l) => ($remaining[$l->id] ?? 0) >= 1)
                ->map(fn (Lot $l) => TerminalController::binLabel($l))->values()->all(),
        ]);
    }
}
