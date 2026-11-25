// A printed label with its QR code: the bins of a lot, a cell door, the infeed
// of a line. Always black on white, like the paper it stands for, whatever the
// theme of the screen. With `onClick` it can be "framed" by the simulated
// camera of the terminal.

const RINGS = {
    scanning: 'ring-4 ring-sky-400 animate-pulse',
    ok: 'ring-4 ring-emerald-500',
    bad: 'ring-4 ring-red-500',
};

export default function Label({ label, onClick, state, disabled = false, large = false }) {
    const body = (
        <>
            {label.qr
                ? <img src={label.qr} alt={`Codice QR ${label.code}`} className={`${large ? 'h-28 w-28' : 'h-20 w-20'} shrink-0`} />
                : <div className={`${large ? 'h-28 w-28' : 'h-20 w-20'} flex shrink-0 items-center justify-center border border-dashed border-neutral-400 text-[10px] text-neutral-500`}>QR</div>}
            <div className="min-w-0 text-left">
                <div className={`font-mono font-bold leading-tight text-black ${large ? 'text-xl' : 'text-base'}`}>{label.title}</div>
                {label.lines.filter(Boolean).map((line) => <div key={line} className="truncate text-[11px] leading-snug text-neutral-700">{line}</div>)}
                <div className="mt-0.5 font-mono text-[10px] text-neutral-500">{label.code}</div>
            </div>
        </>
    );
    const frame = `flex w-full items-center gap-3 rounded-lg border border-neutral-300 bg-white p-2 ${state ? RINGS[state] : ''}`;

    if (!onClick) {
        return <div className={frame}>{body}</div>;
    }

    return (
        <button
            type="button"
            onClick={() => onClick(label)}
            disabled={disabled}
            title="Inquadra questa etichetta"
            className={`${frame} transition-shadow hover:shadow-lg focus:outline-none focus-visible:ring-4 focus-visible:ring-sky-400 disabled:cursor-wait`}
        >
            {body}
        </button>
    );
}
