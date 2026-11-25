import { Link, router, usePage } from '@inertiajs/react';
import AppLayout from '@core/layouts/AppLayout';
import Shell from '../../ortofrutta/Shell';
import { Btn, Card, Chip, Stat, Table } from '../../ortofrutta/ui';
import { BASE, kg, num, plural } from '../../ortofrutta/format';

const options = { preserveScroll: true };

function State({ mission }) {
    if (mission.status === 'delivered') {
        return <Chip tone="ok">Consegnata {mission.delivered_at}</Chip>;
    }
    if (mission.status === 'picked') {
        return <Chip tone="info">In viaggio dalle {mission.picked_at}</Chip>;
    }

    return mission.late ? <Chip tone="bad" title="La produzione è stata lanciata e i suoi bins non sono ancora alla linea">Linea in attesa</Chip> : <Chip>Da fare</Chip>;
}

/** One line of a driver's list: by when, from where to where, what. */
function Row({ mission, index }) {
    const done = mission.status === 'delivered';

    return (
        <li className={`flex items-start gap-3 py-2 ${done ? 'opacity-60' : ''}`}>
            <span className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-om-chip text-xs font-semibold tabular-nums text-om-ink">{index + 1}</span>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-x-2 text-sm text-om-ink">
                    <span className="font-semibold tabular-nums">entro {mission.due}</span>
                    <span className="whitespace-nowrap">Cella {mission.cell ?? '—'} → {mission.line}</span>
                    {mission.priority === 1 && <Chip tone="warn">priorità alta</Chip>}
                </div>
                <div className="truncate text-xs text-om-muted">
                    {plural(mission.bins, 'bins', 'bins')} del lotto <span className="font-medium text-om-ink">{mission.lot}</span> · {mission.variety} · {mission.customer}
                </div>
            </div>
            <State mission={mission} />
        </li>
    );
}

export default function Missions({ board }) {
    const { orto } = usePage().props;
    const readOnly = !orto.is_demo_day;

    if (!board) {
        return <Shell title="Liste di lavoro" active="/missioni" dayPicker>{null}</Shell>;
    }

    const { summary, missions, drivers } = board;
    const unassigned = missions.filter((m) => m.person_id === null && m.status !== 'delivered');
    const assign = (mission, personId) => router.post(`${BASE}/missioni/${mission.id}/assegna`, { person_id: Number(personId) }, options);

    return (
        <Shell
            title="Liste di lavoro"
            active="/missioni"
            dayPicker
            actions={!readOnly && (
                <Btn
                    variant="primary"
                    onClick={() => router.post(`${BASE}/missioni/genera`, {}, options)}
                    title="Dal piano linee: per ogni ordine i bins da portare, in ordine di orario e di priorità, divisi tra i carrellisti presenti"
                >
                    {summary.total === 0 ? 'Genera liste di lavoro' : 'Rigenera dal piano'}
                </Btn>
            )}
        >
            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
                <Stat label="Da fare" value={num(summary.todo)} hint={`${num(summary.bins_open)} bins ancora da portare`} />
                <Stat label="In viaggio" value={num(summary.picked)} tone={summary.picked > 0 ? 'ok' : undefined} hint="bins letti alla cella" />
                <Stat label="Consegnate" value={`${num(summary.delivered)} / ${num(summary.total)}`} tone={summary.total > 0 && summary.delivered === summary.total ? 'ok' : undefined} />
                <Stat
                    label="Linee in attesa"
                    value={num(summary.late)}
                    tone={summary.late > 0 ? 'bad' : 'ok'}
                    hint={summary.late > 0 ? 'produzioni lanciate senza i loro bins' : summary.next_due ? `prossima consegna entro le ${summary.next_due}` : 'nessuna consegna in sospeso'}
                />
                <Stat label="Carrellisti presenti" value={num(drivers.length)} hint="dalle timbrature di oggi" />
            </div>

            {!readOnly && board.stale && (
                <p className="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-200">
                    {summary.total === 0
                        ? 'Le liste di lavoro di oggi non sono ancora state generate: il piano linee dice già quali bins servono e quando.'
                        : 'Il piano linee è cambiato dopo l\'ultima generazione: le missioni da fare non corrispondono più a quello che serve alle linee.'}
                    {' '}Premi <strong>{summary.total === 0 ? 'Genera liste di lavoro' : 'Rigenera dal piano'}</strong>: le missioni già prelevate o consegnate restano.
                </p>
            )}

            {board.short.length > 0 && (
                <p className="mb-4 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200">
                    Frutta insufficiente in cella: {board.short.map((s) => `${kg(s.kg)} di ${s.variety} per ${s.customer} (${s.line}, entro ${s.by})`).join('; ')}.
                </p>
            )}

            {readOnly && summary.total === 0 && (
                <p className="rounded-xl border border-dashed border-om-line bg-om-card px-6 py-10 text-center text-sm text-om-muted">
                    Le liste di lavoro esistono per la giornata in corso: per le giornate passate restano i fogli di lavorazione e la tracciabilità.
                </p>
            )}

            {(!readOnly || summary.total > 0) && (
                <>
                    <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-sm font-semibold text-om-ink">Carrellisti: cosa prendere, dove, dove portarlo, entro quando</h2>
                        <span className="flex gap-3 text-sm">
                            <Link href={`${BASE}/terminale`} className="text-om-accent hover:underline">Apri il terminale →</Link>
                            <Link href={`${BASE}/etichette`} className="text-om-accent hover:underline">Etichette con QR →</Link>
                        </span>
                    </div>
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {drivers.map((driver) => {
                            const mine = missions.filter((m) => m.person_id === driver.id);

                            return (
                                <Card
                                    key={driver.id}
                                    title={driver.name}
                                    aside={(
                                        <span className="flex items-center gap-1.5">
                                            {driver.reason && <Chip tone="bad">{driver.reason}</Chip>}
                                            <Chip tone={driver.open > 0 ? 'info' : 'neutral'}>{plural(driver.open, 'missione', 'missioni')} · {num(driver.bins)} bins</Chip>
                                        </span>
                                    )}
                                >
                                    {mine.length === 0
                                        ? <p className="text-sm text-om-muted">Nessuna missione.</p>
                                        : <ol className="divide-y divide-om-line">{mine.map((m, i) => <Row key={m.id} mission={m} index={i} />)}</ol>}
                                    <p className="mt-2 text-xs">
                                        <Link href={`${BASE}/terminale?persona=${driver.id}`} className="text-om-accent hover:underline">Vedi il suo terminale →</Link>
                                    </p>
                                </Card>
                            );
                        })}
                    </div>

                    {unassigned.length > 0 && (
                        <p className="mt-4 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200">
                            {plural(unassigned.length, 'missione senza carrellista', 'missioni senza carrellista')}: nessuno dei presenti è in turno all'ora in cui servono.
                        </p>
                    )}

                    <Card title="Tutte le missioni, in ordine di lavoro" className="mt-4" aside={<span className="text-xs text-om-muted">orario in cui servono, poi priorità dell'ordine</span>}>
                        <Table
                            rows={missions}
                            empty="Nessuna missione: genera le liste dal piano linee."
                            rowClass={(m) => (m.status === 'delivered' ? 'opacity-60' : m.late ? 'bg-red-50 dark:bg-red-950/30' : '')}
                            columns={[
                                { key: 'due', label: 'Entro', render: (m) => <span className="font-semibold tabular-nums">{m.due}</span> },
                                { key: 'code', label: 'Missione', render: (m) => <span className="tabular-nums">{m.code}</span> },
                                { key: 'cell', label: 'Prendi da', render: (m) => <div className="whitespace-nowrap">Cella {m.cell ?? '—'}<div className="text-xs text-om-muted">{m.building}</div></div> },
                                { key: 'lot', label: 'Cosa', render: (m) => <div><span className="font-medium">{m.lot}</span> · {plural(m.bins, 'bins', 'bins')}<div className="text-xs text-om-muted">{m.variety} · {m.producer} · {kg(m.kg)}</div></div> },
                                { key: 'line', label: 'Porta a', render: (m) => <div className="whitespace-nowrap">{m.line}<div className="text-xs text-om-muted">{m.customer} · {m.order}</div></div> },
                                {
                                    key: 'person', label: 'Carrellista', render: (m) => (readOnly || m.status === 'delivered'
                                        ? (m.person ?? '—')
                                        : (
                                            <select
                                                value={m.person_id ?? ''}
                                                onChange={(e) => e.target.value && assign(m, e.target.value)}
                                                aria-label={`Carrellista della missione ${m.code}`}
                                                className="max-w-[11rem] rounded-lg border border-om-line bg-om-card px-2 py-1 text-sm text-om-ink"
                                            >
                                                {m.person_id === null && <option value="">—</option>}
                                                {drivers.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                                            </select>
                                        )),
                                },
                                { key: 'status', label: 'Stato', render: (m) => <State mission={m} /> },
                            ]}
                        />
                    </Card>

                    <Card title="Operatori di linea" className="mt-4">
                        <p className="text-sm text-om-muted">
                            La lista di ogni operatore sono le produzioni su cui è in squadra, nell'ordine in cui le farà: nascono in{' '}
                            <Link href={`${BASE}/squadre`} className="text-om-accent hover:underline">Squadre</Link> e ognuno le vede sul proprio{' '}
                            <Link href={`${BASE}/terminale`} className="text-om-accent hover:underline">terminale</Link>.
                        </p>
                    </Card>
                </>
            )}
        </Shell>
    );
}

Missions.layout = (page) => <AppLayout>{page}</AppLayout>;
