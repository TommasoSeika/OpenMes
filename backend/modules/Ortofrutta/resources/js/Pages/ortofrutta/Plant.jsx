import { useState } from 'react';
import { Link } from '@inertiajs/react';
import AppLayout from '@core/layouts/AppLayout';
import Shell from '../../ortofrutta/Shell';
import { Card, Chip, Stat, Table } from '../../ortofrutta/ui';
import { BASE, kg, num, pct, plural } from '../../ortofrutta/format';

// Colours follow the plant drawing: cells orange, lines pink, packing area green, buildings blue.
const building = 'rounded-2xl border border-sky-300 bg-sky-100 p-4 dark:border-sky-800 dark:bg-sky-950/40';

function CellTile({ cell, selected, onSelect }) {
    const empty = cell.bins === 0;

    return (
        <button
            type="button"
            onClick={() => onSelect(cell.id)}
            aria-pressed={selected}
            title={empty ? `${cell.code}: vuota` : `${cell.code}: ${cell.varieties.join(', ')} · ${num(cell.bins)} bins`}
            className={`relative flex h-16 flex-col justify-between overflow-hidden rounded-lg border px-2 py-1 text-left transition-shadow ${
                empty
                    ? 'border-orange-300 bg-orange-100 text-orange-900/70 dark:border-orange-900 dark:bg-orange-950/40 dark:text-orange-200/60'
                    : 'border-orange-500 bg-orange-400 text-orange-950 dark:border-orange-600 dark:bg-orange-600 dark:text-white'
            } ${selected ? 'ring-2 ring-om-ink ring-offset-1' : 'hover:shadow-md'}`}
        >
            <span className="flex items-center justify-between text-xs font-semibold">
                {cell.code}
                {!empty && <span className="tabular-nums font-medium">{pct(cell.fill, 0)}</span>}
            </span>
            <span className="truncate text-[11px] leading-tight">{empty ? 'vuota' : cell.varieties.join(', ')}</span>
            <span className="absolute inset-x-0 bottom-0 h-1 bg-black/10" aria-hidden="true">
                <span className="block h-full bg-black/40 dark:bg-white/70" style={{ width: `${Math.round(cell.fill * 100)}%` }} />
            </span>
        </button>
    );
}

function CellGrid({ cells, selected, onSelect }) {
    const rows = [...new Set(cells.map((c) => c.row))].sort((a, b) => a - b);

    return (
        <div className="space-y-1.5">
            {rows.map((row) => (
                <div key={row} className="grid grid-cols-4 gap-1.5">
                    {cells.filter((c) => c.row === row).sort((a, b) => a.col - b.col).map((c) => (
                        <CellTile key={c.id} cell={c} selected={selected === c.id} onSelect={onSelect} />
                    ))}
                </div>
            ))}
        </div>
    );
}

function LineBar({ line }) {
    const alert = line.late_count > 0 || line.understaffed;

    return (
        <Link
            href={`${BASE}/piano`}
            className={`flex min-h-72 flex-1 flex-col gap-2 rounded-3xl border px-3 py-4 text-pink-950 transition-shadow hover:shadow-md dark:text-white ${
                alert ? 'border-red-500 bg-pink-400 ring-2 ring-red-500 dark:bg-pink-700' : 'border-pink-500 bg-pink-400 dark:border-pink-600 dark:bg-pink-700'
            }`}
        >
            <div>
                <div className="text-sm font-bold">{line.name}</div>
                <div className="text-[11px] leading-tight opacity-80">{line.description}</div>
            </div>
            <div className="flex flex-wrap gap-1">
                <Chip tone={line.understaffed ? 'bad' : 'neutral'}>{line.assigned_people}/{line.required_people} pers.</Chip>
                {line.late_count > 0 && <Chip tone="bad">{line.late_count} oltre il camion</Chip>}
            </div>
            <div className="mt-auto space-y-2 text-xs">
                <div className="rounded-lg bg-white/60 p-2 dark:bg-black/30">
                    <div className="text-[10px] font-semibold uppercase tracking-wide opacity-70">Ora</div>
                    {line.current
                        ? <><div className="font-semibold">{line.current.customer}</div><div>{line.current.variety} {line.current.size} · {line.current.pallets} ped. · fino alle {line.current.end}</div></>
                        : <div className="opacity-70">ferma</div>}
                </div>
                <div className="rounded-lg bg-white/40 p-2 dark:bg-black/20">
                    <div className="text-[10px] font-semibold uppercase tracking-wide opacity-70">Poi</div>
                    {line.next
                        ? <><div className="font-semibold">{line.next.customer}</div><div>{line.next.variety} {line.next.size} · dalle {line.next.start}</div></>
                        : <div className="opacity-70">nient'altro in piano</div>}
                </div>
                <div className="opacity-80">{plural(line.blocks, 'ordine', 'ordini')} · {plural(line.pallets, 'pedana', 'pedane')}{line.end ? ` · fine ${line.end}` : ''}</div>
            </div>
        </Link>
    );
}

export default function Plant({ cells, lines, trucks, summary }) {
    const [selected, setSelected] = useState(null);

    if (!summary) {
        return <Shell title="Stabilimento" active="">{null}</Shell>;
    }

    const building2 = cells.filter((c) => c.building === 'Edificio 2');
    const building1 = cells.filter((c) => c.building === 'Edificio 1');
    const blocks = [...new Set(building2.map((c) => c.block))];
    const cell = cells.find((c) => c.id === selected);
    const toggle = (id) => setSelected((current) => (current === id ? null : id));
    const crew = summary.crew;

    return (
        <Shell title="Stabilimento" active="">
            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-6">
                <Stat label="Frutta in cella" value={kg(summary.kg_in_cells)} hint={`${num(summary.bins_in_cells)} bins`} />
                <Stat label="Ordini arrivati" value={num(summary.orders)} hint={summary.waiting > 0 ? `${summary.waiting} in arrivo` : `${num(summary.pallets)} pedane`} />
                <Stat label="Da pianificare" value={num(summary.backlog)} tone={summary.backlog > 0 ? 'bad' : 'ok'} />
                <Stat label="Oltre il camion" value={num(summary.late)} tone={summary.late > 0 ? 'bad' : 'ok'} />
                <Stat label="Presenti" value={`${num(crew.present)} / ${num(crew.people)}`} hint={`${crew.absent} assenti · ${crew.early_exit} escono prima`} />
                <Stat label="In linea" value={`${num(crew.assigned)} / ${num(crew.required)}`} tone={crew.assigned < crew.required ? 'bad' : 'ok'} hint="assegnati / richiesti" />
            </div>

            <div className="space-y-4">
                <section className={building} aria-label="Edificio 2">
                    <h2 className="mb-3 text-sm font-semibold text-sky-950 dark:text-sky-100">Edificio 2 · celle frigo e linee di lavorazione</h2>
                    <div className="grid gap-6 lg:grid-cols-2">
                        <div className="space-y-4">
                            {blocks.map((block) => (
                                <CellGrid key={block} cells={building2.filter((c) => c.block === block)} selected={selected} onSelect={toggle} />
                            ))}
                        </div>
                        <div className="flex gap-3">
                            {lines.map((line, i) => (
                                <div key={line.id} className={`flex flex-1 ${i === 2 ? 'ml-6' : ''}`}>
                                    <LineBar line={line} />
                                </div>
                            ))}
                        </div>
                    </div>
                </section>

                <section className={building} aria-label="Edificio 1">
                    <h2 className="mb-3 text-sm font-semibold text-sky-950 dark:text-sky-100">Edificio 1 · celle frigo, confezionamento e carico</h2>
                    <div className="grid gap-6 lg:grid-cols-2">
                        <div className="space-y-4">
                            <CellGrid cells={building1} selected={selected} onSelect={toggle} />
                            <div className="rounded-3xl border border-green-600 bg-green-400 p-4 text-green-950 dark:border-green-700 dark:bg-green-700 dark:text-white">
                                <div className="text-sm font-bold">Confezionamento e carico</div>
                                <div className="mb-2 text-[11px] opacity-80">Le pedane finite aspettano qui il loro camion</div>
                                <div className="grid gap-2 sm:grid-cols-2">
                                    {trucks.map((t) => (
                                        <div key={t.time} className="rounded-lg bg-white/60 p-2 text-xs dark:bg-black/30">
                                            <div className="flex items-center justify-between">
                                                <span className="text-sm font-bold tabular-nums">Camion {t.time}</span>
                                                {t.late > 0 ? <Chip tone="bad">{t.late} a rischio</Chip> : t.unplanned > 0 ? <Chip tone="warn">{t.unplanned} da pianificare</Chip> : <Chip tone="ok">in orario</Chip>}
                                            </div>
                                            <div>{plural(t.orders, 'ordine', 'ordini')} · {plural(t.pallets, 'pedana', 'pedane')}</div>
                                            <div className="opacity-80">{t.packed} di {t.orders} già confezionati</div>
                                        </div>
                                    ))}
                                    {trucks.length === 0 && <div className="text-xs opacity-80">Nessun camion oggi.</div>}
                                </div>
                            </div>
                        </div>
                        <div className="flex flex-col justify-center gap-2 rounded-xl border border-dashed border-sky-400 p-4 text-sm text-sky-950 dark:border-sky-700 dark:text-sky-100">
                            <p className="font-medium">Il flusso</p>
                            <ol className="list-decimal space-y-1 pl-5 text-[13px]">
                                <li><Link className="underline" href={`${BASE}/conferimenti`}>Conferimento</Link>: il carico viene pesato e scaricato in cella.</li>
                                <li><Link className="underline" href={`${BASE}/ordini`}>Ordini</Link>: l'ufficio vede cosa parte e con quale camion.</li>
                                <li><Link className="underline" href={`${BASE}/piano`}>Piano linee</Link>: sequenza, cambi e avvisi sul camion.</li>
                                <li><Link className="underline" href={`${BASE}/squadre`}>Squadre</Link>: le persone presenti sulle linee.</li>
                                <li><Link className="underline" href={`${BASE}/missioni`}>Missioni bins</Link>: cosa portare, da quale cella, entro che ora.</li>
                                <li><Link className="underline" href={`${BASE}/fogli`}>Fogli</Link>: kg e scarto, costo in €/kg.</li>
                                <li><Link className="underline" href={`${BASE}/margini`}>Margini</Link>: per lotto, produttore e cliente.</li>
                            </ol>
                        </div>
                    </div>
                </section>
            </div>

            <Card title={cell ? `Cella ${cell.code} · ${cell.building}` : 'Celle frigo'} className="mt-4" aside={cell && <Chip>{num(cell.bins)} / {num(cell.capacity_bins)} bins · {cell.temperature_c} °C</Chip>}>
                {cell ? (
                    <Table
                        rows={cell.lots}
                        empty="La cella è vuota."
                        columns={[
                            { key: 'code', label: 'Lotto', render: (l) => <span className="font-medium">{l.code}</span> },
                            { key: 'producer', label: 'Produttore' },
                            { key: 'variety', label: 'Varietà' },
                            { key: 'received', label: 'Conferito il' },
                            { key: 'bins', label: 'Bins', align: 'right', render: (l) => num(l.bins) },
                            { key: 'kg', label: 'Kg in cella', align: 'right', render: (l) => kg(l.kg) },
                        ]}
                    />
                ) : (
                    <p className="text-sm text-om-muted">
                        Tocca una cella per vedere i lotti che contiene. Arancione pieno = occupata, con la percentuale di riempimento; arancione chiaro = vuota.
                        Le linee in rosa mostrano cosa stanno lavorando all'ora della demo: toccale per aprire il piano.
                    </p>
                )}
            </Card>
        </Shell>
    );
}

Plant.layout = (page) => <AppLayout>{page}</AppLayout>;
