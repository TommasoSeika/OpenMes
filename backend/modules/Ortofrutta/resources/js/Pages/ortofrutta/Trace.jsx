import { useEffect, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AppLayout from '@core/layouts/AppLayout';
import Shell from '../../ortofrutta/Shell';
import { Btn, Card, Chip, Field, Table, inputClass } from '../../ortofrutta/ui';
import { BASE, ORDER_TYPES, kg, num, pct, plural } from '../../ortofrutta/format';

const HERE = `${BASE}/tracciabilita`;

const ORDER_STATUS = {
    packed: { label: 'Confezionato', tone: 'ok' },
    running: { label: 'In lavorazione', tone: 'info' },
    planned: { label: 'In piano', tone: 'neutral' },
    waiting: { label: 'Da pianificare', tone: 'warn' },
};

// Only what is set goes in the address, so a link to a search stays short.
const clean = (params) => Object.fromEntries(Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== ''));

function Step({ n, title, aside, children }) {
    return (
        <section className="rounded-xl border border-om-line bg-om-card">
            <header className="flex flex-wrap items-center justify-between gap-2 border-b border-om-line px-4 py-2.5">
                <h2 className="flex items-center gap-2 text-sm font-semibold text-om-ink">
                    <span className="flex h-6 w-6 items-center justify-center rounded-full bg-om-ink text-xs font-semibold text-om-on-ink">{n}</span>
                    {title}
                </h2>
                {aside}
            </header>
            <div className="p-4">{children}</div>
        </section>
    );
}

function Facts({ rows }) {
    return (
        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm">
            {rows.filter(Boolean).map(([label, value]) => (
                <div key={label} className="contents">
                    <dt className="text-om-muted">{label}</dt>
                    <dd className="text-om-ink">{value}</dd>
                </div>
            ))}
        </dl>
    );
}

/** From what a customer received back to the producers. */
function OrderChain({ detail, go, jump }) {
    const { order, sheet, lots, producers, crew } = detail;
    const status = ORDER_STATUS[order.status];

    return (
        <div className="space-y-3">
            <p className="rounded-lg border border-om-line bg-om-chip px-3 py-2 text-sm text-om-ink">
                {sheet
                    ? <>L'ordine <strong>{order.number}</strong> di <strong>{order.customer}</strong> ({plural(order.pallets, 'pedana', 'pedane')}) {sheet.status === 'closed' ? 'è stato confezionato' : 'è in lavorazione'} {sheet.day_label} su {sheet.line}, lotto <strong className="tabular-nums">{sheet.packing_lot}</strong>{lots.length > 0 ? <>, con frutta di <strong>{plural(producers.length, 'produttore', 'produttori')}</strong> ({plural(lots.length, 'lotto', 'lotti')})</> : null}.</>
                    : <>L'ordine <strong>{order.number}</strong> di <strong>{order.customer}</strong> non è ancora stato lavorato: la sua storia comincia quando viene lanciato dal piano linee.</>}
            </p>

            <Step n="1" title="Cliente e spedizione" aside={<Chip tone={status.tone}>{status.label}</Chip>}>
                <Facts rows={[
                    ['Cliente', <>{order.customer}{order.channel ? <span className="text-om-muted"> · {order.channel}</span> : null}</>],
                    ['Ordine', <>{order.number} <span className="text-om-muted">· {ORDER_TYPES[order.type]} · arrivato il {order.arrived}</span></>],
                    ['Prodotto', <>{order.item_code} · {order.item}</>],
                    ['Quantità', <>{plural(order.pallets, 'pedana', 'pedane')} · {num(order.cartons)} colli · {kg(order.kg)} nominali</>],
                    ['Camion', order.truck_full ?? 'nessun camion: a scorta'],
                ]}
                />
            </Step>

            {sheet && (
                <>
                    <Step
                        n="2"
                        title="Lavorazione"
                        aside={<Link href={`${BASE}/fogli/${sheet.id}`} className="text-xs text-om-accent hover:underline">Foglio {sheet.number} →</Link>}
                    >
                        <Facts rows={[
                            ['Lotto di confezionamento', <span className="font-semibold tabular-nums">{sheet.packing_lot}</span>],
                            ['Quando', <>{sheet.day_label}, dalle {sheet.start} alle {sheet.end}</>],
                            ['Dove', <>{sheet.line}{sheet.line_description ? <span className="text-om-muted"> · {sheet.line_description}</span> : null}</>],
                            ['Chi', sheet.people > 0 ? <>{plural(sheet.people, 'persona', 'persone')}{sheet.leader ? <span className="text-om-muted"> · capolinea {sheet.leader}</span> : null}</> : 'squadra non ancora assegnata'],
                            ['Entrati', kg(sheet.kg_in)],
                            ['Confezionati', <>{kg(sheet.kg_packed)} <span className="text-om-muted">· resa {pct(sheet.yield)}</span></>],
                            ['Scarto', <>{kg(sheet.kg_waste_cider)} sidro · {kg(sheet.kg_waste_industry)} industria</>],
                        ]}
                        />
                        {sheet.orders.length > 1 && (
                            <div className="mt-3 border-t border-om-line pt-3">
                                <p className="mb-1.5 text-xs text-om-muted">
                                    Lo stesso foglio ha confezionato {plural(sheet.orders.length, 'ordine', 'ordini')}: a questo va il {pct(sheet.share, 0)} delle pedane, cioè circa {kg(sheet.kg_for_order)}.
                                </p>
                                <div className="flex flex-wrap gap-1.5">
                                    {sheet.orders.map((o) => (o.mine
                                        ? <Chip key={o.id} tone="info">{o.customer} · {o.pallets} ped.</Chip>
                                        : (
                                            <button key={o.id} type="button" onClick={() => go({ ordine: o.id })} className="rounded-full bg-om-chip px-2 py-0.5 text-[11px] font-medium text-om-muted hover:text-om-ink hover:underline">
                                                {o.customer} · {o.pallets} ped.
                                            </button>
                                        )))}
                                </div>
                            </div>
                        )}
                    </Step>

                    <Step n="3" title="Materia prima" aside={<span className="text-xs text-om-muted">{plural(lots.length, 'lotto entrato', 'lotti entrati')} nel foglio</span>}>
                        <Table
                            rowKey={(l) => l.lot_id}
                            rows={lots}
                            empty="Il foglio non ha ancora lotti: si inseriscono a fine lavorazione."
                            columns={[
                                {
                                    key: 'code', label: 'Lotto', render: (l) => (
                                        <button type="button" onClick={() => jump({ vista: 'lotto', lotto: l.lot_id })} className="font-medium text-om-accent hover:underline" title="Vedi a quali clienti è andato questo lotto">
                                            {l.code}
                                        </button>
                                    ),
                                },
                                { key: 'producer', label: 'Produttore', render: (l) => <div>{l.producer}<div className="text-xs text-om-muted">{l.locality}</div></div> },
                                { key: 'received', label: 'Conferito', render: (l) => <div className="tabular-nums">{l.received}<div className="text-xs text-om-muted">cella {l.cell ?? '—'} · {l.days_stored} giorni</div></div> },
                                { key: 'net_kg', label: 'Netto pesa', align: 'right', render: (l) => <div>{kg(l.net_kg)}<div className="text-xs text-om-muted">{num(l.bins)} bins</div></div> },
                                { key: 'kg_in', label: 'Nel foglio', align: 'right', render: (l) => <div>{kg(l.kg_in)}<div className="text-xs text-om-muted">resa {pct(l.yield)}</div></div> },
                                { key: 'kg_for_order', label: 'In questo ordine', align: 'right', render: (l) => kg(l.kg_for_order) },
                            ]}
                        />
                    </Step>

                    <Step n="4" title="Produttori">
                        <Table
                            rowKey={(p) => p.code}
                            rows={producers}
                            empty="Nessun produttore finché il foglio non ha lotti."
                            columns={[
                                { key: 'name', label: 'Produttore', render: (p) => <div>{p.name} <span className="text-xs text-om-muted">{p.code}</span><div className="text-xs text-om-muted">{p.locality}{p.is_member ? ' · socio' : ' · non socio'}</div></div> },
                                { key: 'lots', label: 'Lotti', render: (p) => p.lots.join(', ') },
                                { key: 'kg_for_order', label: 'Kg in questo ordine', align: 'right', render: (p) => kg(p.kg_for_order) },
                                { key: 'share', label: 'Quota', align: 'right', render: (p) => pct(p.share, 0) },
                            ]}
                        />
                        <p className="mt-2 text-xs text-om-muted">
                            La linea non registra da quale bins viene ogni collo: i kg di ogni lotto sono ripartiti tra gli ordini del foglio in proporzione alle pedane. Per un richiamo conta l'elenco dei lotti, che è esatto.
                        </p>
                    </Step>

                    {crew.length > 0 && (
                        <details className="rounded-xl border border-om-line bg-om-card px-4 py-2.5">
                            <summary className="cursor-pointer text-sm font-semibold text-om-ink">Chi l'ha lavorato ({crew.length} persone)</summary>
                            <div className="mt-2 flex flex-wrap gap-1.5">
                                {crew.map((p) => (
                                    <Chip key={p.name} tone={p.is_leader ? 'info' : 'neutral'} title={`${p.qualification} · ${num(p.hours, 2)} ore`}>{p.name}{p.is_leader ? ' · capolinea' : ''}</Chip>
                                ))}
                            </div>
                        </details>
                    )}
                </>
            )}
        </div>
    );
}

/** From a raw lot forward to the customers it reached. */
function LotChain({ detail, jump }) {
    const { lot, sheets, deliveries, customers } = detail;

    return (
        <div className="space-y-3">
            <p className="rounded-lg border border-om-line bg-om-chip px-3 py-2 text-sm text-om-ink">
                {sheets.length > 0
                    ? <>Il lotto <strong>{lot.code}</strong> di <strong>{lot.producer}</strong> è entrato in <strong>{plural(sheets.length, 'foglio di lavorazione', 'fogli di lavorazione')}</strong> ed è arrivato a <strong>{plural(customers.length, 'cliente', 'clienti')}</strong> con {plural(deliveries.length, 'ordine', 'ordini')}. In cella restano {kg(lot.kg_left)}.</>
                    : <>Il lotto <strong>{lot.code}</strong> di <strong>{lot.producer}</strong> non è ancora stato lavorato: è tutto in cella {lot.cell ?? '—'}.</>}
            </p>

            <Step n="1" title="Conferimento" aside={<Chip>{lot.variety}</Chip>}>
                <Facts rows={[
                    ['Produttore', <>{lot.producer} <span className="text-om-muted">{lot.producer_code} · {lot.locality}{lot.is_member ? ' · socio' : ' · non socio'}</span></>],
                    ['Pesato il', <span className="tabular-nums">{lot.received}</span>],
                    ['Peso', <>{kg(lot.gross_kg)} lordi − {kg(lot.tare_kg)} di tara = <strong>{kg(lot.net_kg)}</strong> netti in {num(lot.bins)} bins</>],
                    ['Cella', lot.cell ? <>{lot.cell} <span className="text-om-muted">· {lot.building}</span></> : '—'],
                    ['Lavorato', <>{kg(lot.kg_worked)} entrati in linea · {kg(lot.kg_packed)} confezionati <span className="text-om-muted">· resa {pct(lot.yield)}</span></>],
                    ['Scarto', kg(lot.kg_waste)],
                    ['In cella', kg(lot.kg_left)],
                ]}
                />
            </Step>

            <Step n="2" title="Lavorazioni" aside={<span className="text-xs text-om-muted">{plural(sheets.length, 'foglio', 'fogli')}</span>}>
                <Table
                    rows={sheets}
                    empty="Nessun foglio di lavorazione ha ancora usato questo lotto."
                    columns={[
                        { key: 'number', label: 'Foglio', render: (s) => <Link href={`${BASE}/fogli/${s.id}`} className="font-medium text-om-accent hover:underline">{s.number}</Link> },
                        { key: 'packing_lot', label: 'Lotto confez.', render: (s) => <span className="tabular-nums">{s.packing_lot}</span> },
                        { key: 'day_label', label: 'Quando', render: (s) => <div>{s.day_label}<div className="text-xs tabular-nums text-om-muted">{s.start}–{s.end} · {s.line}</div></div> },
                        { key: 'item', label: 'Articolo' },
                        { key: 'kg_in', label: 'Entrati', align: 'right', render: (s) => kg(s.kg_in) },
                        { key: 'kg_packed', label: 'Confezionati', align: 'right', render: (s) => <div>{kg(s.kg_packed)}<div className="text-xs text-om-muted">resa {pct(s.yield)}</div></div> },
                    ]}
                />
            </Step>

            <Step n="3" title="Clienti raggiunti" aside={<span className="text-xs text-om-muted">{plural(customers.length, 'cliente', 'clienti')}</span>}>
                {customers.length > 0 && (
                    <div className="mb-3 flex flex-wrap gap-1.5">
                        {customers.map((c) => (
                            <Chip key={c.name} tone="info" title={`${c.orders} ordini · dal ${c.first} al ${c.last}`}>{c.name} · {kg(c.kg)}</Chip>
                        ))}
                    </div>
                )}
                <Table
                    rowKey={(d) => `${d.order_id}-${d.sheet_number}`}
                    rows={deliveries}
                    empty="Nessun ordine ha ancora ricevuto frutta di questo lotto."
                    columns={[
                        { key: 'day_label', label: 'Spedito', render: (d) => <div>{d.day_label}<div className="text-xs tabular-nums text-om-muted">{d.truck ? `camion ${d.truck}` : 'a scorta'}</div></div> },
                        { key: 'customer', label: 'Cliente' },
                        {
                            key: 'number', label: 'Ordine', render: (d) => (
                                <button type="button" onClick={() => jump({ vista: 'cliente', ordine: d.order_id })} className="font-medium text-om-accent hover:underline" title="Vedi tutta la storia di questo ordine">
                                    {d.number}
                                </button>
                            ),
                        },
                        { key: 'packing_lot', label: 'Lotto confez.', render: (d) => <span className="tabular-nums">{d.packing_lot}</span> },
                        { key: 'pallets', label: 'Pedane', align: 'right' },
                        { key: 'kg', label: 'Kg di questo lotto', align: 'right', render: (d) => kg(d.kg) },
                    ]}
                />
                {lot.kg_unassigned > 0 && <p className="mt-2 text-xs text-om-muted">{kg(lot.kg_unassigned)} confezionati senza un ordine dietro: sono a magazzino.</p>}
            </Step>
        </div>
    );
}

export default function Trace({ mode, filters, options, results, selected, detail }) {
    const [form, setForm] = useState(filters);
    useEffect(() => setForm(filters), [filters]);

    const byLot = mode === 'lotto';
    const set = (field) => (e) => setForm({ ...form, [field]: e.target.value });

    // Every move is a normal page visit: the address always says what is on screen, so it can be sent to a colleague.
    const visit = (params) => router.get(HERE, clean(params), { preserveScroll: true, preserveState: true });
    const search = (e) => {
        e?.preventDefault();
        visit({ vista: mode, ...form });
    };
    // Pick a row of the list: the search stays as it is.
    const go = (target) => visit({ vista: mode, ...filters, ...target });
    // Follow the chain into the other view: the search of this one does not apply there.
    const jump = (target) => visit(target);
    const switchTo = (vista) => visit({ vista, q: '', cliente: form.cliente, produttore: form.produttore });
    const reset = () => visit({ vista: mode });

    if (!options) {
        return <Shell title="Tracciabilità" active="/tracciabilita">{null}</Shell>;
    }

    const tab = (vista, label) => (
        <button
            type="button"
            onClick={() => switchTo(vista)}
            aria-pressed={mode === vista}
            className={`rounded-lg px-3 py-1.5 text-sm ${mode === vista ? 'bg-om-ink font-medium text-om-on-ink' : 'text-om-muted hover:bg-om-chip hover:text-om-ink'}`}
        >
            {label}
        </button>
    );

    return (
        <Shell title="Tracciabilità" active="/tracciabilita">
            <Card className="mb-4">
                <div className="mb-3 flex flex-wrap gap-1">
                    {tab('cliente', 'Dal cliente al produttore')}
                    {tab('lotto', 'Dal produttore ai clienti')}
                </div>
                <form onSubmit={search} className="grid gap-3 md:grid-cols-2 xl:grid-cols-[2fr_1fr_1fr_auto_auto_auto] xl:items-end">
                    <Field label={byLot ? 'Cerca un lotto, un produttore, una varietà' : 'Cerca un ordine, un cliente, un lotto di confezionamento, un produttore'}>
                        <input type="search" value={form.q ?? ''} onChange={set('q')} placeholder={byLot ? 'es. LT26-012' : 'es. OV48321 oppure L26328-1702'} className={inputClass} />
                    </Field>
                    <Field label="Cliente">
                        <select value={form.cliente ?? ''} onChange={set('cliente')} className={inputClass}>
                            <option value="">Tutti</option>
                            {options.customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                    </Field>
                    <Field label="Produttore">
                        <select value={form.produttore ?? ''} onChange={set('produttore')} className={inputClass}>
                            <option value="">Tutti</option>
                            {options.producers.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                        </select>
                    </Field>
                    {!byLot && (
                        <>
                            <Field label="Dal">
                                <input type="date" value={form.dal ?? ''} min={options.first_day} max={options.last_day} onChange={set('dal')} className={inputClass} />
                            </Field>
                            <Field label="Al">
                                <input type="date" value={form.al ?? ''} min={options.first_day} max={options.last_day} onChange={set('al')} className={inputClass} />
                            </Field>
                        </>
                    )}
                    <div className="flex gap-2">
                        <Btn type="submit" variant="primary">Cerca</Btn>
                        <Btn onClick={reset}>Azzera</Btn>
                    </div>
                </form>
            </Card>

            <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
                <Card
                    title={byLot ? 'Lotti conferiti' : 'Ordini'}
                    aside={(
                        <span className="text-xs text-om-muted">
                            {results.total > results.rows.length ? `primi ${num(results.rows.length)} di ${num(results.total)}: restringi la ricerca` : plural(results.total, 'risultato', 'risultati')}
                        </span>
                    )}
                >
                    <div className="max-h-[70vh] overflow-y-auto">
                        {byLot ? (
                            <Table
                                rows={results.rows}
                                empty="Nessun lotto corrisponde alla ricerca."
                                rowClass={(l) => (l.id === selected ? 'bg-om-chip' : '')}
                                columns={[
                                    { key: 'code', label: 'Lotto', render: (l) => <button type="button" onClick={() => go({ lotto: l.id })} className="font-medium text-om-accent hover:underline">{l.code}</button> },
                                    { key: 'producer', label: 'Produttore', render: (l) => <div>{l.producer}<div className="text-xs text-om-muted">{l.variety}</div></div> },
                                    { key: 'received', label: 'Conferito', render: (l) => <span className="tabular-nums">{l.received}</span> },
                                    { key: 'net_kg', label: 'Netto', align: 'right', render: (l) => <div>{kg(l.net_kg)}<div className="text-xs text-om-muted">restano {kg(l.kg_left)}</div></div> },
                                    { key: 'customers', label: 'Clienti', align: 'right', render: (l) => (l.sheets > 0 ? <div>{num(l.customers)}<div className="text-xs text-om-muted">{plural(l.sheets, 'foglio', 'fogli')}</div></div> : <span className="text-xs text-om-muted">in cella</span>) },
                                ]}
                            />
                        ) : (
                            <Table
                                rows={results.rows}
                                empty="Nessun ordine corrisponde alla ricerca."
                                rowClass={(o) => (o.id === selected ? 'bg-om-chip' : '')}
                                columns={[
                                    { key: 'day_label', label: 'Giorno', render: (o) => <div className="whitespace-nowrap">{o.day_label}<div className="text-xs tabular-nums text-om-muted">{o.truck ? `camion ${o.truck}` : 'scorta'}</div></div> },
                                    {
                                        key: 'number', label: 'Ordine', render: (o) => (
                                            <div>
                                                <button type="button" onClick={() => go({ ordine: o.id })} className="font-medium text-om-accent hover:underline">{o.number}</button>
                                                <div className="text-xs text-om-muted">{o.customer}</div>
                                            </div>
                                        ),
                                    },
                                    { key: 'item', label: 'Prodotto', render: (o) => <div>{o.variety}<div className="text-xs text-om-muted">{o.pallets} ped. · {kg(o.kg)}</div></div> },
                                    {
                                        key: 'packing_lot', label: 'Lotto confez.', render: (o) => (o.packing_lot
                                            ? <div className="tabular-nums">{o.packing_lot}<div className="text-xs text-om-muted">{o.line}</div></div>
                                            : <Chip tone={ORDER_STATUS[o.status].tone}>{ORDER_STATUS[o.status].label}</Chip>),
                                    },
                                    { key: 'producers', label: 'Produttori', render: (o) => (o.producers.length > 0 ? <span className="text-xs">{o.producers.join(', ')}</span> : <span className="text-xs text-om-muted">—</span>) },
                                ]}
                            />
                        )}
                    </div>
                </Card>

                <div>
                    {detail
                        ? (byLot ? <LotChain detail={detail} jump={jump} /> : <OrderChain detail={detail} go={go} jump={jump} />)
                        : (
                            <p className="rounded-xl border border-dashed border-om-line bg-om-card px-6 py-10 text-center text-sm text-om-muted">
                                {byLot
                                    ? 'Scegli un lotto per vedere in quali lavorazioni è entrato e a quali clienti è arrivato.'
                                    : 'Scegli un ordine per vedere quando è stato lavorato, con quali lotti e di quali produttori.'}
                            </p>
                        )}
                </div>
            </div>
        </Shell>
    );
}

Trace.layout = (page) => <AppLayout>{page}</AppLayout>;
