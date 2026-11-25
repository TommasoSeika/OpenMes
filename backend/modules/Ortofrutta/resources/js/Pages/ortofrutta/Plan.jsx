import { Fragment, useEffect, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import AppLayout from '@core/layouts/AppLayout';
import { apiCall } from '@core/lib/http';
import Shell from '../../ortofrutta/Shell';
import { Btn, Chip } from '../../ortofrutta/ui';
import { BASE, ORDER_TYPES, SETUP_LABELS, num, plural } from '../../ortofrutta/format';

const BACKLOG = 'backlog';
const END = 500;

function TruckChip({ block }) {
    if (!block.truck) {
        return <Chip>Senza camion</Chip>;
    }
    if (block.late) {
        return <Chip tone="bad">Camion {block.truck} · oltre di {block.late_minutes}′</Chip>;
    }

    return <Chip tone={block.truck === '15:00' ? 'info' : 'neutral'}>Camion {block.truck}</Chip>;
}

function Block({ block, index, count, line, lines, readOnly, busy, onMove, onDragStart, onDragEnd, onDragOver, firstOfLaunch, launchPallets, canLaunch, onLaunch }) {
    const planned = line !== null;
    const movable = !readOnly && !block.locked;
    const targets = lines.filter((l) => l.formats.some((f) => f.format === block.format) && l.id !== line?.id);

    return (
        <li
            draggable={movable}
            onDragStart={(e) => onDragStart(e, block)}
            onDragEnd={onDragEnd}
            onDragOver={(e) => onDragOver(e, index)}
            className={`rounded-lg border px-3 py-2 text-sm shadow-sm ${movable ? 'cursor-grab active:cursor-grabbing' : ''} ${
                block.late
                    ? 'border-red-400 bg-red-50 dark:border-red-700 dark:bg-red-950/40'
                    : block.locked
                        ? 'border-om-line bg-om-chip'
                        : 'border-om-line bg-om-card'
            }`}
        >
            {planned && firstOfLaunch && (
                <div className="mb-1.5 flex items-center justify-between gap-2 border-b border-dashed border-om-line pb-1.5 text-[11px] uppercase tracking-wide text-om-muted">
                    <span>
                        {block.locked ? `Foglio ${block.sheet_number}` : 'Lancio'} · {block.item_code} · {num(launchPallets)} pedane
                    </span>
                    {block.locked && (
                        <Link href={`${BASE}/fogli/${block.sheet_id}`} className="font-medium normal-case text-om-accent hover:underline">
                            {block.sheet_status === 'closed' ? 'chiuso' : 'apri il foglio'}
                        </Link>
                    )}
                    {canLaunch && <Btn small variant="primary" disabled={busy} onClick={onLaunch}>Lancia</Btn>}
                </div>
            )}
            {planned && firstOfLaunch && block.crew_required != null && block.sheet_status !== 'closed' && (
                <Link
                    href={`${BASE}/squadre?produzione=${block.id}`}
                    title="Apri la squadra di questa produzione"
                    className={`mb-1.5 inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium hover:underline ${
                        block.crew_people < block.crew_required
                            ? 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300'
                            : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300'
                    }`}
                >
                    Squadra {block.crew_people}/{block.crew_required}
                </Link>
            )}

            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <div className="truncate font-medium text-om-ink">{block.customer}</div>
                    <div className="truncate text-xs text-om-muted">{block.variety} {block.size} · {block.format}</div>
                    <div className="truncate text-xs text-om-muted">{block.packaging}</div>
                </div>
                <div className="shrink-0 text-right">
                    <div className="font-semibold tabular-nums text-om-ink">{block.pallets} ped.</div>
                    {planned && <div className="text-xs tabular-nums text-om-muted">{block.start}–{block.end}</div>}
                </div>
            </div>

            <div className="mt-1.5 flex flex-wrap items-center gap-1">
                <TruckChip block={block} />
                <Chip>{ORDER_TYPES[block.type]}</Chip>
                {planned && block.setup_minutes > 0 && (
                    <Chip tone={block.setup_type === 'startup' ? 'neutral' : 'warn'}>{SETUP_LABELS[block.setup_type]} {block.setup_minutes}′</Chip>
                )}
                {planned && block.wait_minutes > 0 && <Chip title="La linea aspetta che l'ordine arrivi">Attesa {block.wait_minutes}′</Chip>}
                {!planned && <Chip>Arrivato {block.arrived}</Chip>}
            </div>

            <div className="mt-1.5 flex items-center justify-between gap-2 text-[11px] text-om-muted">
                <span className="tabular-nums">{block.number}</span>
                {movable && (
                    <span className="flex items-center gap-1">
                        {planned && (
                            <>
                                <button type="button" className="rounded px-1 hover:bg-om-chip disabled:opacity-30" disabled={busy || index === 0} onClick={() => onMove(block, line.id, index - 1)} aria-label="Sposta prima">▲</button>
                                <button type="button" className="rounded px-1 hover:bg-om-chip disabled:opacity-30" disabled={busy || index === count - 1} onClick={() => onMove(block, line.id, index + 1)} aria-label="Sposta dopo">▼</button>
                            </>
                        )}
                        <select
                            value=""
                            disabled={busy}
                            onChange={(e) => e.target.value && onMove(block, e.target.value === BACKLOG ? null : Number(e.target.value), END)}
                            className="rounded border border-om-line bg-om-card px-1 py-0.5 text-[11px] text-om-ink"
                            aria-label="Sposta su un'altra linea"
                        >
                            <option value="">Sposta su…</option>
                            {targets.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
                            {planned && <option value={BACKLOG}>Da pianificare</option>}
                        </select>
                    </span>
                )}
            </div>
        </li>
    );
}

export default function Plan({ board: initial }) {
    const { orto } = usePage().props;
    const [board, setBoard] = useState(initial);
    const [dragging, setDragging] = useState(null);
    const [over, setOver] = useState(null);
    const [notice, setNotice] = useState(null);
    const [busy, setBusy] = useState(false);
    const readOnly = !orto.is_demo_day;

    // A different day (or a reload after "Ripristina dati demo") brings a new board.
    useEffect(() => setBoard(initial), [initial]);

    const call = async (path, body) => {
        setBusy(true);
        try {
            const response = await apiCall(`${BASE}${path}`, 'POST', body);
            const data = await response.json().catch(() => ({}));
            if (data.board) {
                setBoard(data.board);
            }
            if (!response.ok) {
                setNotice({ tone: 'bad', text: data.message || 'Operazione non riuscita.' });
            } else {
                setNotice(data.message ? { tone: 'ok', text: data.message, sheetId: data.sheet_id } : null);
            }
        } catch {
            setNotice({ tone: 'bad', text: 'Connessione non riuscita: il piano non è stato modificato.' });
        } finally {
            setBusy(false);
        }
    };

    const move = (block, lineId, position) => call('/piano/sposta', { order_id: block.id, line_id: lineId, position: Math.max(0, position) });

    const onDragStart = (e, block) => {
        e.dataTransfer.setData('text/plain', String(block.id));
        e.dataTransfer.effectAllowed = 'move';
        setDragging(block.id);
    };
    const onDragEnd = () => {
        setDragging(null);
        setOver(null);
    };

    // `index` is where the card would be inserted, counting the dragged card too.
    const drop = (e, key, blocks) => {
        e.preventDefault();
        const id = Number(e.dataTransfer.getData('text/plain')) || dragging;
        const index = over && over.key === key ? over.index : blocks.length;
        onDragEnd();
        if (!id) {
            return;
        }
        const from = blocks.findIndex((b) => b.id === id);
        const position = from !== -1 && index > from ? index - 1 : index;
        if (from !== -1 && position === from) {
            return;
        }
        move({ id }, key === BACKLOG ? null : key, position);
    };

    if (!board) {
        return <Shell title="Piano linee" active="/piano" dayPicker>{null}</Shell>;
    }

    const column = (key, line, blocks, header) => (
        <div key={key} className="flex w-72 shrink-0 flex-col rounded-xl border border-om-line bg-om-panel">
            {header}
            <ul
                className={`flex min-h-24 flex-1 flex-col gap-2 p-2 ${dragging && over?.key === key ? 'bg-om-selected' : ''}`}
                onDragOver={(e) => {
                    if (readOnly) return;
                    e.preventDefault();
                    setOver({ key, index: blocks.length });
                }}
                onDrop={(e) => !readOnly && drop(e, key, blocks)}
            >
                {blocks.map((block, i) => {
                    const first = line !== null && (i === 0 || blocks[i - 1].launch !== block.launch);
                    const launchPallets = first ? blocks.filter((b) => b.launch === block.launch).reduce((s, b) => s + b.pallets, 0) : 0;

                    return (
                        <Fragment key={block.id}>
                            {dragging && over?.key === key && over.index === i && <li className="h-1 rounded bg-om-accent" aria-hidden="true" />}
                            <Block
                                block={block}
                                index={i}
                                count={blocks.length}
                                line={line}
                                lines={board.lines}
                                readOnly={readOnly}
                                busy={busy}
                                onMove={move}
                                onDragStart={onDragStart}
                                onDragEnd={onDragEnd}
                                onDragOver={(e, index) => {
                                    if (readOnly) return;
                                    e.preventDefault();
                                    e.stopPropagation();
                                    const box = e.currentTarget.getBoundingClientRect();
                                    setOver({ key, index: index + (e.clientY > box.top + box.height / 2 ? 1 : 0) });
                                }}
                                firstOfLaunch={first}
                                launchPallets={launchPallets}
                                canLaunch={first && !readOnly && line !== null && line.next_launch === block.launch}
                                onLaunch={() => call('/piano/lancia', { line_id: line.id })}
                            />
                        </Fragment>
                    );
                })}
                {dragging && over?.key === key && over.index === blocks.length && blocks.length > 0 && <li className="h-1 rounded bg-om-accent" aria-hidden="true" />}
                {blocks.length === 0 && (
                    <li className="rounded-lg border border-dashed border-om-line px-3 py-6 text-center text-xs text-om-muted">
                        {line === null ? 'Nessun ordine da pianificare.' : 'Trascina qui un ordine.'}
                    </li>
                )}
            </ul>
        </div>
    );

    return (
        <Shell
            title="Piano linee"
            active="/piano"
            dayPicker
            actions={!readOnly && (
                <Btn variant="primary" disabled={busy} onClick={() => call('/piano/proponi', {})} title="Ordina per camion e mette vicini gli articoli uguali">
                    Proponi sequenza
                </Btn>
            )}
        >
            <div className="mb-3 flex flex-wrap items-center gap-2">
                {board.trucks.map((t) => (
                    <div key={t.time} className={`rounded-lg border px-3 py-1.5 text-sm ${t.late > 0 ? 'border-red-400 bg-red-50 dark:border-red-700 dark:bg-red-950/40' : 'border-om-line bg-om-card'}`}>
                        <span className="font-semibold tabular-nums text-om-ink">Camion {t.time}</span>
                        <span className="text-om-muted"> · {plural(t.orders, 'ordine', 'ordini')} · {plural(t.pallets, 'pedana', 'pedane')}</span>
                        {t.late > 0 && <span className="font-medium text-red-700 dark:text-red-300"> · {t.late} a rischio</span>}
                        {t.unplanned > 0 && <span className="text-amber-700 dark:text-amber-300"> · {t.unplanned} da pianificare</span>}
                        {t.late === 0 && t.unplanned === 0 && <span className="text-emerald-700 dark:text-emerald-400"> · in orario</span>}
                    </div>
                ))}
                {board.waiting > 0 && (
                    <span className="text-sm text-om-muted">
                        {board.waiting} ordini A per A devono ancora arrivare: entrano da <Link className="text-om-accent hover:underline" href={`${BASE}/ordini`}>1 · Ordini del giorno</Link>.
                    </span>
                )}
            </div>

            {notice && (
                <div role="status" className={`mb-3 flex flex-wrap items-center justify-between gap-2 rounded-lg border px-3 py-2 text-sm ${notice.tone === 'bad' ? 'border-red-300 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200' : 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200'}`}>
                    <span>{notice.text}</span>
                    <span className="flex items-center gap-3">
                        {notice.sheetId && <Link href={`${BASE}/fogli/${notice.sheetId}`} className="font-medium underline">Apri il foglio</Link>}
                        <button type="button" onClick={() => setNotice(null)} aria-label="Chiudi" className="opacity-70 hover:opacity-100">✕</button>
                    </span>
                </div>
            )}

            <div className="flex items-start gap-3 overflow-x-auto pb-4">
                {column(BACKLOG, null, board.backlog, (
                    <header className="border-b border-om-line px-3 py-2.5">
                        <div className="flex items-center justify-between">
                            <h2 className="text-sm font-semibold text-om-ink">Da pianificare</h2>
                            <Chip tone={board.backlog.length > 0 ? 'warn' : 'neutral'}>{board.backlog.length}</Chip>
                        </div>
                        <p className="mt-0.5 text-xs text-om-muted">Ordini arrivati, non ancora su una linea</p>
                    </header>
                ))}

                {board.lines.map((line) => column(line.id, line, line.blocks, (
                    <header className="border-b border-om-line px-3 py-2.5">
                        <div className="flex items-center justify-between gap-2">
                            <h2 className="text-sm font-semibold text-om-ink">{line.name}</h2>
                            <Chip
                                tone={line.understaffed ? 'bad' : line.open_productions > 0 ? 'ok' : 'neutral'}
                                title="Produzioni ancora da chiudere che hanno tutte le persone che servono"
                            >
                                {line.open_productions === 0
                                    ? 'Nessuna produzione'
                                    : line.understaffed
                                        ? `Squadra incompleta: ${line.short_productions} su ${line.open_productions}`
                                        : 'Squadre complete'}
                            </Chip>
                        </div>
                        <p className="mt-0.5 truncate text-xs text-om-muted">{line.description} · {line.formats.map((f) => f.format).join(', ')}</p>
                        <div className="mt-1.5 flex flex-wrap gap-1">
                            <Chip>{num(line.pallets)} pedane</Chip>
                            {line.end && <Chip>Fine {line.end}</Chip>}
                            {line.late_count > 0 && <Chip tone="bad">{line.late_count} oltre il camion</Chip>}
                        </div>
                    </header>
                )))}
            </div>

            <p className="text-xs text-om-muted">
                Durata di un blocco = pedane ÷ capacità della linea per quel formato + avvio (primo blocco) o cambio rispetto al blocco prima.
                Le linee partono alle {board.day_start}. Un blocco diventa rosso se finisce dopo l'ora del suo camion.
            </p>
        </Shell>
    );
}

Plan.layout = (page) => <AppLayout>{page}</AppLayout>;
