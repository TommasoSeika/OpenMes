import { Link } from '@inertiajs/react';
import AppLayout from '@core/layouts/AppLayout';
import Shell from '../../ortofrutta/Shell';
import { Card, Chip, Stat, Table } from '../../ortofrutta/ui';
import { BASE, eurKg, kg, num, pct } from '../../ortofrutta/format';

export default function WorkSheets({ sheets }) {
    const closed = sheets.filter((s) => s.status === 'closed');
    const packed = closed.reduce((sum, s) => sum + Number(s.kg_packed || 0), 0);
    const cost = closed.reduce((sum, s) => sum + Number(s.labour_cost || 0) + Number(s.packaging_cost || 0), 0);
    const entered = closed.reduce((sum, s) => sum + Number(s.kg_in || 0), 0);

    const columns = [
        {
            key: 'number', label: 'Foglio',
            render: (s) => <Link href={`${BASE}/fogli/${s.id}`} className="font-semibold tabular-nums text-om-accent hover:underline">{s.number}</Link>,
        },
        { key: 'line', label: 'Linea' },
        {
            key: 'item', label: 'Articolo',
            render: (s) => (
                <div>
                    <div>{s.item_code} · {s.variety}</div>
                    <div className="text-xs text-om-muted">{s.customers.join(', ')}</div>
                </div>
            ),
        },
        { key: 'time', label: 'Orario', render: (s) => <span className="tabular-nums">{s.start}–{s.end}</span> },
        { key: 'people', label: 'Persone', align: 'right', render: (s) => num(s.people) },
        { key: 'pallets', label: 'Pedane', align: 'right', render: (s) => num(s.pallets) },
        { key: 'kg_packed', label: 'Kg confezionati', align: 'right', render: (s) => (s.kg_packed > 0 ? kg(s.kg_packed) : '—') },
        { key: 'yield', label: 'Resa', align: 'right', render: (s) => pct(s.yield) },
        { key: 'cost_per_kg', label: 'Costo lavorazione', align: 'right', render: (s) => <span className="font-semibold">{eurKg(s.cost_per_kg)}</span> },
        { key: 'status', label: 'Stato', render: (s) => <Chip tone={s.status === 'closed' ? 'ok' : 'info'}>{s.status === 'closed' ? 'Chiuso' : 'Aperto'}</Chip> },
    ];

    return (
        <Shell title="Fogli di lavorazione" active="/fogli" dayPicker>
            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Stat label="Fogli" value={num(sheets.length)} hint={`${num(sheets.length - closed.length)} aperti`} />
                <Stat label="Kg confezionati" value={kg(packed)} />
                <Stat label="Resa media" value={entered > 0 ? pct(packed / entered) : '—'} />
                <Stat label="Costo medio di lavorazione" value={packed > 0 ? eurKg(cost / packed) : '—'} hint="manodopera + imballi" />
            </div>

            <Card title="Fogli della giornata">
                <Table
                    columns={columns}
                    rows={sheets}
                    empty="Nessun foglio per questa giornata. Un foglio nasce dal piano linee, quando si lancia un gruppo di ordini."
                />
            </Card>
            <p className="mt-3 text-xs text-om-muted">
                Nessun costo è stato digitato: la manodopera viene dalle persone assegnate alla linea e dalle ore del foglio, gli imballi dalla distinta dell'articolo.
            </p>
        </Shell>
    );
}

WorkSheets.layout = (page) => <AppLayout>{page}</AppLayout>;
