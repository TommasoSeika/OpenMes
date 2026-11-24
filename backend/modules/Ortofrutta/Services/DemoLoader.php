<?php

namespace Modules\Ortofrutta\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Ortofrutta\Domain\WorkSheetCosting;
use Modules\Ortofrutta\Models\Line;
use Modules\Ortofrutta\Support\Demo;
use RuntimeException;
use Throwable;

/**
 * Reloads the simulated data from the CSV files in seed/.
 *
 * One file per real source (order system, shift app, time clock, sizers), in
 * the shape an export of that source would have, so moving to real data means
 * replacing files. Everything is rebuilt from scratch on every run: the same
 * files always give the same demo.
 */
class DemoLoader
{
    private const TABLES = [
        'orto_price_list', 'orto_orders', 'orto_work_sheet_lots', 'orto_work_sheet_people', 'orto_work_sheets',
        'orto_crew_assignments', 'orto_attendances', 'orto_people', 'orto_lots', 'orto_changeover_rules',
        'orto_packaging_components', 'orto_items', 'orto_producers', 'orto_customers', 'orto_cells',
        'orto_line_capacities', 'orto_lines',
    ];

    private const ORDER_TYPES = ['A per A' => 'axa', 'A per B' => 'axb', 'Scorta' => 'stock'];

    private const CHANGEOVER_TYPES = ['varieta' => 'variety', 'formato' => 'format', 'imballo' => 'packaging'];

    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? dirname(__DIR__).'/seed';
    }

    /**
     * @return array<string, int> what was loaded, for the command and the flash message
     */
    public function load(?string $company = null): array
    {
        $counts = DB::transaction(function () {
            foreach (self::TABLES as $table) {
                DB::table($table)->delete();
            }

            $now = now();
            $stamp = ['created_at' => $now, 'updated_at' => $now];

            // ── Master data ─────────────────────────────────────────────────
            $customers = $this->insertKeyed('orto_customers', array_map(fn ($r) => [
                'code' => $r['codice'], 'name' => $r['nome'], 'channel' => $r['canale'],
            ] + $stamp, $this->rows('clienti.csv')));

            $producers = $this->insertKeyed('orto_producers', array_map(fn ($r) => [
                'code' => $r['codice'], 'name' => $r['ragione_sociale'], 'locality' => $r['localita'],
                'is_member' => $r['socio'] === 'si',
            ] + $stamp, $this->rows('produttori.csv')));

            $cells = $this->insertKeyed('orto_cells', array_map(fn ($r) => [
                'code' => $r['codice'], 'building' => $r['edificio'], 'block' => $r['blocco'], 'row' => (int) $r['riga'],
                'col' => (int) $r['colonna'], 'capacity_bins' => (int) $r['capacita_bins'],
                'temperature_c' => (float) $r['temperatura_c'],
            ] + $stamp, $this->rows('celle.csv')));

            $lineRows = [];
            $capacities = [];
            foreach ($this->rows('linee.csv') as $r) {
                $lineRows[$r['codice']] ??= [
                    'code' => $r['codice'], 'name' => $r['nome'], 'description' => $r['descrizione'],
                    'building' => $r['edificio'], 'required_people' => (int) $r['persone_richieste'],
                    'startup_minutes' => (int) $r['minuti_avvio'], 'sort' => count($lineRows),
                ] + $stamp;
                $capacities[] = [$r['codice'], $r['formato'], (float) $r['pedane_ora']];
            }
            $lines = $this->insertKeyed('orto_lines', array_values($lineRows));
            DB::table('orto_line_capacities')->insert(array_map(fn ($c) => [
                'line_id' => $lines[$c[0]], 'format' => $c[1], 'pallets_per_hour' => $c[2],
            ], $capacities));

            $itemRows = [];
            $components = [];
            foreach ($this->rows('articoli_imballi.csv') as $r) {
                $itemRows[$r['articolo']] ??= [
                    'code' => $r['articolo'], 'description' => $r['descrizione'], 'variety' => $r['varieta'],
                    'size' => $r['calibro'], 'format' => $r['formato'], 'packaging' => $r['imballo'],
                    'customer_id' => $r['cliente'] !== '' ? $customers[$r['cliente']] : null,
                    'kg_per_carton' => (float) $r['kg_per_collo'], 'cartons_per_pallet' => (int) $r['colli_per_pedana'],
                ] + $stamp;
                $components[] = $r;
            }
            $items = $this->insertKeyed('orto_items', array_values($itemRows));
            DB::table('orto_packaging_components')->insert(array_map(fn ($r) => [
                'item_id' => $items[$r['articolo']], 'component' => $r['componente'], 'quantity' => (float) $r['quantita'],
                'per' => $r['riferimento'] === 'pedana' ? 'pallet' : 'carton', 'unit_cost' => (float) $r['costo_unitario'],
            ], $components));

            DB::table('orto_changeover_rules')->insert(array_map(fn ($r) => [
                'type' => self::CHANGEOVER_TYPES[$r['tipo']] ?? $r['tipo'],
                'from_item_id' => $r['da_articolo'] !== '' ? $items[$r['da_articolo']] : null,
                'to_item_id' => $r['a_articolo'] !== '' ? $items[$r['a_articolo']] : null,
                'minutes' => (int) $r['minuti'],
            ], $this->rows('regole_cambio.csv')));

            DB::table('orto_price_list')->insert(array_map(fn ($r) => [
                'customer_id' => $customers[$r['cliente']], 'item_id' => $items[$r['articolo']], 'week' => $r['settimana'],
                'price_kg' => (float) $r['prezzo_kg'], 'discount_pct' => (float) $r['sconto_pct'],
            ], $this->rows('listino_vendita.csv')));

            // ── Deliveries weighed at the gate: the lots in the cells ──────
            $lotRows = [];
            foreach ($this->rows('conferimenti_pesa.csv') as $r) {
                $lotRows[] = [
                    'code' => $r['lotto'], 'producer_id' => $producers[$r['produttore']], 'variety' => $r['varieta'],
                    'received_at' => $r['data'].' '.$r['ora'].':00', 'gross_kg' => (float) $r['peso_lordo_kg'],
                    'tare_kg' => (float) $r['tara_kg'], 'net_kg' => (float) $r['peso_netto_kg'], 'bins' => (int) $r['bins'],
                    'cell_id' => $cells[$r['cella']] ?? null, 'purchase_price_kg' => (float) $r['prezzo_acquisto_kg'],
                ] + $stamp;
            }
            $lots = $this->insertKeyed('orto_lots', $lotRows);

            // ── People, attendance (shift app) and clockings ────────────────
            $hourly = [];
            foreach ($this->rows('costi_orari.csv') as $r) {
                $hourly[$r['qualifica']] = (float) $r['costo_orario'];
            }
            $clockings = [];
            foreach ($this->rows('timbrature.csv') as $r) {
                $clockings[$r['matricola'].'|'.$r['giorno']] = [$r['entrata'] ?: null, $r['uscita'] ?: null];
            }
            $personRows = [];
            $attendanceRows = [];
            foreach ($this->rows('presenze_app_turni.csv') as $r) {
                $personRows[$r['matricola']] ??= [
                    'badge' => $r['matricola'], 'name' => $r['nome'], 'group_name' => $r['gruppo'],
                    'qualification' => $r['qualifica'], 'hourly_cost' => $hourly[$r['qualifica']] ?? 0,
                    'enabled_lines' => json_encode($r['linee_abilitate'] === '' ? [] : explode('|', $r['linee_abilitate'])),
                ] + $stamp;
                $attendanceRows[] = $r;
            }
            $people = $this->insertKeyed('orto_people', array_values($personRows), 'badge');
            $presence = [];
            $rows = [];
            foreach ($attendanceRows as $r) {
                $status = match ($r['stato']) {
                    'Presente' => 'present',
                    'Uscita anticipata' => 'early_exit',
                    default => 'absent',
                };
                [$in, $out] = $clockings[$r['matricola'].'|'.$r['giorno']] ?? [null, null];
                $rows[] = [
                    'person_id' => $people[$r['matricola']], 'day' => $r['giorno'], 'status' => $status,
                    'reason' => $status === 'absent' ? $r['stato'] : null,
                    'planned_in' => $r['entrata'] ?: null, 'planned_out' => $r['uscita'] ?: null,
                    'clock_in' => $in, 'clock_out' => $out,
                ];
                $presence[$r['matricola'].'|'.$r['giorno']] = [$in ?: ($r['entrata'] ?: null), $out ?: ($r['uscita'] ?: null)];
            }
            foreach (array_chunk($rows, 400) as $chunk) {
                DB::table('orto_attendances')->insert($chunk);
            }

            // ── Orders ──────────────────────────────────────────────────────
            $orderRows = [];
            $demoDay = '';
            foreach ($this->rows('ordini_gestionale.csv') as $r) {
                $demoDay = max($demoDay, $r['data']);
                $orderRows[] = $r;
            }
            $rows = [];
            foreach ($orderRows as $r) {
                $type = self::ORDER_TYPES[$r['tipo']] ?? 'axa';
                $rows[] = [
                    'number' => $r['numero'], 'day' => $r['data'], 'customer_id' => $customers[$r['cliente']],
                    'item_id' => $items[$r['articolo']], 'pallets' => (int) $r['pedane'], 'type' => $type,
                    'arrived_at' => $r['arrivo'].':00', 'truck_at' => $r['camion'] !== '' ? $r['camion'].':00' : null,
                    'priority' => (int) $r['priorita'], 'notes' => $r['note'] ?: null,
                    // On the demo day only the orders that came in the evening before are there at 10:00.
                    'released' => $r['data'] < $demoDay || $type !== 'axa',
                ] + $stamp;
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('orto_orders')->insert($chunk);
            }
            $orders = DB::table('orto_orders')->pluck('id', 'number')->all();

            // ── History: crews, work sheets and what the sizers measured ────
            $crews = [];
            $rows = [];
            foreach ($this->rows('squadre_storico.csv') as $r) {
                $crews[$r['data'].'|'.$r['linea']][] = $r['matricola'];
                $rows[] = ['day' => $r['data'], 'line_id' => $lines[$r['linea']], 'person_id' => $people[$r['matricola']]];
            }
            foreach (array_chunk($rows, 400) as $chunk) {
                DB::table('orto_crew_assignments')->insert($chunk);
            }

            $measured = [];
            foreach (glob($this->dir.'/calibratrice_*.csv') ?: [] as $file) {
                foreach ($this->rows(basename($file)) as $r) {
                    $measured[(int) $r['foglio']][] = [
                        'lot_id' => $lots[$r['lotto']], 'kg_in' => (float) $r['kg_entrati'],
                        'kg_packed' => (float) $r['kg_confezionati'], 'kg_waste_cider' => (float) $r['kg_scarto_sidro'],
                        'kg_waste_industry' => (float) $r['kg_scarto_industria'],
                    ];
                }
            }

            $itemMeta = DB::table('orto_items')->get()->keyBy('id');
            $bom = DB::table('orto_packaging_components')->get()->groupBy('item_id')
                ->map(fn ($g) => $g->map(fn ($c) => ['quantity' => (float) $c->quantity, 'per' => $c->per, 'unit_cost' => (float) $c->unit_cost])->all());
            $costOf = DB::table('orto_people')->pluck('hourly_cost', 'id');

            $position = [];
            $sheetPeople = [];
            $sheetLots = [];
            $sheets = 0;
            foreach ($this->rows('fogli_storico.csv') as $r) {
                $number = (int) $r['numero'];
                $itemId = $items[$r['articolo']];
                $start = CarbonImmutable::parse($r['data'].' '.$r['ora_inizio']);
                $end = CarbonImmutable::parse($r['data'].' '.$r['ora_fine']);

                $crew = [];
                foreach ($crews[$r['data'].'|'.$r['linea']] ?? [] as $badge) {
                    [$in, $out] = $presence[$badge.'|'.$r['data']] ?? [null, null];
                    $crew[] = [
                        'person_id' => $people[$badge],
                        'hours' => WorkSheetCosting::hoursOnSheet(
                            $start, $end,
                            $in ? CarbonImmutable::parse($r['data'].' '.$in) : null,
                            $out ? CarbonImmutable::parse($r['data'].' '.$out) : null,
                        ),
                        'hourly_cost' => (float) $costOf[$people[$badge]],
                    ];
                }
                $lotRowsOfSheet = $measured[$number] ?? [];
                $costing = WorkSheetCosting::compute(
                    $crew, $lotRowsOfSheet, (float) $itemMeta[$itemId]->kg_per_carton,
                    (int) $itemMeta[$itemId]->cartons_per_pallet, $bom[$itemId] ?? [],
                );

                $sheetId = DB::table('orto_work_sheets')->insertGetId([
                    'number' => $number, 'day' => $r['data'], 'line_id' => $lines[$r['linea']], 'item_id' => $itemId,
                    'started_at' => $start, 'ended_at' => $end, 'pallets' => (int) $r['pedane'], 'status' => 'closed',
                    'kg_in' => $costing['kg_in'], 'kg_packed' => $costing['kg_packed'],
                    'kg_waste_cider' => $costing['kg_waste_cider'], 'kg_waste_industry' => $costing['kg_waste_industry'],
                    'labour_cost' => round($costing['labour_cost'], 2), 'packaging_cost' => round($costing['packaging_cost'], 2),
                    'cost_per_kg' => $costing['cost_per_kg'] !== null ? round($costing['cost_per_kg'], 4) : null,
                    'closed_at' => $end,
                ] + $stamp);
                $sheets++;

                foreach ($crew as $person) {
                    $sheetPeople[] = ['work_sheet_id' => $sheetId] + $person;
                }
                foreach ($lotRowsOfSheet as $lot) {
                    $sheetLots[] = ['work_sheet_id' => $sheetId] + $lot;
                }
                foreach (array_filter(explode('|', $r['ordini'])) as $orderNumber) {
                    $key = $r['data'].'|'.$r['linea'];
                    DB::table('orto_orders')->where('id', $orders[$orderNumber])->update([
                        'line_id' => $lines[$r['linea']], 'position' => $position[$key] ?? 0, 'work_sheet_id' => $sheetId,
                    ]);
                    $position[$key] = ($position[$key] ?? 0) + 1;
                }
            }
            foreach (array_chunk($sheetPeople, 500) as $chunk) {
                DB::table('orto_work_sheet_people')->insert($chunk);
            }
            foreach (array_chunk($sheetLots, 500) as $chunk) {
                DB::table('orto_work_sheet_lots')->insert($chunk);
            }

            Demo::set('demo_day', $demoDay);
            Demo::set('demo_clock', Demo::CLOCK_START);

            return [
                'lines' => count($lines), 'cells' => count($cells), 'producers' => count($producers), 'lots' => count($lots),
                'items' => count($items), 'people' => count($people), 'orders' => count($orders), 'sheets' => $sheets,
            ];
        });

        if ($company !== null && trim($company) !== '') {
            Demo::set('company_name', trim($company));
        }

        $this->linkCoreLines();

        // At 10:00 the orders that came in the evening before are already in sequence.
        app(PlanService::class)->propose(Demo::day());

        return $counts;
    }

    /**
     * Mirror the lines into core production lines, so they also appear in the
     * rest of OpenMES. Optional: the module works without it, so a failure
     * here (a core change, a tenant rule) is not allowed to break the load.
     */
    private function linkCoreLines(): void
    {
        try {
            foreach (Line::query()->get() as $line) {
                $core = \App\Models\Line::withTrashed()->firstOrCreate(
                    ['code' => 'ORTO-'.$line->code],
                    ['name' => trim($line->name.' · '.$line->description, ' ·'), 'is_active' => true],
                );
                if ($core->trashed()) {
                    $core->restore();
                }
                $line->update(['core_line_id' => $core->id]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Rows of a semicolon-separated file as arrays keyed by the header.
     *
     * @return list<array<string, string>>
     */
    private function rows(string $file): array
    {
        $path = $this->dir.'/'.$file;
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("File dati mancante: {$file}");
        }

        $header = fgetcsv($handle, null, ';', '"', '');
        $rows = [];
        while (($line = fgetcsv($handle, null, ';', '"', '')) !== false) {
            if ($line === [null] || count($line) !== count($header)) {
                continue;
            }
            $rows[] = array_combine($header, array_map(fn ($v) => trim((string) $v), $line));
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Insert rows and return their ids keyed by a column.
     *
     * @return array<string, int>
     */
    private function insertKeyed(string $table, array $rows, string $key = 'code'): array
    {
        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table($table)->insert($chunk);
        }

        return DB::table($table)->pluck('id', $key)->all();
    }
}
