import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import AppLayout from '@core/layouts/AppLayout';
import Shell from '../../ortofrutta/Shell';
import { Btn, Card, Chip, Stat, Table, inputClass } from '../../ortofrutta/ui';
import { BASE, ORDER_TYPES, PRIORITIES, num } from '../../ortofrutta/format';

function Status({ order }) {
    if (order.sheet_status === 'closed') {
        return <Chip tone="ok">Confezionato · foglio {order.sheet_number}</Chip>;
    }
    if (order.sheet_number) {
        return <Chip tone="info">In lavorazione · foglio {order.sheet_number}</Chip>;
    }
    if (order.plan) {
        return (
            <span className="flex flex-wrap items-center gap-1">
                <Chip>{order.plan.line} · {order.plan.start}–{order.plan.end}</Chip>
                {order.plan.late && <Chip tone="bad">oltre il camion</Chip>}
            </span>
        );
    }

    return <Chip tone="warn">Da pianificare</Chip>;
}

export default function Orders({ orders, items, waiting }) {
    const { orto } = usePage().props;
    const readOnly = !orto.is_demo_day;
    const [stock, setStock] = useState({ item_id: '', pallets: 10 });
    const [adding, setAdding] = useState(false);

    const options = { preserveScroll: true };
    const pallets = orders.reduce((s, o) => s + o.pallets, 0);
    const fast = orders.filter((o) => o.truck === '15:00');
    const late = orders.filter((o) => o.plan?.late).length;
    const unplanned = orders.filter((o) => !o.plan && !o.sheet_number).length;

    const addStock = (e) => {
        e.preventDefault();
        router.post(`${BASE}/ordini/scorta`, stock, { ...options, onSuccess: () => setAdding(false) });
    };

    const columns = [
        { key: 'number', label: 'Ordine', render: (o) => <span className="tabular-nums">{o.number}</span> },
        { key: 'customer', label: 'Cliente', render: (o) => <span className="font-medium">{o.customer}</span> },
        {
            key: 'item', label: 'Articolo',
            render: (o) => (
                <div>
                    <div>{o.variety} {o.size}</div>
                    <div className="text-xs text-om-muted">{o.item_code} · {o.format} · {o.packaging}</div>
                </div>
            ),
        },
        { key: 'pallets', label: 'Pedane', align: 'right', render: (o) => num(o.pallets) },
        { key: 'type', label: 'Tipo', render: (o) => <Chip tone={o.type === 'axb' ? 'info' : 'neutral'}>{ORDER_TYPES[o.type]}</Chip> },
        { key: 'arrived', label: 'Arrivo', render: (o) => <span className="tabular-nums">{o.arrived}</span> },
        {
            key: 'truck', label: 'Camion',
            render: (o) => (o.truck
                ? <Chip tone={o.truck === '15:00' ? 'info' : 'neutral'}>{o.truck}{o.truck === '15:00' ? ' · carico veloce' : ''}</Chip>
                : <span className="text-om-muted">—</span>),
        },
        {
            key: 'priority', label: 'Priorità di uscita',
            render: (o) => (readOnly || o.locked
                ? PRIORITIES[o.priority]
                : (
                    <select
                        value={o.priority}
                        onChange={(e) => router.patch(`${BASE}/ordini/${o.id}/priorita`, { priority: Number(e.target.value) }, options)}
                        className="rounded-lg border border-om-line bg-om-card px-2 py-1 text-sm text-om-ink"
                        aria-label={`Priorità dell'ordine ${o.number}`}
                    >
                        {Object.entries(PRIORITIES).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                    </select>
                )),
        },
        { key: 'status', label: 'Stato', render: (o) => <Status order={o} /> },
    ];

    return (
        <Shell
            title="Ordini del giorno"
            active="/ordini"
            dayPicker
            actions={!readOnly && (
                <>
                    <Btn onClick={() => setAdding((v) => !v)}>Aggiungi riga di scorta</Btn>
                    <Btn variant="primary" disabled={waiting === 0} onClick={() => router.post(`${BASE}/ordini/simula`, {}, options)} title="Fa entrare la prossima ondata di ordini A per A">
                        Simula arrivo ordini{waiting > 0 ? ` (${waiting} in arrivo)` : ''}
                    </Btn>
                </>
            )}
        >
            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
                <Stat label="Ordini arrivati" value={num(orders.length)} hint={waiting > 0 ? `${waiting} devono ancora arrivare` : 'tutti arrivati'} />
                <Stat label="Pedane" value={num(pallets)} />
                <Stat label="Camion delle 15:00" value={num(fast.length)} hint={`${num(fast.reduce((s, o) => s + o.pallets, 0))} pedane`} />
                <Stat label="Da pianificare" value={num(unplanned)} tone={unplanned > 0 ? 'bad' : 'ok'} />
                <Stat label="Oltre il camion" value={num(late)} tone={late > 0 ? 'bad' : 'ok'} hint={late > 0 ? 'vedi il piano linee' : 'nessuno a rischio'} />
            </div>

            {adding && (
                <Card title="Nuova riga di scorta" className="mb-4">
                    <form onSubmit={addStock} className="flex flex-wrap items-end gap-3">
                        <label className="min-w-64 flex-1">
                            <span className="mb-1 block text-xs font-medium text-om-muted">Articolo</span>
                            <select required value={stock.item_id} onChange={(e) => setStock({ ...stock, item_id: e.target.value })} className={inputClass}>
                                <option value="">Scegli un articolo…</option>
                                {items.map((i) => <option key={i.id} value={i.id}>{i.code} · {i.description}</option>)}
                            </select>
                        </label>
                        <label className="w-28">
                            <span className="mb-1 block text-xs font-medium text-om-muted">Pedane</span>
                            <input type="number" min="1" max="60" required value={stock.pallets} onChange={(e) => setStock({ ...stock, pallets: e.target.value })} className={inputClass} />
                        </label>
                        <Btn type="submit" variant="primary">Aggiungi</Btn>
                        <Btn onClick={() => setAdding(false)}>Annulla</Btn>
                    </form>
                    <p className="mt-2 text-xs text-om-muted">Una riga di scorta non ha camion: entra nel piano con la priorità più bassa e può essere raggruppata nello stesso lancio di un ordine con lo stesso articolo.</p>
                </Card>
            )}

            <Card
                title="Ordini"
                aside={<Link href={`${BASE}/piano`} className="text-sm text-om-accent hover:underline">Vai al piano linee →</Link>}
            >
                <Table
                    columns={columns}
                    rows={orders}
                    empty="Nessun ordine per questa giornata."
                    rowClass={(o) => (o.plan?.late ? 'bg-red-50 dark:bg-red-950/30' : '')}
                />
            </Card>
        </Shell>
    );
}

Orders.layout = (page) => <AppLayout>{page}</AppLayout>;
