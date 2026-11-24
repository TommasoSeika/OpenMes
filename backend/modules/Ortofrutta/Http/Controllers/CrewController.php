<?php

namespace Modules\Ortofrutta\Http\Controllers;

use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Ortofrutta\Http\Requests\CrewRequest;
use Modules\Ortofrutta\Models\Line;
use Modules\Ortofrutta\Models\Person;
use Modules\Ortofrutta\Services\CrewService;
use Modules\Ortofrutta\Services\PlanService;
use Modules\Ortofrutta\Support\Demo;

/** Screen 3 — crews: required, assigned and missing people per line. */
class CrewController extends OrtoController
{
    public function __construct(private CrewService $crews) {}

    public function index(Request $request, PlanService $plan): Response
    {
        $day = $this->day($request);
        if (! Demo::loaded()) {
            return $this->page('Crews', ['crews' => null, 'load' => []], $day);
        }

        // What each line has to run today, so the crew is read against the work.
        $load = [];
        foreach ($plan->board($day)['lines'] as $line) {
            $load[$line['id']] = ['blocks' => count($line['blocks']), 'pallets' => $line['pallets'], 'end' => $line['end']];
        }

        return $this->page('Crews', ['crews' => $this->crews->board($day), 'load' => $load], $day);
    }

    public function assign(CrewRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $person = Person::query()->findOrFail($data['person_id']);
        $line = Line::query()->findOrFail($data['line_id'] ?? 0);

        try {
            $this->crews->assign(Demo::day(), $line, $person);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', sprintf('%s assegnato a %s.', $person->name, $line->name));
    }

    public function unassign(CrewRequest $request): RedirectResponse
    {
        $person = Person::query()->findOrFail($request->validated('person_id'));
        $this->crews->unassign(Demo::day(), $person);

        return back()->with('success', sprintf('%s tolto dalla linea.', $person->name));
    }

    public function propose(): RedirectResponse
    {
        $missing = array_filter($this->crews->propose(Demo::day()));
        if ($missing === []) {
            return back()->with('success', 'Squadre proposte: tutte le linee sono al completo.');
        }

        $names = Line::query()->whereIn('code', array_keys($missing))->pluck('name', 'code');
        $parts = [];
        foreach ($missing as $code => $count) {
            $parts[] = sprintf('%s (mancano %d)', $names[$code] ?? $code, $count);
        }

        return back()->with('error', 'Squadre proposte, ma non bastano i presenti abilitati: '.implode(', ', $parts).'.');
    }
}
