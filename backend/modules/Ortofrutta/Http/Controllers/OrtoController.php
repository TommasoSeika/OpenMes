<?php

namespace Modules\Ortofrutta\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Ortofrutta\Models\Order;
use Modules\Ortofrutta\Support\Demo;
use Throwable;

/**
 * What every screen of the module shares: the header (company, demo day and
 * clock, the "simulated data" label) and the day being looked at.
 */
abstract class OrtoController extends Controller
{
    protected function page(string $component, array $props = [], ?CarbonImmutable $day = null): Response
    {
        return Inertia::render('ortofrutta/'.$component, ['orto' => $this->shell($day)] + $props);
    }

    /** The day in the address (?giorno=2026-11-26), or the demo day. */
    protected function day(Request $request): CarbonImmutable
    {
        $demo = Demo::day();
        $asked = $request->query('giorno');
        if (! is_string($asked) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $asked)) {
            return $demo;
        }

        try {
            $day = CarbonImmutable::parse($asked)->startOfDay();
        } catch (Throwable) {
            return $demo;
        }

        return $day->greaterThan($demo) ? $demo : $day;
    }

    private function shell(?CarbonImmutable $day): array
    {
        $demo = Demo::day();
        $day ??= $demo;

        $days = Demo::loaded()
            ? Order::query()->selectRaw('day')->distinct()->orderByDesc('day')->pluck('day')
                ->map(fn ($d) => CarbonImmutable::parse($d))
                ->map(fn (CarbonImmutable $d) => [
                    'value' => $d->toDateString(),
                    'label' => $d->locale('it')->isoFormat('ddd D MMM').($d->equalTo($demo) ? ' · oggi' : ''),
                ])->values()->all()
            : [];

        return Demo::header() + [
            'viewing' => $day->toDateString(),
            'viewing_label' => $day->locale('it')->isoFormat('dddd D MMMM'),
            'is_demo_day' => $day->equalTo($demo),
            'days' => $days,
        ];
    }
}
