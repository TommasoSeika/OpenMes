import { useEffect, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AppLayout from '@core/layouts/AppLayout';
import { apiCall } from '@core/lib/http';
import Shell from '../../ortofrutta/Shell';
import { Btn, Card, Chip, Field, Stat, Table, inputClass } from '../../ortofrutta/ui';
import { BASE, eur, eurKg, kg, num, pct } from '../../ortofrutta/format';

const VIEWS = [['producers', 'Produttori'], ['lots', 'Lotti'], ['sheets', 'Fogli'], ['customers', 'Clienti']];

const margin = (v) => (
    <span className={`font-semibold ${v === null || v === undefined ? '' : v < 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-700 dark:text-emerald-400'}`}>{eurKg(v)}</span>
);
const yieldCell = (v) => <span className={v !== null && v < 0.8 ? 'font-semibold text-red-600 dark:text-red-400' : ''}>{pct(v)}</span>;

function Simulator({ defaults, discounts }) {
    const [input, setInput] = useState(defaults);
    const [result, setResult] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        const timer = setTimeout(async () => {
            try {
                const response = await apiCall(`${BASE}/margini/simula`, 'POST', {
                    purchase_price: input.purchase_price, yield_pct: input.yield_pct, processing: input.processing,
                    discount_pct: input.discount_pct, list_price: input.list_price === '' ? null : input.list_price,
                });
                const data = await response.json();
                if (response.ok) {
                    setResult(data);
                    setError(null);
                } else {
                    setError(data.message || 'Valori non validi.');
                }
            } catch {
                setError('Connessione non riuscita.');
            }
        }, 250);

        return () => clearTimeout(timer);
    }, [input.purchase_price, input.yield_pct, input.processing, input.discount_pct, input.list_price]);

    const field = (name, label, step, hint) => (
        <Field label={label} hint={hint}>
            <input type="number" step={step} min="0" value={input[name]} onChange={(e) => setInput({ ...input, [name]: e.target.value })} className={`${inputClass} text-right tabular-nums`} />
        </Field>
    );

    return (
        <Card title="Simulatore di prezzo minimo">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                {field('purchase_price', 'Prezzo di acquisto €/kg', '0.01')}
                {field('yield_pct', 'Resa %', '1', 'kg confezionati su kg entrati')}
                {field('processing', 'Costo di lavorazione €/kg', '0.005', 'media dei fogli chiusi')}
                {field('discount_pct', 'Sconto del periodo %', '1')}
                {field('list_price', 'Prezzo di listino €/kg', '0.01', 'facoltativo: per vedere il margine')}
            </div>

            {discounts.length > 0 && (
                <div className="mt-3 flex flex-wrap items-center gap-1.5 text-xs text-om-muted">
                    Settimane in scontistica:
                    {discounts.map((d) => (
                        <button key={`${d.customer}-${d.week}`} type="button" onClick={() => setInput({ ...input, discount_pct: d.discount_pct })} className="rounded-full border border-om-line px-2 py-0.5 text-om-ink hover:bg-om-chip">
                            {d.customer} · {d.week.replace('-W', ' sett. ')} · {num(d.discount_pct)}%{d.current ? ' · questa settimana' : ''}
                        </button>
                    ))}
                </div>
            )}

            {error && <p className="mt-3 text-sm text-red-600 dark:text-red-400">{error}</p>}
            {result && !error && (
                <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat label="Costo materia per kg venduto" value={eurKg(result.material)} hint="acquisto ÷ resa" />
                    <Stat label="Prezzo minimo netto" value={eurKg(result.minimum_net)} hint="materia + lavorazione" tone="ok" />
                    <Stat label="Prezzo minimo di listino" value={eurKg(result.minimum_list)} hint={`con lo sconto del ${num(input.discount_pct)}%`} />
                    <Stat
                        label="Margine al prezzo di listino"
                        value={result.margin === null ? '—' : eurKg(result.margin)}
                        hint={result.net_price === null ? 'inserisci un prezzo di listino' : `netto ${eurKg(result.net_price)}`}
                        tone={result.margin === null ? undefined : result.margin < 0 ? 'bad' : 'ok'}
                    />
                </div>
            )}
        </Card>
    );
}

export default function Margins({ report, filters, options, discounts }) {
    const [view, setView] = useState('producers');

    if (!report) {
        return <Shell title="Margini" active="/margini">{null}</Shell>;
    }

    const filter = (name, value) => {
        const next = { produttore: filters.producer_id, cliente: filters.customer_id, lotto: filters.lot_id, [name]: value || null };
        router.get(`${BASE}/margini`, Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveScroll: true });
    };
    const filtered = filters.producer_id || filters.customer_id || filters.lot_id;
    const { totals } = report;

    const common = [
        { key: 'kg_packed', label: 'Kg confezionati', align: 'right', render: (r) => kg(r.kg_packed) },
        { key: 'yield', label: 'Resa', align: 'right', render: (r) => yieldCell(r.yield) },
        { key: 'material', label: 'Materia', align: 'right', render: (r) => eurKg(r.material) },
        { key: 'processing', label: 'Lavorazione', align: 'right', render: (r) => eurKg(r.processing) },
        { key: 'minimum', label: 'Prezzo minimo', align: 'right', render: (r) => eurKg(r.minimum ?? (r.material !== null && r.processing !== null ? r.material + r.processing : null)) },
        { key: 'net_price', label: 'Prezzo netto', align: 'right', render: (r) => eurKg(r.net_price) },
        { key: 'margin', label: 'Margine', align: 'right', render: (r) => margin(r.margin) },
    ];
    const total = { key: 'margin_total', label: 'Margine €', align: 'right', render: (r) => eur(r.margin_total, 0) };
    const rank = { key: 'rank', label: '#', render: (r, i) => <span className="tabular-nums text-om-muted">{i + 1}</span> };

    const tables = {
        producers: {
            rows: report.producers,
            columns: [rank, { key: 'label', label: 'Produttore', render: (r) => <div><div className="font-medium">{r.label}</div><div className="text-xs text-om-muted">{r.sub} · {r.sheets} righe di foglio</div></div> }, ...common, total],
        },
        lots: {
            rows: report.lots,
            columns: [rank, { key: 'label', label: 'Lotto', render: (r) => <div><div className="font-medium">{r.label} · {r.variety}</div><div className="text-xs text-om-muted">{r.sub} · acquisto {num(r.purchase_price, 2)} €/kg</div></div> }, ...common, total],
        },
        sheets: {
            rows: report.sheets,
            columns: [
                { key: 'number', label: 'Foglio', render: (r) => <Link href={`${BASE}/fogli/${r.id}`} className="font-semibold tabular-nums text-om-accent hover:underline">{r.number}</Link> },
                { key: 'day', label: 'Giorno', render: (r) => `${r.day} · ${r.line}` },
                { key: 'item', label: 'Articolo', render: (r) => <div><div>{r.item_code} · {r.variety}</div><div className="text-xs text-om-muted">{r.customers.join(', ')}</div></div> },
                ...common,
            ],
        },
        customers: {
            rows: report.customers,
            columns: [rank, { key: 'label', label: 'Cliente', render: (r) => <span className="font-medium">{r.label}</span> }, ...common.filter((c) => c.key !== 'yield'), total],
        },
    };
    const current = tables[view];

    const select = (name, value, items, label, all) => (
        <label className="block">
            <span className="mb-1 block text-xs font-medium text-om-muted">{label}</span>
            <select value={value ?? ''} onChange={(e) => filter(name, e.target.value)} className={`${inputClass} min-w-48`}>
                <option value="">{all}</option>
                {items.map((o) => <option key={o.id} value={o.id}>{o.name ?? o.code}</option>)}
            </select>
        </label>
    );

    return (
        <Shell title="Margini" active="/margini">
            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
                <Stat label="Fogli chiusi" value={num(totals.sheets)} />
                <Stat label="Kg confezionati" value={kg(totals.kg_packed)} />
                <Stat label="Resa media" value={pct(totals.yield)} />
                <Stat label="Costo medio di lavorazione" value={eurKg(totals.processing)} hint="lo stesso €/kg dei fogli" />
                <Stat label="Margine medio" value={eurKg(totals.margin)} tone={totals.margin !== null && totals.margin < 0 ? 'bad' : 'ok'} />
            </div>

            <Card
                title="Costo di lavorazione e margine"
                aside={(
                    <div className="flex flex-wrap gap-1">
                        {VIEWS.map(([key, label]) => (
                            <button key={key} type="button" onClick={() => setView(key)} className={`rounded-lg px-3 py-1 text-sm ${view === key ? 'bg-om-ink font-medium text-om-on-ink' : 'text-om-muted hover:bg-om-chip'}`}>
                                {label}
                            </button>
                        ))}
                    </div>
                )}
            >
                <div className="mb-3 flex flex-wrap items-end gap-3">
                    {select('produttore', filters.producer_id, options.producers, 'Produttore', 'Tutti i produttori')}
                    {select('lotto', filters.lot_id, options.lots, 'Lotto', 'Tutti i lotti')}
                    {select('cliente', filters.customer_id, options.customers, 'Cliente', 'Tutti i clienti')}
                    {filtered && <Btn onClick={() => router.get(`${BASE}/margini`)}>Togli i filtri</Btn>}
                </div>
                <Table
                    columns={current.columns}
                    rows={current.rows}
                    empty="Nessun foglio chiuso corrisponde ai filtri."
                    rowClass={(r) => (r.margin !== null && r.margin !== undefined && r.margin < 0 ? 'bg-red-50 dark:bg-red-950/30' : '')}
                />
                <p className="mt-3 text-xs text-om-muted">
                    Materia = prezzo di acquisto ÷ resa. Margine = prezzo di vendita al netto dello sconto − materia − lavorazione. Classifica dal margine più alto al più basso: chi ha la resa bassa finisce in fondo, perché lo scarto si paga ma non si vende.
                    {view === 'sheets' && <> Il prezzo minimo è materia + lavorazione.</>}
                </p>
            </Card>

            <div className="mt-4">
                <Simulator
                    discounts={discounts}
                    defaults={{
                        purchase_price: 1.0,
                        yield_pct: totals.yield ? Math.round(totals.yield * 100) : 88,
                        processing: totals.processing ? Number(totals.processing.toFixed(3)) : 0.2,
                        discount_pct: 0,
                        list_price: '',
                    }}
                />
            </div>
            <p className="mt-3 flex items-center gap-2 text-xs text-om-muted">
                <Chip tone="warn">DATI SIMULATI</Chip> Prezzi di acquisto, listini, sconti e costi orari sono ipotesi: servono a mostrare il calcolo, non i numeri veri.
            </p>
        </Shell>
    );
}

Margins.layout = (page) => <AppLayout>{page}</AppLayout>;
