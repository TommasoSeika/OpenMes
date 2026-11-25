import { useEffect, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import AppLayout from '@core/layouts/AppLayout';
import Shell from '../../ortofrutta/Shell';
import { Btn, Card, Chip, Stat, Table, inputClass } from '../../ortofrutta/ui';
import { BASE, ORDER_TYPES, eur, eurKg, kg, num, pct } from '../../ortofrutta/format';

const toRow = (lot) => ({
    lot_id: lot.lot_id,
    kg_in: lot.kg_in ?? '',
    kg_waste_cider: lot.kg_waste_cider ?? 0,
    kg_waste_industry: lot.kg_waste_industry ?? 0,
    kg_packed: lot.kg_packed ?? lot.kg_in ?? '',
    // Until someone types the packed kilograms, they follow from what went in minus the waste.
    manual: lot.kg_packed !== undefined && lot.kg_packed !== null
        && Math.abs(Number(lot.kg_packed) - (Number(lot.kg_in) - Number(lot.kg_waste_cider) - Number(lot.kg_waste_industry))) > 0.5,
});

const n = (v) => (v === '' || v === null || v === undefined ? 0 : Number(v));

export default function WorkSheet({ sheet, crew, components, lots, available_lots: available, suggested_lots: suggested, costing }) {
    const { errors = {} } = usePage().props;
    const open = sheet.status === 'open';
    const [rows, setRows] = useState(lots.map(toRow));
    const [times, setTimes] = useState({ started_at: sheet.start, ended_at: sheet.end });
    const [dirty, setDirty] = useState(false);

    // After a save the page comes back with the stored rows: start again from them.
    useEffect(() => {
        setRows(lots.map(toRow));
        setTimes({ started_at: sheet.start, ended_at: sheet.end });
        setDirty(false);
    }, [JSON.stringify(lots), sheet.start, sheet.end]);

    const lotById = Object.fromEntries(available.map((l) => [l.lot_id, l]));

    const change = (index, field, value) => {
        setDirty(true);
        setRows((current) => current.map((row, i) => {
            if (i !== index) {
                return row;
            }
            const next = { ...row, [field]: value };
            if (field === 'kg_packed') {
                next.manual = true;
            } else if (!next.manual && field !== 'lot_id') {
                next.kg_packed = Math.max(0, n(next.kg_in) - n(next.kg_waste_cider) - n(next.kg_waste_industry));
            }

            return next;
        }));
    };

    const addRow = () => {
        const used = new Set(rows.map((r) => Number(r.lot_id)));
        const free = available.find((l) => !used.has(l.lot_id));
        setDirty(true);
        setRows([...rows, { lot_id: free?.lot_id ?? '', kg_in: '', kg_waste_cider: 0, kg_waste_industry: 0, kg_packed: '', manual: false }]);
    };

    const useSuggested = () => {
        setDirty(true);
        setRows(suggested.map((l) => ({ lot_id: l.lot_id, kg_in: l.kg_in, kg_waste_cider: 0, kg_waste_industry: 0, kg_packed: l.kg_in, manual: false })));
    };

    const save = () => router.put(`${BASE}/fogli/${sheet.id}`, {
        ...times,
        lots: rows.filter((r) => r.lot_id !== '').map((r) => ({
            lot_id: Number(r.lot_id), kg_in: n(r.kg_in), kg_packed: n(r.kg_packed),
            kg_waste_cider: n(r.kg_waste_cider), kg_waste_industry: n(r.kg_waste_industry),
        })),
    }, { preserveScroll: true });

    const close = () => {
        if (window.confirm(`Chiudere il foglio ${sheet.number}? Dopo la chiusura non si può più modificare.`)) {
            router.post(`${BASE}/fogli/${sheet.id}/chiudi`, {}, { preserveScroll: true });
        }
    };

    const totals = rows.reduce((t, r) => ({
        in: t.in + n(r.kg_in), packed: t.packed + n(r.kg_packed), cider: t.cider + n(r.kg_waste_cider), industry: t.industry + n(r.kg_waste_industry),
    }), { in: 0, packed: 0, cider: 0, industry: 0 });
    const labour = crew.reduce((s, p) => s + p.cost, 0);
    const errorList = Object.values(errors);

    const number = (index, field, row, label) => (
        <input
            type="number" min="0" step="1" inputMode="decimal" aria-label={label}
            value={row[field]} disabled={!open}
            onChange={(e) => change(index, field, e.target.value)}
            className={`${inputClass} w-28 text-right tabular-nums`}
        />
    );

    return (
        <Shell
            title={`Foglio ${sheet.number}`}
            active="/fogli"
            actions={open && (
                <>
                    <Btn variant="primary" onClick={save}>Salva e calcola il costo</Btn>
                    <Btn onClick={close} disabled={dirty || !(costing.kg_packed > 0)} title={dirty ? 'Salva prima le modifiche' : 'Chiude il foglio: non sarà più modificabile'}>Chiudi foglio</Btn>
                </>
            )}
        >
            <p className="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                <Link href={`${BASE}/fogli?giorno=${sheet.day}`} className="text-om-accent hover:underline">← Fogli della giornata</Link>
                <span className="text-om-muted">
                    Lotto di confezionamento <span className="font-semibold tabular-nums text-om-ink">{sheet.packing_lot}</span>
                </span>
                <Link href={`${BASE}/tracciabilita?foglio=${sheet.id}`} className="text-om-accent hover:underline">Tracciabilità: da dove viene, a chi va →</Link>
            </p>

            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
                <Stat label="Costo di lavorazione" value={eurKg(costing.cost_per_kg)} hint={costing.cost_per_kg === null ? 'appare al salvataggio dei kg' : 'manodopera + imballi'} tone={costing.cost_per_kg !== null ? 'ok' : undefined} />
                <Stat label="Manodopera" value={eurKg(costing.labour_per_kg)} hint={eur(costing.labour_cost)} />
                <Stat label="Imballi" value={eurKg(costing.packaging_per_kg)} hint={eur(costing.packaging_cost)} />
                <Stat label="Resa" value={pct(costing.yield)} hint={`${kg(costing.kg_packed)} su ${kg(costing.kg_in)}`} />
                <Stat label="Prodotto" value={`${num(costing.pallets, 1)} pedane`} hint={`${num(costing.cartons)} colli · previste ${num(sheet.pallets)}`} />
            </div>

            {errorList.length > 0 && (
                <ul className="mb-4 rounded-lg border border-red-300 bg-red-50 px-4 py-2 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200">
                    {errorList.map((message, i) => <li key={i}>{message}</li>)}
                </ul>
            )}

            <div className="grid gap-4 xl:grid-cols-3">
                <Card title="Lancio" aside={<Chip tone={open ? 'info' : 'ok'}>{open ? 'Aperto' : 'Chiuso'}</Chip>}>
                    <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
                        <dt className="text-om-muted">Linea</dt><dd className="text-om-ink">{sheet.line}</dd>
                        <dt className="text-om-muted">Lotto</dt><dd className="tabular-nums text-om-ink">{sheet.packing_lot}</dd>
                        <dt className="text-om-muted">Articolo</dt><dd className="text-om-ink">{sheet.item_code} · {sheet.item}</dd>
                        <dt className="text-om-muted">Previsto</dt><dd className="text-om-ink">{num(sheet.pallets)} pedane · {kg(sheet.planned_kg)}</dd>
                        <dt className="text-om-muted">Inizio</dt>
                        <dd>{open
                            ? <input type="time" value={times.started_at} onChange={(e) => { setDirty(true); setTimes({ ...times, started_at: e.target.value }); }} className={`${inputClass} w-32`} aria-label="Ora di inizio" />
                            : <span className="tabular-nums text-om-ink">{sheet.start}</span>}
                        </dd>
                        <dt className="text-om-muted">Fine</dt>
                        <dd>{open
                            ? <input type="time" value={times.ended_at} onChange={(e) => { setDirty(true); setTimes({ ...times, ended_at: e.target.value }); }} className={`${inputClass} w-32`} aria-label="Ora di fine" />
                            : <span className="tabular-nums text-om-ink">{sheet.end}</span>}
                        </dd>
                    </dl>
                    <h3 className="mb-1 mt-4 text-xs font-medium uppercase tracking-wide text-om-muted">Ordini nel lancio</h3>
                    <ul className="divide-y divide-om-line text-sm">
                        {sheet.orders.map((o) => (
                            <li key={o.number} className="flex items-center justify-between gap-2 py-1.5">
                                <span className="min-w-0 truncate text-om-ink">{o.customer} <span className="text-xs text-om-muted">{o.number}</span></span>
                                <span className="flex shrink-0 items-center gap-1">
                                    <Chip>{ORDER_TYPES[o.type]}</Chip>
                                    {o.truck && <Chip tone={o.truck === '15:00' ? 'info' : 'neutral'}>{o.truck}</Chip>}
                                    <span className="w-14 text-right tabular-nums text-om-ink">{o.pallets} ped.</span>
                                </span>
                            </li>
                        ))}
                    </ul>
                </Card>

                <Card title={`Squadra (${crew.length} persone)`} aside={<span className="text-sm font-medium tabular-nums text-om-ink">{eur(labour)}</span>}>
                    {open && sheet.production && (
                        <p className="mb-2 text-sm">
                            <Link href={`${BASE}/squadre?produzione=${sheet.production}`} className="text-om-accent hover:underline">Organizza la squadra di questa produzione →</Link>
                        </p>
                    )}
                    {crew.length === 0
                        ? <p className="text-sm text-om-muted">Questa produzione non ha ancora una squadra: il costo di manodopera è zero. Le persone si assegnano in Squadre e il foglio si aggiorna da solo.</p>
                        : (
                            <div className="max-h-80 overflow-y-auto">
                                <Table
                                    rowKey={(p, i) => i}
                                    rows={crew}
                                    columns={[
                                        { key: 'name', label: 'Persona', render: (p) => <div><div>{p.name}</div><div className="text-xs text-om-muted">{p.qualification}</div></div> },
                                        { key: 'hours', label: 'Ore', align: 'right', render: (p) => num(p.hours, 2) },
                                        { key: 'hourly_cost', label: '€/ora', align: 'right', render: (p) => num(p.hourly_cost, 2) },
                                        { key: 'cost', label: 'Costo', align: 'right', render: (p) => eur(p.cost) },
                                    ]}
                                />
                            </div>
                        )}
                    <p className="mt-2 text-xs text-om-muted">Ore = durata del foglio, ridotta per chi è uscito prima. Costo orario per qualifica.</p>
                </Card>

                <Card title="Imballi (distinta dell'articolo)" aside={<span className="text-sm font-medium tabular-nums text-om-ink">{eur(costing.packaging_cost)}</span>}>
                    <Table
                        rowKey={(c, i) => i}
                        rows={components}
                        columns={[
                            { key: 'component', label: 'Componente' },
                            { key: 'quantity', label: 'Quantità', align: 'right', render: (c) => `${num(c.quantity, c.quantity % 1 ? 2 : 0)} / ${c.per === 'pallet' ? 'pedana' : 'collo'}` },
                            { key: 'unit_cost', label: '€ cad.', align: 'right', render: (c) => num(c.unit_cost, 3) },
                            { key: 'cost', label: 'Costo', align: 'right', render: (c) => eur(c.cost) },
                        ]}
                    />
                    <p className="mt-2 text-xs text-om-muted">Un collo = {num(sheet.kg_per_carton, 1)} kg, una pedana = {num(sheet.cartons_per_pallet)} colli. Le unità prodotte vengono dai kg confezionati.</p>
                </Card>
            </div>

            <Card
                title="Lotti in ingresso e kg in uscita"
                className="mt-4"
                aside={open && (
                    <span className="flex gap-2">
                        {suggested.length > 0 && rows.length === 0 && <Btn small variant="primary" onClick={useSuggested}>Usa i lotti suggeriti</Btn>}
                        <Btn small onClick={addRow} disabled={available.length === 0}>Aggiungi lotto</Btn>
                    </span>
                )}
            >
                {rows.length === 0 ? (
                    <p className="py-4 text-center text-sm text-om-muted">
                        {open
                            ? 'Nessun lotto ancora. "Usa i lotti suggeriti" propone i lotti più vecchi della varietà giusta: poi basta inserire lo scarto pesato a fine lavorazione.'
                            : 'Nessun lotto registrato.'}
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full border-collapse text-sm">
                            <thead>
                                <tr className="border-b border-om-line text-left text-[11px] uppercase tracking-wide text-om-muted">
                                    <th className="px-2 py-2 font-medium">Lotto · produttore · cella</th>
                                    <th className="px-2 py-2 text-right font-medium">Kg entrati</th>
                                    <th className="px-2 py-2 text-right font-medium">Scarto sidro</th>
                                    <th className="px-2 py-2 text-right font-medium">Scarto industria</th>
                                    <th className="px-2 py-2 text-right font-medium">Kg confezionati</th>
                                    <th className="px-2 py-2 text-right font-medium">Resa</th>
                                    <th className="px-2 py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row, i) => {
                                    const lot = lotById[row.lot_id];

                                    return (
                                        <tr key={i} className="border-b border-om-line last:border-0">
                                            <td className="px-2 py-2">
                                                {open ? (
                                                    <select value={row.lot_id} onChange={(e) => change(i, 'lot_id', e.target.value === '' ? '' : Number(e.target.value))} className={`${inputClass} min-w-64`} aria-label="Lotto">
                                                        <option value="">Scegli un lotto…</option>
                                                        {available.map((l) => <option key={l.lot_id} value={l.lot_id}>{l.code} · {l.producer} · {l.cell} · {num(l.remaining_kg)} kg</option>)}
                                                    </select>
                                                ) : (
                                                    <div>
                                                        <div className="font-medium text-om-ink">{lots[i]?.code}</div>
                                                        <div className="text-xs text-om-muted">{lots[i]?.producer} · cella {lots[i]?.cell}</div>
                                                    </div>
                                                )}
                                                {open && lot && <div className="mt-0.5 text-xs text-om-muted">In cella {lot.cell}: {num(lot.remaining_kg)} kg · acquisto {num(lot.purchase_price_kg, 2)} €/kg</div>}
                                            </td>
                                            <td className="px-2 py-2 text-right">{number(i, 'kg_in', row, 'Kg entrati')}</td>
                                            <td className="px-2 py-2 text-right">{number(i, 'kg_waste_cider', row, 'Scarto sidro')}</td>
                                            <td className="px-2 py-2 text-right">{number(i, 'kg_waste_industry', row, 'Scarto industria')}</td>
                                            <td className="px-2 py-2 text-right">{number(i, 'kg_packed', row, 'Kg confezionati')}</td>
                                            <td className="px-2 py-2 text-right tabular-nums text-om-ink">{n(row.kg_in) > 0 ? pct(n(row.kg_packed) / n(row.kg_in)) : '—'}</td>
                                            <td className="px-2 py-2 text-right">
                                                {open && <Btn small onClick={() => { setDirty(true); setRows(rows.filter((_, j) => j !== i)); }} aria-label="Togli il lotto">Togli</Btn>}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                            <tfoot>
                                <tr className="font-semibold text-om-ink">
                                    <td className="px-2 py-2">Totale</td>
                                    <td className="px-2 py-2 text-right tabular-nums">{kg(totals.in)}</td>
                                    <td className="px-2 py-2 text-right tabular-nums">{kg(totals.cider)}</td>
                                    <td className="px-2 py-2 text-right tabular-nums">{kg(totals.industry)}</td>
                                    <td className="px-2 py-2 text-right tabular-nums">{kg(totals.packed)}</td>
                                    <td className="px-2 py-2 text-right tabular-nums">{totals.in > 0 ? pct(totals.packed / totals.in) : '—'}</td>
                                    <td />
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                )}
                {open && dirty && <p className="mt-2 text-xs text-amber-700 dark:text-amber-300">Modifiche non salvate: il costo in €/kg si aggiorna con "Salva e calcola il costo".</p>}
            </Card>
        </Shell>
    );
}

WorkSheet.layout = (page) => <AppLayout>{page}</AppLayout>;
