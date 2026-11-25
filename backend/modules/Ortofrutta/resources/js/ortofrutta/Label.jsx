// A printed label with its QR code: the bins of a lot, a cell door, the infeed
// of a line. Always black on white, like the paper it stands for, whatever the
// theme of the screen — so its colours are set here and not left to the theme.
// With `onClick` it can be "framed" by the simulated camera of the terminal.

const RINGS = {
    scanning: 'ring-4 ring-sky-400 animate-pulse',
    ok: 'ring-4 ring-emerald-500',
    bad: 'ring-4 ring-red-500',
};

const PAPER = { backgroundColor: '#ffffff', borderColor: '#d4d4d4' };

export default function Label({ label, onClick, state, disabled = false, small = false }) {
    const size = small ? 'h-14 w-14' : 'h-20 w-20';
    const body = (
        <>
            {label.qr
                ? <img src={label.qr} alt={`Codice QR ${label.code}`} className={`${size} shrink-0`} />
                : <div className={`${size} flex shrink-0 items-center justify-center border border-dashed text-[10px]`} style={{ color: '#737373', borderColor: '#a3a3a3' }}>QR</div>}
            <div className="min-w-0 text-left">
                <div className={`truncate font-mono font-bold leading-tight ${small ? 'text-sm' : 'text-base'}`} style={{ color: '#000000' }}>{label.title}</div>
                {label.lines.filter(Boolean).map((line) => (
                    <div key={line} className="truncate text-[11px] leading-snug" style={{ color: '#404040' }}>{line}</div>
                ))}
                {!small && <div className="mt-0.5 font-mono text-[10px]" style={{ color: '#737373' }}>{label.code}</div>}
            </div>
        </>
    );
    const frame = `flex w-full items-center rounded-lg border ${small ? 'gap-2 p-1.5' : 'gap-3 p-2'} ${state ? RINGS[state] : ''}`;

    if (!onClick) {
        return <div className={frame} style={PAPER}>{body}</div>;
    }

    return (
        <button
            type="button"
            onClick={() => onClick(label)}
            disabled={disabled}
            title="Inquadra questa etichetta"
            style={PAPER}
            className={`${frame} transition-shadow hover:shadow-lg focus:outline-none focus-visible:ring-4 focus-visible:ring-sky-400 disabled:cursor-wait`}
        >
            {body}
        </button>
    );
}
