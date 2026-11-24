<?php

namespace Modules\Ortofrutta\Support;

use Carbon\CarbonImmutable;
use Modules\Ortofrutta\Models\Setting;

/**
 * The state of the demo: whose plant it is, which day is "today" and what time
 * it is there. The data is simulated, so the clock is too: it starts at 10:00
 * and moves when new orders are simulated, whatever the real time is.
 */
final class Demo
{
    public const DEFAULT_COMPANY = 'Azienda Demo';

    public const DAY_START = '07:30';

    public const CLOCK_START = '10:00';

    public static function get(string $key, ?string $default = null): ?string
    {
        return Setting::query()->find($key)?->value ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** Shown in the header of every screen. Kept in the database, not in the code. */
    public static function company(): string
    {
        return self::get('company_name') ?: self::DEFAULT_COMPANY;
    }

    /** The day the demo plays: the last day that has orders. */
    public static function day(): CarbonImmutable
    {
        $day = self::get('demo_day');

        return $day ? CarbonImmutable::parse($day)->startOfDay() : CarbonImmutable::today();
    }

    public static function clock(): string
    {
        return self::get('demo_clock') ?: self::CLOCK_START;
    }

    public static function now(): CarbonImmutable
    {
        return self::at(self::day(), self::clock());
    }

    /** When the lines start on a given day. */
    public static function dayStart(CarbonImmutable $day): CarbonImmutable
    {
        return self::at($day, self::DAY_START);
    }

    public static function at(CarbonImmutable $day, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($day->toDateString().' '.$time);
    }

    public static function loaded(): bool
    {
        return self::get('demo_day') !== null;
    }

    /**
     * What every screen needs for its header.
     *
     * @return array{company: string, day: string, day_label: string, clock: string, loaded: bool}
     */
    public static function header(): array
    {
        $day = self::day();

        return [
            'company' => self::company(),
            'day' => $day->toDateString(),
            'day_label' => $day->locale('it')->isoFormat('dddd D MMMM YYYY'),
            'clock' => self::clock(),
            'loaded' => self::loaded(),
        ];
    }
}
