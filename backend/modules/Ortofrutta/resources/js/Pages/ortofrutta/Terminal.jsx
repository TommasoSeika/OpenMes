import { useEffect, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AppLayout from '@core/layouts/AppLayout';
import { apiCall } from '@core/lib/http';
import Shell from '../../ortofrutta/Shell';
import Label from '../../ortofrutta/Label';
import { Btn, Chip, inputClass } from '../../ortofrutta/ui';
import { BASE, kg, plural } from '../../ortofrutta/format';

const HERE = `${BASE}/terminale`;

// How long the simulated camera "reads" a label, and how long its answer stays before the next step.
const READ_MS = 900;
const NEXT_MS = 1600;

const GROUPS = [
    ['driver', 'Carrellisti'],
    ['line', 'Operatori di linea'],
    ['other', 'Altri'],
];

function Steps({ step }) {
    const item = (n, label, current, done) => (
        <div className={`flex flex-1 items-center gap-2 rounded-lg px-2.5 py-1.5 text-sm ${current ? 'bg-om-ink font-medium text-om-on-ink' : done ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-om-chip text-om-muted'}`}>
            <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-current text-[11px]">{done ? '✓' : n}</span>
            {label}
        </div>
    );

    return (
        <div className="flex gap-1.5">
            {item(1, 'Prendi', step === 'pick', step === 'deliver')}
            {item(2, 'Porta', step === 'deliver', false)}
        </div>
    );
}

/** The mission in hand: what to do now, and the labels in front of the driver to read. */
function Mission({ mission, labels, onDone, list }) {
    const [phase, setPhase] = useState('idle');   // idle | reading | answer
    const [target, setTarget] = useState(null);
    const [answer, setAnswer] = useState(null);
    const [typed, setTyped] = useState('');
    const timers = useRef([]);

    // A new step starts clean; timers of the step before must not fire into it.
    useEffect(() => {
        setPhase('idle');
        setTarget(null);
        setAnswer(null);
        setTyped('');

        return () => timers.current.forEach(clearTimeout);
    }, [mission.id, mission.step]);

    const later = (fn, ms) => timers.current.push(setTimeout(fn, ms));

    const send = async (code) => {
        try {
            const response = await apiCall(`${HERE}/scansiona`, 'POST', { mission_id: mission.id, code });
            const data = await response.json().catch(() => ({}));
            const ok = response.ok && data.ok === true;
            setAnswer({ ok, message: data.message || (ok ? 'Fatto.' : 'Lettura non accettata.') });
            setPhase('answer');
            if (ok) {
                later(onDone, NEXT_MS);
            }
        } catch {
            setAnswer({ ok: false, message: 'Connessione non riuscita: riprova.' });
            setPhase('answer');
        }
    };

    const read = (code) => {
        if (phase === 'reading' || !code) {
            return;
        }
        setTarget(code);
        setAnswer(null);
        setPhase('reading');
        later(() => send(code), READ_MS);
    };

    const pick = mission.step === 'pick';
    const stateOf = (label) => {
        if (label.code !== target) {
            return undefined;
        }
        if (phase === 'reading') {
            return 'scanning';
        }

        return answer ? (answer.ok ? 'ok' : 'bad') : undefined;
    };

    return (
        <div className="grid items-start gap-3 md:grid-cols-2">
            <div className="space-y-3">
            <div className="rounded-xl border border-om-line bg-om-card p-3">
                <div className="mb-2 flex items-center justify-between gap-2">
                    <span className="text-sm font-semibold tabular-nums text-om-ink">Missione {mission.code}</span>
                    <Chip tone={mission.late ? 'bad' : 'neutral'}>{mission.late ? 'linea in attesa · ' : ''}entro {mission.due}</Chip>
                </div>
                <Steps step={mission.step} />

                <div className="mt-3">
                    {pick ? (
                        <>
                            <div className="text-xs uppercase tracking-wide text-om-muted">Vai a</div>
                            <div className="text-2xl font-bold leading-tight text-om-ink">Cella {mission.cell ?? '—'}</div>
                            <div className="text-xs text-om-muted">{mission.building}</div>
                            <div className="mt-2 text-sm text-om-ink">
                                Prendi <strong>{plural(mission.bins, 'bins', 'bins')}</strong> del lotto <strong className="tabular-nums">{mission.lot}</strong>
                            </div>
                            <div className="text-xs text-om-muted">{mission.variety} · {mission.producer} · {kg(mission.kg)}</div>
                        </>
                    ) : (
                        <>
                            <div className="text-xs uppercase tracking-wide text-om-muted">Porta a</div>
                            <div className="text-2xl font-bold leading-tight text-om-ink">{mission.line}</div>
                            <div className="mt-2 text-sm text-om-ink">
                                <strong>{plural(mission.bins, 'bins', 'bins')}</strong> del lotto <strong className="tabular-nums">{mission.lot}</strong> per {mission.customer}
                            </div>
                            <div className="text-xs text-om-muted">Prelevati alle {mission.picked_at} dalla cella {mission.cell ?? '—'}</div>
                        </>
                    )}
                </div>
            </div>
            {list}
            </div>

            <div className="space-y-3">
            <div className="rounded-xl p-3" style={{ backgroundColor: '#171717' }}>
                <div className="mb-2 flex items-center justify-between gap-2 text-xs" style={{ color: '#d4d4d4' }}>
                    <span className="font-medium">{pick ? 'Inquadra l\'etichetta del bins' : 'Inquadra il codice della linea'}</span>
                    <span className="rounded px-1.5 py-0.5 text-[10px] uppercase tracking-wide" style={{ backgroundColor: '#404040' }}>fotocamera simulata</span>
                </div>
                <div className={pick ? 'space-y-2' : 'grid grid-cols-2 gap-2'}>
                    {labels.map((label) => (
                        <Label key={label.code} label={label} small={!pick} onClick={(l) => read(l.code)} state={stateOf(label)} disabled={phase === 'reading'} />
                    ))}
                </div>
                <p className="mt-2 text-[11px] leading-snug" style={{ color: '#a3a3a3' }}>
                    {pick
                        ? 'Sono le etichette che il carrellista ha davanti alla cella: toccane una per leggerla. Prova anche quella sbagliata.'
                        : 'Sono i codici all\'ingresso delle linee: tocca quello della linea a cui stai consegnando.'}
                </p>
            </div>

            <div aria-live="polite">
                {phase === 'reading' && <p className="rounded-lg bg-sky-100 px-3 py-2 text-sm font-medium text-sky-900 dark:bg-sky-900/40 dark:text-sky-200">Lettura in corso…</p>}
                {phase === 'answer' && answer && (
                    <p className={`rounded-lg px-3 py-2 text-sm font-medium ${answer.ok ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-200' : 'bg-red-100 text-red-900 dark:bg-red-900/40 dark:text-red-200'}`}>
                        {answer.ok ? '✓ ' : '✕ '}{answer.message}
                    </p>
                )}
            </div>

            <form
                className="flex gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    read(typed.trim());
                }}
            >
                <input
                    value={typed}
                    onChange={(e) => setTyped(e.target.value)}
                    placeholder={pick ? `es. LOT:${mission.lot}` : `es. LINE:${mission.line_code}`}
                    aria-label="Codice letto con il lettore o scritto a mano"
                    className={`${inputClass} font-mono`}
                />
                <Btn type="submit" disabled={phase === 'reading' || typed.trim() === ''}>Leggi</Btn>
            </form>
            <p className="text-[11px] text-om-muted">Con un lettore a pistola il codice arriva qui come se fosse scritto sulla tastiera.</p>
            </div>
        </div>
    );
}

function MissionList({ missions, activeId, onOpen }) {
    return (
        <ol className="divide-y divide-om-line rounded-xl border border-om-line bg-om-card">
            {missions.map((m, i) => {
                const done = m.status === 'delivered';

                return (
                    <li key={m.id}>
                        <button
                            type="button"
                            disabled={done}
                            onClick={() => onOpen(m.id)}
                            className={`flex w-full items-center gap-2.5 px-3 py-2 text-left ${m.id === activeId ? 'bg-om-chip' : ''} ${done ? 'opacity-60' : 'hover:bg-om-chip'}`}
                        >
                            <span className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold ${done ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-om-chip text-om-ink'}`}>{done ? '✓' : i + 1}</span>
                            <span className="min-w-0 flex-1">
                                <span className="block text-sm text-om-ink"><span className="font-semibold tabular-nums">{m.due}</span> · Cella {m.cell ?? '—'} → {m.line}</span>
                                <span className="block truncate text-xs text-om-muted">{plural(m.bins, 'bins', 'bins')} · {m.lot} · {m.variety}</span>
                            </span>
                            {done
                                ? <span className="text-xs tabular-nums text-om-muted">{m.delivered_at}</span>
                                : m.status === 'picked' ? <Chip tone="info">in viaggio</Chip> : m.late ? <Chip tone="bad">linea in attesa</Chip> : null}
                        </button>
                    </li>
                );
            })}
        </ol>
    );
}

function Day({ productions }) {
    if (productions.length === 0) {
        return <p className="rounded-xl border border-dashed border-om-line bg-om-card px-4 py-8 text-center text-sm text-om-muted">Oggi non sei ancora in nessuna squadra: le squadre le fa il capoturno.</p>;
    }

    return (
        <ol className="space-y-2">
            {productions.map((p, i) => (
                <li key={p.key} className={`rounded-xl border bg-om-card p-3 ${p.now ? 'border-om-accent ring-1 ring-om-accent' : 'border-om-line'}`}>
                    <div className="flex items-center justify-between gap-2">
                        <span className="text-sm font-semibold tabular-nums text-om-ink">{i + 1}. {p.start}–{p.end}</span>
                        {p.now ? <Chip tone="info">adesso</Chip> : p.status === 'closed' ? <Chip tone="ok">fatta</Chip> : <Chip>in programma</Chip>}
                    </div>
                    <div className="mt-1 text-xl font-bold leading-tight text-om-ink">{p.line}</div>
                    <div className="mt-0.5 text-sm text-om-ink">{p.item}</div>
                    <div className="text-xs text-om-muted">
                        {plural(p.pallets, 'pedana', 'pedane')} per {p.customers.join(', ')} · {plural(p.people, 'persona', 'persone')} in squadra{p.leader ? ` · capolinea ${p.leader}` : ''}
                    </div>
                </li>
            ))}
        </ol>
    );
}

export default function Terminal({ terminal }) {
    if (!terminal) {
        return <Shell title="Terminale" active="/terminale">{null}</Shell>;
    }

    const { person, people, missions, active, labels, productions } = terminal;
    const go = (params) => router.get(HERE, params, { preserveScroll: true, preserveState: true });
    const open = missions.filter((m) => m.status !== 'delivered');

    return (
        <Shell title="Terminale" active="/terminale">
            <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_21rem]">
                {/* The tablet on the forklift, or in the hand of the person on the floor. */}
                <div className="w-full rounded-[1.75rem] border-[6px] bg-om-panel p-3 shadow-xl" style={{ borderColor: '#262626' }}>
                    <div className="mb-3 flex items-center justify-between gap-2 px-1 text-xs text-om-muted">
                        <span className="font-semibold tabular-nums text-om-ink">{terminal.now}</span>
                        <span className="truncate">{person ? `${person.name} · ${person.badge}` : 'Nessuno in turno'}</span>
                    </div>

                    {person?.role === 'driver' && (() => {
                        const list = missions.length > 0 && (
                            <div>
                                <h3 className="mb-1.5 px-1 text-xs font-medium uppercase tracking-wide text-om-muted">La mia lista · {plural(open.length, 'missione da fare', 'missioni da fare')}</h3>
                                <MissionList missions={missions} activeId={active?.id} onOpen={(id) => go({ persona: person.id, missione: id })} />
                            </div>
                        );

                        return active
                            ? <Mission mission={active} labels={labels} list={list} onDone={() => router.reload({ preserveScroll: true })} />
                            : (
                                <div className="space-y-3">
                                    <p className="rounded-xl border border-dashed border-om-line bg-om-card px-4 py-8 text-center text-sm text-om-muted">
                                        {missions.length === 0
                                            ? 'Nessuna missione per te: le liste si generano dal piano linee.'
                                            : 'Hai finito: tutte le tue missioni sono consegnate.'}
                                    </p>
                                    {list}
                                </div>
                            );
                    })()}

                    {person?.role === 'line' && (
                        <div className="mx-auto max-w-xl space-y-2">
                            <h3 className="px-1 text-xs font-medium uppercase tracking-wide text-om-muted">La mia giornata · {plural(productions.length, 'produzione', 'produzioni')}</h3>
                            <Day productions={productions} />
                        </div>
                    )}

                    {person?.role === 'other' && (
                        <p className="rounded-xl border border-dashed border-om-line bg-om-card px-4 py-8 text-center text-sm text-om-muted">
                            Per {person.qualification.toLowerCase()} non c'è ancora una lista di lavoro in questa versione.
                        </p>
                    )}
                </div>

                {/* Outside the device: who is holding it, and what the scan is for. */}
                <div className="space-y-4">
                    <div className="rounded-xl border border-om-line bg-om-card p-4">
                        <label className="block">
                            <span className="mb-1 block text-xs font-medium text-om-muted">Chi ha in mano il terminale</span>
                            <select value={person?.id ?? ''} onChange={(e) => go({ persona: e.target.value })} className={inputClass}>
                                {GROUPS.map(([role, title]) => {
                                    const group = people.filter((p) => p.role === role);

                                    return group.length > 0 && (
                                        <optgroup key={role} label={title}>
                                            {group.map((p) => (
                                                <option key={p.id} value={p.id}>
                                                    {p.name} · {p.qualification}{p.open > 0 ? ` · ${p.open} da fare` : ''}
                                                </option>
                                            ))}
                                        </optgroup>
                                    );
                                })}
                            </select>
                        </label>
                        <p className="mt-2 text-xs text-om-muted">Sul campo ognuno entra con il proprio badge. Qui si sceglie la persona per vedere il suo terminale.</p>
                    </div>

                    <div className="rounded-xl border border-om-line bg-om-card p-4 text-sm text-om-ink">
                        <h2 className="mb-2 text-sm font-semibold">Cosa succede a ogni lettura</h2>
                        <ol className="list-decimal space-y-1.5 pl-5 text-om-muted">
                            <li><span className="text-om-ink">Etichetta del bins alla cella.</span> Se il lotto non è quello della missione il terminale lo rifiuta: alla linea non arriva la frutta sbagliata. Se è giusto i bins escono dalla giacenza della cella.</li>
                            <li><span className="text-om-ink">Codice della linea alla consegna.</span> Se la linea non è quella giusta il terminale lo dice. Se è giusta la missione si chiude e il lotto finisce sul foglio di lavorazione di quella produzione.</li>
                            <li><span className="text-om-ink">Tracciabilità.</span> Il foglio si compila con i lotti letti, non con quelli ricordati a fine turno: da lì si risale dal cliente al produttore.</li>
                        </ol>
                        <p className="mt-3 flex flex-wrap gap-x-4 gap-y-1">
                            <Link href={`${BASE}/missioni`} className="text-om-accent hover:underline">Liste di lavoro →</Link>
                            <Link href={`${BASE}/etichette`} className="text-om-accent hover:underline">Etichette con QR →</Link>
                            <Link href={`${BASE}/tracciabilita`} className="text-om-accent hover:underline">Tracciabilità →</Link>
                        </p>
                    </div>
                </div>
            </div>
        </Shell>
    );
}

Terminal.layout = (page) => <AppLayout>{page}</AppLayout>;
