import { useEffect, useMemo, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import AppLayout from '@core/layouts/AppLayout';
import Shell from '../../ortofrutta/Shell';
import { Btn, Card, Chip, Stat } from '../../ortofrutta/ui';
import { BASE, num, plural } from '../../ortofrutta/format';

// The selected production must stay selected after an action, so the page keeps its state.
const post = (path, data = {}) => router.post(`${BASE}/squadre/${path}`, data, { preserveScroll: true, preserveState: true });

const STATUS = {
    planned: { label: 'In piano', tone: 'neutral' },
    running: { label: 'In lavorazione', tone: 'info' },
    closed: { label: 'Chiusa', tone: 'ok' },
};

const overlap = (a, b) => a.start_min < b.end_min && b.start_min < a.end_min;

/**
 * Whether a person can join a production, and if not, why. The server checks
 * the same rules again when the button is pressed.
 */
function availability(person, production, productions) {
    if (person.status === 'absent') {
        return { ok: false, why: person.reason };
    }
    const from = person.from ?? 0;
    const until = person.until ?? Infinity;
    if (!(from < production.end_min && until > production.start_min)) {
        return { ok: false, why: person.reason };
    }
    const clash = productions.find((other) => other.key !== production.key
        && other.crew.some((c) => c.id === person.id) && overlap(other, production));
    if (clash) {
        return { ok: false, why: `Su ${clash.line} ${clash.start}–${clash.end}` };
    }

    return { ok: true, partial: until < production.end_min, why: until < production.end_min ? person.reason : null };
}

function PersonRow({ person, note, tone, action }) {
    return (
        <li className="flex items-center justify-between gap-2 py-1.5">
            <div className="min-w-0">
                <div className="truncate text-sm text-om-ink">
                    {person.name}
                    {person.is_leader && <span className="ml-1.5 text-xs font-medium text-om-accent">capolinea</span>}
                </div>
                <div className="truncate text-xs text-om-muted">
                    {person.qualification} · {person.badge}
                    {note && <span className={tone === 'bad' ? ' font-medium text-red-600 dark:text-red-400' : ''}> · {note}</span>}
                </div>
            </div>
            {action}
        </li>
    );
}

function ProductionRow({ production, selected, onSelect }) {
    const status = STATUS[production.status];
    const short = production.status !== 'closed' && production.missing > 0;

    return (
        <li>
            <button
                type="button"
                onClick={() => onSelect(production.key)}
                aria-pressed={selected}
                className={`w-full rounded-lg border px-3 py-2 text-left transition-colors ${
                    selected ? 'border-om-accent bg-om-chip ring-1 ring-om-accent' : 'border-om-line bg-om-card hover:bg-om-chip'
                }`}
            >
                <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                        <div className="text-sm font-medium tabular-nums text-om-ink">
                            {production.start}–{production.end}
                            <span className="ml-2 font-normal text-om-muted">{plural(production.pallets, 'pedana', 'pedane')}</span>
                        </div>
                        <div className="truncate text-sm text-om-ink">{production.variety} {production.size} · {production.format}</div>
                        <div className="truncate text-xs text-om-muted">{production.customers.join(', ')}</div>
                    </div>
                    <div className="flex shrink-0 flex-col items-end gap-1">
                        <Chip tone={production.status === 'closed' ? 'neutral' : short ? 'bad' : 'ok'}>
                            {production.crew.length}/{production.required} pers.
                        </Chip>
                        <Chip tone={status.tone}>{status.label}{production.sheet_number ? ` · ${production.sheet_number}` : ''}</Chip>
                    </div>
                </div>
                {production.status !== 'closed' && (short || !production.has_leader || production.crew.some((c) => c.issue)) && (
                    <div className="mt-1 flex flex-wrap gap-1">
                        {short && <Chip tone="bad">mancano {production.missing}</Chip>}
                        {!production.has_leader && <Chip tone="warn">senza capolinea</Chip>}
                        {production.crew.some((c) => c.issue) && <Chip tone="warn">da controllare</Chip>}
                    </div>
                )}
            </button>
        </li>
    );
}

export default function Crews({ crews, focus }) {
    const { orto } = usePage().props;
    const productions = crews?.productions ?? [];
    const people = crews?.people ?? [];

    const first = () => (productions.find((p) => p.key === focus)
        ?? productions.find((p) => p.status !== 'closed' && p.missing > 0)
        ?? productions.find((p) => p.status !== 'closed')
        ?? productions[0])?.key ?? null;
    const [selectedKey, setSelectedKey] = useState(first);

    // The plan can change under the page (another day, an order moved): fall back to a production that exists.
    useEffect(() => {
        if (!productions.some((p) => p.key === selectedKey)) {
            setSelectedKey(first());
        }
    }, [crews]);

    const byId = useMemo(() => Object.fromEntries(people.map((p) => [p.id, p])), [people]);
    // What each person is booked on today, in time order.
    const bookings = useMemo(() => {
        const out = {};
        [...productions].sort((a, b) => a.start_min - b.start_min).forEach((production) => {
            production.crew.forEach((c) => (out[c.id] ??= []).push(production));
        });

        return out;
    }, [productions]);

    if (!crews) {
        return <Shell title="Squadre" active="/squadre" dayPicker>{null}</Shell>;
    }

    const { summary } = crews;
    const selected = productions.find((p) => p.key === selectedKey) ?? null;
    const readOnly = !orto.is_demo_day;
    const editable = selected !== null && !readOnly && selected.status !== 'closed';

    // The crew of the production before on the same line: who it is natural to keep.
    const before = selected
        ? productions.filter((p) => p.line_id === selected.line_id && p.start_min < selected.start_min)
            .sort((a, b) => b.start_min - a.start_min)[0] ?? null
        : null;
    const wasBefore = new Set((before?.crew ?? []).map((c) => c.id));

    const free = [];
    const taken = [];
    if (selected) {
        const onIt = new Set(selected.crew.map((c) => c.id));
        people.filter((p) => p.enabled_lines.includes(selected.line_code) && !onIt.has(p.id)).forEach((person) => {
            const state = availability(person, selected, productions);
            (state.ok ? free : taken).push({ person, ...state });
        });
        const rank = ({ person, partial }) => [
            partial ? 1 : 0,
            !selected.has_leader && person.is_leader ? 0 : 1,
            wasBefore.has(person.id) ? 0 : 1,
            person.is_leader ? 1 : 0,
            person.enabled_lines.length,
        ];
        free.sort((a, b) => {
            const [x, y] = [rank(a), rank(b)];
            const i = x.findIndex((v, k) => v !== y[k]);

            return i === -1 ? a.person.name.localeCompare(b.person.name) : x[i] - y[i];
        });
    }

    const shortList = productions.filter((p) => p.status !== 'closed' && p.missing > 0);
    const linePeople = people.filter((p) => p.enabled_lines.length > 0);
    const offLine = people.filter((p) => p.enabled_lines.length === 0);

    return (
        <Shell
            title="Squadre"
            active="/squadre"
            dayPicker
            actions={!readOnly && (
                <Btn variant="primary" onClick={() => post('proponi')} title="Completa la squadra di ogni produzione con i presenti abilitati, tenendo le stesse persone sulla linea da una produzione alla successiva">
                    Proponi squadre
                </Btn>
            )}
        >
            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
                <Stat label="Presenti tutto il turno" value={`${num(summary.present)} / ${num(summary.people)}`} tone="ok" />
                <Stat label="Escono prima" value={num(summary.early_exit)} tone={summary.early_exit > 0 ? 'bad' : undefined} />
                <Stat label="Assenti" value={num(summary.absent)} tone={summary.absent > 0 ? 'bad' : undefined} />
                <Stat
                    label="Produzioni con squadra"
                    value={`${num(summary.covered)} / ${num(summary.productions)}`}
                    tone={summary.short > 0 ? 'bad' : 'ok'}
                    hint={summary.short > 0 ? `${summary.short} senza tutte le persone` : 'tutte complete'}
                />
                <Stat label="Senza produzione" value={num(summary.idle)} hint="presenti, abilitati a una linea, non impegnati" />
            </div>

            {!readOnly && shortList.length > 0 && (
                <p className="mb-4 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200">
                    Squadra incompleta: {shortList.slice(0, 6).map((p) => `${p.line} delle ${p.start} (mancano ${p.missing})`).join(', ')}{shortList.length > 6 ? `, e altre ${shortList.length - 6}` : ''}.
                </p>
            )}

            {productions.length === 0 && (
                <p className="rounded-xl border border-dashed border-om-line bg-om-card px-6 py-10 text-center text-sm text-om-muted">
                    Nessuna produzione in piano per questa giornata. Le produzioni nascono dal <Link href={`${BASE}/piano`} className="text-om-accent hover:underline">piano linee</Link>: gli ordini dello stesso articolo, uno dopo l'altro sulla stessa linea.
                </p>
            )}

            {productions.length > 0 && (
                <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,5fr)_minmax(0,4fr)]">
                    <div className="grid gap-4 md:grid-cols-2">
                        {crews.lines.map((line) => {
                            const rows = productions.filter((p) => p.line_id === line.id);

                            return (
                                <Card
                                    key={line.id}
                                    title={line.name}
                                    aside={<span className="text-xs text-om-muted">{line.description} · {plural(rows.length, 'produzione', 'produzioni')}</span>}
                                >
                                    {rows.length === 0
                                        ? <p className="text-sm text-om-muted">Nessuna produzione oggi.</p>
                                        : (
                                            <ul className="space-y-2">
                                                {rows.map((p) => <ProductionRow key={p.key} production={p} selected={p.key === selectedKey} onSelect={setSelectedKey} />)}
                                            </ul>
                                        )}
                                </Card>
                            );
                        })}
                    </div>

                    {selected && (
                        <Card
                            className="lg:sticky lg:top-4"
                            title={`${selected.line} · ${selected.start}–${selected.end}`}
                            aside={(
                                <Chip tone={selected.status === 'closed' ? 'neutral' : selected.missing > 0 ? 'bad' : 'ok'}>
                                    {selected.crew.length} / {selected.required}{selected.status === 'closed' ? '' : selected.missing > 0 ? ` · mancano ${selected.missing}` : ' · al completo'}
                                </Chip>
                            )}
                        >
                            <div className="text-sm text-om-ink">{selected.item}</div>
                            <div className="mt-0.5 text-xs text-om-muted">
                                {plural(selected.pallets, 'pedana', 'pedane')} per {selected.customers.join(', ')}
                                {selected.truck ? ` · camion delle ${selected.truck}` : ''}
                                {selected.late ? ' · oltre il camion' : ''}
                            </div>
                            <div className="mt-2 flex flex-wrap items-center gap-1.5">
                                <Chip tone={STATUS[selected.status].tone}>{STATUS[selected.status].label}</Chip>
                                {selected.sheet_id && <Link href={`${BASE}/fogli/${selected.sheet_id}`} className="text-xs text-om-accent hover:underline">Foglio {selected.sheet_number} →</Link>}
                                {editable && before && (
                                    <Btn small onClick={() => post('copia', { production: selected.key })} title={`Porta qui chi era sulla produzione delle ${before.start}`}>
                                        Stessa squadra delle {before.start}
                                    </Btn>
                                )}
                                {editable && selected.crew.length > 0 && (
                                    <Btn small variant="danger" onClick={() => post('svuota', { production: selected.key })}>Svuota</Btn>
                                )}
                            </div>
                            {selected.status === 'closed' && <p className="mt-2 text-xs text-om-muted">Produzione chiusa: la squadra è quella scritta sul foglio di lavorazione e non cambia più.</p>}
                            {selected.status === 'running' && !readOnly && <p className="mt-2 text-xs text-om-muted">Produzione in corso: ogni cambio aggiorna subito le persone e il costo del foglio.</p>}

                            <h3 className="mb-1 mt-4 text-xs font-medium uppercase tracking-wide text-om-muted">In squadra ({selected.crew.length})</h3>
                            {selected.crew.length === 0 && <p className="py-2 text-sm text-om-muted">Nessuno ancora assegnato a questa produzione.</p>}
                            <ul className="max-h-80 divide-y divide-om-line overflow-y-auto">
                                {selected.crew.map((c) => byId[c.id] && (
                                    <PersonRow
                                        key={c.id}
                                        person={byId[c.id]}
                                        note={c.note}
                                        tone={c.issue ? 'bad' : undefined}
                                        action={editable && <Btn small onClick={() => post('rimuovi', { production: selected.key, person_id: c.id })}>Togli</Btn>}
                                    />
                                ))}
                            </ul>

                            {editable && (
                                <>
                                    <h3 className="mb-1 mt-4 text-xs font-medium uppercase tracking-wide text-om-muted">Liberi in quell'orario ({free.length})</h3>
                                    {free.length === 0 && <p className="py-2 text-sm text-om-muted">Nessun altro presente abilitato su {selected.line} è libero dalle {selected.start} alle {selected.end}.</p>}
                                    <ul className="max-h-80 divide-y divide-om-line overflow-y-auto">
                                        {free.map(({ person, partial, why }) => (
                                            <PersonRow
                                                key={person.id}
                                                person={person}
                                                note={partial ? why : wasBefore.has(person.id) ? `era sulla produzione delle ${before.start}` : null}
                                                tone={partial ? 'bad' : undefined}
                                                action={<Btn small variant="primary" onClick={() => post('assegna', { production: selected.key, person_id: person.id })}>Assegna</Btn>}
                                            />
                                        ))}
                                    </ul>

                                    {taken.length > 0 && (
                                        <details className="mt-3">
                                            <summary className="cursor-pointer text-xs font-medium uppercase tracking-wide text-om-muted">Abilitati ma non disponibili ({taken.length})</summary>
                                            <ul className="mt-1 max-h-60 divide-y divide-om-line overflow-y-auto opacity-80">
                                                {taken.map(({ person, why }) => <PersonRow key={person.id} person={person} note={why} />)}
                                            </ul>
                                        </details>
                                    )}
                                </>
                            )}
                        </Card>
                    )}
                </div>
            )}

            <Card title={`Chi fa cosa oggi (${linePeople.length} persone di linea)`} className="mt-4">
                <p className="mb-2 text-xs text-om-muted">Ogni persona con le produzioni su cui è impegnata, in ordine di orario. Chi non ne ha è libero.</p>
                <div className="max-h-96 overflow-y-auto">
                    <table className="w-full border-collapse text-sm">
                        <thead>
                            <tr className="border-b border-om-line text-left text-[11px] uppercase tracking-wide text-om-muted">
                                <th className="px-2 py-2 font-medium">Persona</th>
                                <th className="px-2 py-2 font-medium">Abilitata su</th>
                                <th className="px-2 py-2 font-medium">Presenza</th>
                                <th className="px-2 py-2 font-medium">Produzioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            {linePeople.map((person) => {
                                const mine = bookings[person.id] ?? [];

                                return (
                                    <tr key={person.id} className="border-b border-om-line last:border-0">
                                        <td className="px-2 py-1.5 text-om-ink">
                                            {person.name}
                                            <div className="text-xs text-om-muted">{person.qualification}</div>
                                        </td>
                                        <td className="px-2 py-1.5 text-xs text-om-muted">{person.enabled_lines.join(', ')}</td>
                                        <td className="px-2 py-1.5">
                                            <Chip tone={person.status === 'present' ? 'ok' : 'bad'}>{person.status === 'present' ? 'Tutto il turno' : person.reason}</Chip>
                                        </td>
                                        <td className="px-2 py-1.5">
                                            {mine.length === 0
                                                ? <span className="text-xs text-om-muted">{person.status === 'absent' ? '—' : 'libera'}</span>
                                                : (
                                                    <span className="flex flex-wrap gap-1">
                                                        {mine.map((p) => (
                                                            <button key={p.key} type="button" onClick={() => setSelectedKey(p.key)} className="rounded-full bg-om-chip px-2 py-0.5 text-[11px] font-medium tabular-nums text-om-ink hover:underline">
                                                                {p.line_code} {p.start}–{p.end}
                                                            </button>
                                                        ))}
                                                    </span>
                                                )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </Card>

            <Card title={`Fuori linea (${offLine.length})`} className="mt-4">
                <p className="mb-2 text-xs text-om-muted">Carrellisti, controllo qualità e addetti alle pedane: non entrano nelle squadre né nel costo dei fogli di lavorazione.</p>
                <div className="flex flex-wrap gap-1.5">
                    {offLine.map((p) => (
                        <Chip key={p.id} tone={p.status === 'absent' ? 'bad' : 'neutral'} title={p.reason || p.qualification}>{p.name} · {p.qualification}</Chip>
                    ))}
                </div>
            </Card>
        </Shell>
    );
}

Crews.layout = (page) => <AppLayout>{page}</AppLayout>;
