import { router, usePage } from '@inertiajs/react';
import AppLayout from '@core/layouts/AppLayout';
import Shell from '../../ortofrutta/Shell';
import { Btn, Card, Chip, Stat } from '../../ortofrutta/ui';
import { BASE, num, plural } from '../../ortofrutta/format';

const options = { preserveScroll: true };

function Person({ person, action }) {
    return (
        <li className="flex items-center justify-between gap-2 py-1.5">
            <div className="min-w-0">
                <div className="truncate text-sm text-om-ink">
                    {person.name}
                    {person.is_leader && <span className="ml-1.5 text-xs font-medium text-om-accent">capolinea</span>}
                </div>
                <div className="truncate text-xs text-om-muted">{person.qualification} · {person.badge}{person.reason ? ` · ${person.reason}` : ''}</div>
            </div>
            {action}
        </li>
    );
}

export default function Crews({ crews, load }) {
    const { orto } = usePage().props;
    const readOnly = !orto.is_demo_day;

    if (!crews) {
        return <Shell title="Squadre" active="/squadre" dayPicker>{null}</Shell>;
    }

    const { summary } = crews;
    const short = crews.lines.filter((l) => l.missing > 0 && (load[l.id]?.blocks ?? 0) > 0);

    return (
        <Shell
            title="Squadre"
            active="/squadre"
            dayPicker
            actions={!readOnly && (
                <Btn variant="primary" onClick={() => router.post(`${BASE}/squadre/proponi`, {}, options)} title="Riempie ogni linea con i presenti abilitati">
                    Proponi squadre
                </Btn>
            )}
        >
            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
                <Stat label="Persone" value={num(summary.people)} />
                <Stat label="Presenti tutto il turno" value={num(summary.present)} tone="ok" />
                <Stat label="Escono prima" value={num(summary.early_exit)} tone={summary.early_exit > 0 ? 'bad' : undefined} />
                <Stat label="Assenti" value={num(summary.absent)} tone={summary.absent > 0 ? 'bad' : undefined} />
                <Stat label="Assegnati alle linee" value={`${num(summary.assigned)} / ${num(summary.required)}`} />
            </div>

            {short.length > 0 && (
                <p className="mb-4 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200">
                    Sotto organico: {short.map((l) => `${l.name} (mancano ${l.missing})`).join(', ')}. Nel piano linee queste linee sono evidenziate.
                </p>
            )}

            <div className="grid gap-4 xl:grid-cols-2">
                {crews.lines.map((line) => {
                    const work = load[line.id];

                    return (
                        <Card
                            key={line.id}
                            title={`${line.name} · ${line.description}`}
                            aside={(
                                <span className="flex items-center gap-1.5">
                                    {work?.blocks > 0 && <Chip>{plural(work.blocks, 'ordine', 'ordini')} · {plural(work.pallets, 'pedana', 'pedane')} · fine {work.end}</Chip>}
                                    <Chip tone={line.missing > 0 ? 'bad' : 'ok'}>{line.assigned.length} / {line.required}{line.missing > 0 ? ` · mancano ${line.missing}` : ' · al completo'}</Chip>
                                </span>
                            )}
                        >
                            <div className="grid gap-4 md:grid-cols-2">
                                <div>
                                    <h3 className="mb-1 text-xs font-medium uppercase tracking-wide text-om-muted">Assegnati ({line.assigned.length})</h3>
                                    {line.assigned.length === 0 && <p className="py-2 text-sm text-om-muted">Nessuno ancora assegnato.</p>}
                                    <ul className="divide-y divide-om-line">
                                        {line.assigned.map((p) => (
                                            <Person
                                                key={p.id}
                                                person={p}
                                                action={!readOnly && <Btn small onClick={() => router.post(`${BASE}/squadre/rimuovi`, { person_id: p.id }, options)}>Togli</Btn>}
                                            />
                                        ))}
                                    </ul>
                                </div>
                                <div>
                                    <h3 className="mb-1 text-xs font-medium uppercase tracking-wide text-om-muted">Presenti e abilitati, liberi ({line.candidates.length})</h3>
                                    {line.candidates.length === 0 && <p className="py-2 text-sm text-om-muted">Nessun altro presente è abilitato su questa linea.</p>}
                                    <ul className="divide-y divide-om-line">
                                        {line.candidates.map((p) => (
                                            <Person
                                                key={p.id}
                                                person={p}
                                                action={!readOnly && <Btn small variant="primary" onClick={() => router.post(`${BASE}/squadre/assegna`, { person_id: p.id, line_id: line.id }, options)}>Assegna</Btn>}
                                            />
                                        ))}
                                    </ul>
                                    {line.unavailable.length > 0 && (
                                        <>
                                            <h3 className="mb-1 mt-3 text-xs font-medium uppercase tracking-wide text-om-muted">Abilitati ma non assegnabili ({line.unavailable.length})</h3>
                                            <ul className="divide-y divide-om-line opacity-70">
                                                {line.unavailable.map((p) => (
                                                    <Person key={p.id} person={p} action={<Chip tone="bad">{p.status === 'early_exit' ? 'esce prima' : 'assente'}</Chip>} />
                                                ))}
                                            </ul>
                                        </>
                                    )}
                                </div>
                            </div>
                        </Card>
                    );
                })}
            </div>

            <Card title={`Fuori linea (${crews.off_line.length})`} className="mt-4">
                <p className="mb-2 text-xs text-om-muted">Carrellisti, controllo qualità e addetti alle pedane: non entrano nel costo dei fogli di lavorazione.</p>
                <div className="flex flex-wrap gap-1.5">
                    {crews.off_line.map((p) => (
                        <Chip key={p.id} tone={p.available ? 'neutral' : 'bad'} title={p.reason || p.qualification}>{p.name} · {p.qualification}</Chip>
                    ))}
                </div>
            </Card>
        </Shell>
    );
}

Crews.layout = (page) => <AppLayout>{page}</AppLayout>;
