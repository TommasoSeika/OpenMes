<?php

namespace Modules\Ortofrutta\Http\Controllers;

use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Ortofrutta\Http\Requests\CrewRequest;
use Modules\Ortofrutta\Http\Requests\ProductionRequest;
use Modules\Ortofrutta\Models\Person;
use Modules\Ortofrutta\Services\CrewService;
use Modules\Ortofrutta\Support\Demo;

/** Screen 3 — crews: for every production of the day, who works on it. */
class CrewController extends OrtoController
{
    public function __construct(private CrewService $crews) {}

    public function index(Request $request): Response
    {
        $day = $this->day($request);

        return $this->page('Crews', [
            'crews' => Demo::loaded() ? $this->crews->board($day) : null,
            // The production to open first (?produzione=<one of its orders>), e.g. coming from a work sheet.
            'focus' => ctype_digit((string) $request->query('produzione')) ? (int) $request->query('produzione') : null,
        ], $day);
    }

    public function assign(CrewRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $person = Person::query()->findOrFail($data['person_id']);

        try {
            $production = $this->crews->assign(Demo::day(), (int) $data['production'], $person);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', sprintf('%s assegnato a %s.', $person->name, $this->crews->label($production)));
    }

    public function unassign(CrewRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $person = Person::query()->findOrFail($data['person_id']);

        try {
            $production = $this->crews->unassign(Demo::day(), (int) $data['production'], $person);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', sprintf('%s tolto da %s.', $person->name, $this->crews->label($production)));
    }

    public function copy(ProductionRequest $request): RedirectResponse
    {
        try {
            $result = $this->crews->copyPrevious(Demo::day(), (int) $request->validated('production'));
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $label = $this->crews->label($result['production']);
        if ($result['added'] === 0 && $result['left'] === 0) {
            return back()->with('success', sprintf('%s ha già la squadra della produzione precedente.', $label));
        }

        return back()->with($result['left'] > 0 ? 'error' : 'success', sprintf(
            '%s: %s dalla produzione precedente%s.',
            $label,
            $result['added'] === 1 ? 'una persona portata' : $result['added'].' persone portate',
            $result['left'] > 0 ? sprintf(', %d non disponibili in quell\'orario', $result['left']) : '',
        ));
    }

    public function clear(ProductionRequest $request): RedirectResponse
    {
        try {
            $production = $this->crews->clear(Demo::day(), (int) $request->validated('production'));
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', sprintf('Squadra di %s svuotata.', $this->crews->label($production)));
    }

    public function propose(): RedirectResponse
    {
        $short = $this->crews->propose(Demo::day());
        if ($short === []) {
            return back()->with('success', 'Squadre proposte: ogni produzione ha le persone che servono.');
        }

        $parts = array_map(fn (array $p) => sprintf('%s (mancano %d)', $p['label'], $p['missing']), array_slice($short, 0, 6));

        return back()->with('error', sprintf(
            'Squadre proposte, ma per %s non bastano i presenti abilitati: %s%s.',
            count($short) === 1 ? 'una produzione' : count($short).' produzioni',
            implode(', ', $parts), count($short) > 6 ? '…' : '',
        ));
    }
}
