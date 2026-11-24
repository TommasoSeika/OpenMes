import AppLayout from '@core/layouts/AppLayout';
import Shell from '../../ortofrutta/Shell';
import { Card, Chip, Stat, Table } from '../../ortofrutta/ui';
import { kg, num } from '../../ortofrutta/format';

export default function Missions({ missions }) {
    const real = missions.filter((m) => !m.short);
    const cells = new Set(real.map((m) => m.cell));
    const short = missions.filter((m) => m.short);

    const columns = [
        { key: 'by', label: 'Entro', render: (m) => <span className="font-semibold tabular-nums">{m.by}</span> },
        { key: 'line', label: 'Per la linea', render: (m) => m.line },
        {
            key: 'cell', label: 'Cella',
            render: (m) => (m.short
                ? <Chip tone="bad">giacenza insufficiente</Chip>
                : <div><div className="font-semibold">{m.cell}</div><div className="text-xs text-om-muted">{m.building}</div></div>),
        },
        { key: 'lot', label: 'Lotto', render: (m) => (m.short ? '—' : <div><div className="font-medium">{m.lot}</div><div className="text-xs text-om-muted">{m.producer}</div></div>) },
        { key: 'variety', label: 'Varietà' },
        { key: 'bins', label: 'Bins', align: 'right', render: (m) => (m.short ? '—' : <span className="font-semibold">{num(m.bins)}</span>) },
        { key: 'kg', label: 'Kg', align: 'right', render: (m) => kg(m.kg) },
        { key: 'order', label: 'Ordine', render: (m) => <div><div>{m.customer}</div><div className="text-xs text-om-muted">{m.order} · {m.item_code}</div></div> },
        { key: 'launched', label: '', render: (m) => (m.launched ? <Chip tone="info">in lavorazione</Chip> : <Chip>in piano</Chip>) },
    ];

    return (
        <Shell title="Missioni bins" active="/missioni" dayPicker>
            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Stat label="Missioni" value={num(real.length)} />
                <Stat label="Bins da portare" value={num(real.reduce((s, m) => s + m.bins, 0))} />
                <Stat label="Kg da portare" value={kg(real.reduce((s, m) => s + m.kg, 0))} />
                <Stat label="Celle da aprire" value={num(cells.size)} />
            </div>

            {short.length > 0 && (
                <p className="mb-4 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200">
                    Per {short.length} ordini non c'è abbastanza frutta in cella della varietà richiesta.
                </p>
            )}

            <Card title="Prelievi per le linee" aside={<Chip>sola consultazione</Chip>}>
                <Table
                    columns={columns}
                    rows={missions}
                    rowKey={(m, i) => i}
                    empty="Nessuna missione: sul piano non ci sono ordini da lavorare."
                    rowClass={(m) => (m.short ? 'bg-red-50 dark:bg-red-950/30' : '')}
                />
                <p className="mt-3 text-xs text-om-muted">
                    Elenco generato dal piano linee: per ogni ordine il lotto più vecchio della varietà giusta, la cella in cui si trova e l'ora entro cui portarlo alla linea (20 minuti prima dell'inizio del blocco).
                    Qui non si prenota e non si scarica nulla: le giacenze cambiano solo quando si salva un foglio di lavorazione.
                </p>
            </Card>
        </Shell>
    );
}

Missions.layout = (page) => <AppLayout>{page}</AppLayout>;
