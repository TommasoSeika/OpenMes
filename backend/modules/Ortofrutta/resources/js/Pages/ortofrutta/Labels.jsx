import { Link } from '@inertiajs/react';
import AppLayout from '@core/layouts/AppLayout';
import Shell from '../../ortofrutta/Shell';
import Label from '../../ortofrutta/Label';
import { Btn, Card } from '../../ortofrutta/ui';
import { BASE, plural } from '../../ortofrutta/format';

function Sheet({ title, hint, labels }) {
    return (
        <Card title={title} aside={<span className="text-xs text-om-muted">{plural(labels.length, 'etichetta', 'etichette')}</span>} className="mb-4 break-inside-avoid">
            <p className="mb-3 text-xs text-om-muted print:hidden">{hint}</p>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                {labels.map((label) => <Label key={label.code} label={label} />)}
            </div>
        </Card>
    );
}

export default function Labels({ lines, cells, lots }) {
    return (
        <Shell
            title="Etichette"
            active="/missioni"
            actions={<Btn variant="primary" onClick={() => window.print()}>Stampa</Btn>}
        >
            <p className="mb-3 text-sm print:hidden">
                <Link href={`${BASE}/missioni`} className="text-om-accent hover:underline">← Liste di lavoro</Link>
            </p>
            <Sheet title="Linee" hint="Una per linea, all'ingresso dei bins: si legge quando si consegna." labels={lines} />
            <Sheet title="Bins dei lotti in cella" hint="Una per bins, stampata alla pesa: dice di quale lotto è la frutta. Si legge quando si preleva." labels={lots} />
            <Sheet title="Celle frigo" hint="Una per porta. In questa versione non serve per le missioni: è pronta per registrare gli spostamenti tra celle." labels={cells} />
        </Shell>
    );
}

Labels.layout = (page) => <AppLayout>{page}</AppLayout>;
