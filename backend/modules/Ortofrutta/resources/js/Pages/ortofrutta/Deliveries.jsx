import { useMemo, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import AppLayout from '@core/layouts/AppLayout';
import Shell from '../../ortofrutta/Shell';
import { Btn, Card, Chip, Field, Stat, Table, inputClass } from '../../ortofrutta/ui';
import { BASE, kg, num } from '../../ortofrutta/format';

// The cell proposed for a delivery: one that already holds only this variety and has room, else an empty one.
function suggestCell(cells, variety, bins) {
    const room = (c) => c.capacity_bins - c.bins >= bins;

    return cells.find((c) => c.varieties.length === 1 && c.varieties[0] === variety && room(c))
        ?? cells.find((c) => c.bins === 0 && room(c))
        ?? null;
}

export default function Deliveries({ deliveries, producers, varieties, cells }) {
    const { errors = {}, orto } = usePage().props;
    const [form, setForm] = useState({ producer_id: '', variety: '', gross_kg: '', bins: '', bin_tare_kg: 33, cell_id: '', purchase_price_kg: '1.00' });
    const [cellChosen, setCellChosen] = useState(false);

    const bins = Number(form.bins) || 0;
    const tare = bins * (Number(form.bin_tare_kg) || 0);
    const net = Math.max(0, (Number(form.gross_kg) || 0) - tare);
    const suggested = useMemo(() => (form.variety && bins > 0 ? suggestCell(cells, form.variety, bins) : null), [cells, form.variety, bins]);
    const cellId = cellChosen ? form.cell_id : (suggested?.id ?? '');

    const set = (name) => (e) => setForm({ ...form, [name]: e.target.value });

    const submit = (e) => {
        e.preventDefault();
        router.post(`${BASE}/conferimenti`, { ...form, cell_id: cellId }, {
            preserveScroll: true,
            onSuccess: () => {
                setForm({ ...form, gross_kg: '', bins: '', cell_id: '' });
                setCellChosen(false);
            },
        });
    };

    const inStock = deliveries.reduce((s, d) => s + d.remaining_kg, 0);

    return (
        <Shell title="Conferimenti" active="/conferimenti">
            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Stat label="Lotti conferiti" value={num(deliveries.length)} />
                <Stat label="Kg conferiti" value={kg(deliveries.reduce((s, d) => s + d.net_kg, 0))} />
                <Stat label="Ancora in cella" value={kg(inStock)} />
                <Stat label="Celle occupate" value={`${cells.filter((c) => c.bins > 0).length} / ${cells.length}`} />
            </div>

            <Card title="Nuovo conferimento: pesa e scarico in cella" className="mb-4">
                <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Field label="Produttore" error={errors.producer_id}>
                        <select required value={form.producer_id} onChange={set('producer_id')} className={inputClass}>
                            <option value="">Scegli…</option>
                            {producers.map((p) => <option key={p.id} value={p.id}>{p.code} · {p.name}</option>)}
                        </select>
                    </Field>
                    <Field label="Varietà" error={errors.variety}>
                        <select required value={form.variety} onChange={set('variety')} className={inputClass}>
                            <option value="">Scegli…</option>
                            {varieties.map((v) => <option key={v} value={v}>{v}</option>)}
                        </select>
                    </Field>
                    <Field label="Peso lordo alla pesa (kg)" error={errors.gross_kg}>
                        <input type="number" min="1" step="1" required value={form.gross_kg} onChange={set('gross_kg')} className={`${inputClass} text-right tabular-nums`} />
                    </Field>
                    <Field label="Numero di bins" error={errors.bins}>
                        <input type="number" min="1" step="1" required value={form.bins} onChange={set('bins')} className={`${inputClass} text-right tabular-nums`} />
                    </Field>
                    <Field label="Tara per bin (kg)" error={errors.bin_tare_kg} hint={`Tara totale ${kg(tare)}`}>
                        <input type="number" min="0" step="0.5" required value={form.bin_tare_kg} onChange={set('bin_tare_kg')} className={`${inputClass} text-right tabular-nums`} />
                    </Field>
                    <Field label="Peso netto" hint="lordo − tara dei bins">
                        <div className="rounded-lg border border-om-line bg-om-chip px-2.5 py-1.5 text-right text-sm font-semibold tabular-nums text-om-ink">{kg(net)}</div>
                    </Field>
                    <Field label="Cella di destinazione" error={errors.cell_id} hint={!cellChosen && suggested ? `Proposta: ${suggested.code}, ${suggested.bins === 0 ? 'vuota' : 'stessa varietà'}` : undefined}>
                        <select required value={cellId} onChange={(e) => { setCellChosen(true); setForm({ ...form, cell_id: e.target.value }); }} className={inputClass}>
                            <option value="">Scegli…</option>
                            {cells.map((c) => (
                                <option key={c.id} value={c.id} disabled={c.capacity_bins - c.bins < bins}>
                                    {c.code} · {c.building} · liberi {num(c.capacity_bins - c.bins)} bins{c.varieties.length ? ` · ${c.varieties.join(', ')}` : ' · vuota'}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Prezzo di acquisto ipotizzato (€/kg)" error={errors.purchase_price_kg} hint="o liquidazione prevista">
                        <input type="number" min="0" step="0.01" required value={form.purchase_price_kg} onChange={set('purchase_price_kg')} className={`${inputClass} text-right tabular-nums`} />
                    </Field>
                    <div className="flex items-end sm:col-span-2 lg:col-span-4">
                        <Btn type="submit" variant="primary">Registra il conferimento</Btn>
                        <span className="ml-3 text-xs text-om-muted">Il lotto nasce alle {orto.clock} della giornata demo e compare subito nella cella.</span>
                    </div>
                </form>
            </Card>

            <Card title="Conferimenti" aside={<Chip>dal più recente</Chip>}>
                <Table
                    rows={deliveries}
                    empty="Nessun conferimento."
                    columns={[
                        { key: 'code', label: 'Lotto', render: (d) => <span className="font-medium">{d.code}</span> },
                        { key: 'received', label: 'Conferito il', render: (d) => <span className="tabular-nums">{d.received}</span> },
                        { key: 'producer', label: 'Produttore', render: (d) => <div><div>{d.producer}</div><div className="text-xs text-om-muted">{d.producer_code}</div></div> },
                        { key: 'variety', label: 'Varietà' },
                        { key: 'gross_kg', label: 'Lordo', align: 'right', render: (d) => kg(d.gross_kg) },
                        { key: 'tare_kg', label: 'Tara', align: 'right', render: (d) => kg(d.tare_kg) },
                        { key: 'net_kg', label: 'Netto', align: 'right', render: (d) => <span className="font-semibold">{kg(d.net_kg)}</span> },
                        { key: 'bins', label: 'Bins', align: 'right', render: (d) => num(d.bins) },
                        { key: 'cell', label: 'Cella', render: (d) => <div><div className="font-medium">{d.cell}</div><div className="text-xs text-om-muted">{d.building}</div></div> },
                        { key: 'purchase_price_kg', label: '€/kg', align: 'right', render: (d) => num(d.purchase_price_kg, 2) },
                        { key: 'remaining_kg', label: 'In cella', align: 'right', render: (d) => (d.remaining_kg > 0 ? kg(d.remaining_kg) : <Chip>lavorato</Chip>) },
                    ]}
                />
            </Card>
        </Shell>
    );
}

Deliveries.layout = (page) => <AppLayout>{page}</AppLayout>;
