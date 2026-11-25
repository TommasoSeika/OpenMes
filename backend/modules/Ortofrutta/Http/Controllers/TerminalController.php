<?php

namespace Modules\Ortofrutta\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Inertia\Response;
use Modules\Ortofrutta\Domain\MissionPlanner;
use Modules\Ortofrutta\Http\Requests\ScanRequest;
use Modules\Ortofrutta\Http\Requests\TerminalRequest;
use Modules\Ortofrutta\Models\Attendance;
use Modules\Ortofrutta\Models\Line;
use Modules\Ortofrutta\Models\Lot;
use Modules\Ortofrutta\Models\Mission;
use Modules\Ortofrutta\Models\Person;
use Modules\Ortofrutta\Services\CrewService;
use Modules\Ortofrutta\Services\MissionService;
use Modules\Ortofrutta\Support\Demo;
use Modules\Ortofrutta\Support\Qr;

/**
 * The terminal a person carries on the floor. A forklift driver sees their
 * missions in order and carries them out by scanning the label of the bins and
 * the code of the line; a line operator sees the productions they are on.
 *
 * In this proof of concept the camera is simulated: the labels that would be
 * in front of the driver are drawn on the screen with their real QR codes, and
 * touching one reads it. A hand-held reader works too, since it types the code
 * like a keyboard.
 */
class TerminalController extends OrtoController
{
    public function __construct(private MissionService $missions) {}

    public function index(TerminalRequest $request, CrewService $crews): Response
    {
        if (! Demo::loaded()) {
            return $this->page('Terminal', ['terminal' => null]);
        }

        $day = Demo::day();
        $asked = $request->validated();
        $in = Attendance::query()->whereDate('day', $day->toDateString())->whereIn('status', ['present', 'early_exit'])->pluck('person_id');
        $people = Person::query()->whereIn('id', $in)->orderBy('name')->get();

        $missions = Mission::query()->with(['order.customer', 'order.item', 'line', 'lot.producer', 'cell', 'person'])
            ->whereDate('day', $day->toDateString())->get();
        $open = $missions->where('status', '!=', 'delivered');

        // Whoever was asked for; else the first driver with something to do; else the first driver.
        $person = $people->firstWhere('id', (int) ($asked['persona'] ?? 0))
            ?? $people->first(fn (Person $p) => $open->contains('person_id', $p->id))
            ?? $people->firstWhere('qualification', MissionService::DRIVER)
            ?? $people->first();

        $terminal = [
            'now' => Demo::clock(),
            'people' => $people->map(fn (Person $p) => [
                'id' => $p->id, 'name' => $p->name, 'badge' => $p->badge, 'qualification' => $p->qualification,
                'role' => $this->role($p),
                'open' => $open->where('person_id', $p->id)->count(),
            ])->values()->all(),
            'person' => $person === null ? null : [
                'id' => $person->id, 'name' => $person->name, 'badge' => $person->badge,
                'qualification' => $person->qualification, 'role' => $this->role($person),
            ],
            'missions' => [],
            'active' => null,
            'labels' => [],
            'productions' => [],
        ];

        if ($person !== null && $this->role($person) === 'driver') {
            $mine = $missions->where('person_id', $person->id);
            $order = collect(MissionPlanner::order($mine->map(fn (Mission $m) => [
                'id' => $m->id, 'due' => $m->due_at->hour * 60 + $m->due_at->minute, 'priority' => $m->priority, 'line' => $m->line?->code,
            ])->values()->all()))->pluck('id');
            $sorted = $order->map(fn ($id) => $mine->firstWhere('id', $id));
            $terminal['missions'] = $sorted->map(fn (Mission $m) => $this->missions->present($m))->values()->all();

            $active = $sorted->first(fn (Mission $m) => $m->id === (int) ($asked['missione'] ?? 0) && $m->status !== 'delivered')
                ?? $sorted->first(fn (Mission $m) => $m->status === 'picked')
                ?? $sorted->first(fn (Mission $m) => $m->status === 'todo');
            if ($active !== null) {
                $terminal['active'] = $this->missions->present($active) + [
                    'step' => $active->status === 'picked' ? 'deliver' : 'pick',
                    'received' => $active->lot?->received_at?->format('d/m/Y'),
                ];
                $terminal['labels'] = $active->status === 'picked' ? $this->lineLabels() : $this->binLabels($active);
            }
        } elseif ($person !== null && $this->role($person) === 'line') {
            $board = $crews->board($day);
            $names = collect($board['people'])->keyBy('id');
            $terminal['productions'] = collect($board['productions'])
                ->filter(fn (array $p) => collect($p['crew'])->contains('id', $person->id))
                ->sortBy('start_min')->values()
                ->map(fn (array $p) => [
                    'key' => $p['key'], 'line' => $p['line'], 'start' => $p['start'], 'end' => $p['end'],
                    'item' => $p['item'], 'pallets' => $p['pallets'], 'customers' => $p['customers'],
                    'status' => $p['status'], 'sheet_number' => $p['sheet_number'], 'people' => count($p['crew']),
                    'leader' => collect($p['crew'])->map(fn ($c) => $names[$c['id']] ?? null)
                        ->first(fn ($x) => $x !== null && $x['is_leader'])['name'] ?? null,
                    'now' => $p['start'] <= Demo::clock() && Demo::clock() < $p['end'],
                ])->all();
        }

        return $this->page('Terminal', ['terminal' => $terminal]);
    }

    /** A code read for a mission: the answer is shown on the terminal itself. */
    public function scan(ScanRequest $request): JsonResponse
    {
        $data = $request->validated();
        $mission = Mission::query()->findOrFail($data['mission_id']);
        $result = $this->missions->scan($mission, $data['code']);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /** The label on the bins of a lot, as it is printed. */
    public static function binLabel(Lot $lot): array
    {
        return [
            'code' => 'LOT:'.$lot->code,
            'title' => $lot->code,
            'lines' => [$lot->variety, $lot->producer?->name, 'Conferito il '.$lot->received_at->format('d/m/Y').' · cella '.($lot->cell?->code ?? '—')],
            'qr' => Qr::dataUri('LOT:'.$lot->code),
        ];
    }

    private function role(Person $person): string
    {
        return match (true) {
            $person->qualification === MissionService::DRIVER => 'driver',
            ($person->enabled_lines ?? []) !== [] => 'line',
            default => 'other',
        };
    }

    /**
     * The labels a driver finds at the cell: the bins the mission wants, and
     * two others that are close by — same cell first, then same variety — so a
     * wrong pick can be tried.
     *
     * @return list<array<string, mixed>>
     */
    private function binLabels(Mission $mission): array
    {
        $others = Lot::query()->with(['producer', 'cell'])->where('id', '!=', $mission->lot_id)
            ->orderByRaw('case when cell_id = ? then 0 when variety = ? then 1 else 2 end', [$mission->cell_id ?? 0, $mission->lot->variety])
            ->orderBy('code')->limit(2)->get();

        // The right one is not always the first on the shelf.
        $labels = $others->map(fn (Lot $l) => self::binLabel($l))->all();
        array_splice($labels, $mission->id % (count($labels) + 1), 0, [self::binLabel($mission->lot)]);

        return $labels;
    }

    /** @return list<array<string, mixed>> */
    private function lineLabels(): array
    {
        return Line::query()->orderBy('sort')->get()->map(fn (Line $l) => [
            'code' => 'LINE:'.$l->code, 'title' => $l->name, 'lines' => [$l->description], 'qr' => Qr::dataUri('LINE:'.$l->code),
        ])->all();
    }
}
