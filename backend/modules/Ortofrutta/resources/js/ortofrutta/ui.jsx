// Small presentational pieces shared by the module's screens. Plain elements and
// the app's own colour tokens, so the screens follow the light and dark themes.

const TONES = {
    neutral: 'bg-om-chip text-om-muted',
    ok: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
    warn: 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200',
    bad: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
    info: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300',
};

export function Chip({ tone = 'neutral', children, title }) {
    return (
        <span title={title} className={`inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-medium ${TONES[tone]}`}>
            {children}
        </span>
    );
}

const BUTTONS = {
    primary: 'bg-om-ink text-om-on-ink hover:bg-om-ink-hover',
    secondary: 'border border-om-line bg-om-card text-om-ink hover:bg-om-chip',
    danger: 'border border-red-300 bg-om-card text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-900/30',
};

export function Btn({ variant = 'secondary', small = false, className = '', type = 'button', ...props }) {
    return (
        <button
            type={type}
            className={`inline-flex items-center justify-center gap-1.5 rounded-lg font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${small ? 'px-2 py-1 text-xs' : 'px-3 py-1.5 text-sm'} ${BUTTONS[variant]} ${className}`}
            {...props}
        />
    );
}

export function Card({ title, aside, children, className = '' }) {
    return (
        <section className={`rounded-xl border border-om-line bg-om-card ${className}`}>
            {(title || aside) && (
                <header className="flex flex-wrap items-center justify-between gap-2 border-b border-om-line px-4 py-2.5">
                    <h2 className="text-sm font-semibold text-om-ink">{title}</h2>
                    {aside}
                </header>
            )}
            <div className="p-4">{children}</div>
        </section>
    );
}

export function Stat({ label, value, hint, tone }) {
    const colour = tone === 'bad' ? 'text-red-600 dark:text-red-400' : tone === 'ok' ? 'text-emerald-700 dark:text-emerald-400' : 'text-om-ink';

    return (
        <div className="rounded-xl border border-om-line bg-om-card px-4 py-3">
            <div className="text-[11px] font-medium uppercase tracking-wide text-om-muted">{label}</div>
            <div className={`mt-0.5 text-xl font-semibold tabular-nums ${colour}`}>{value}</div>
            {hint && <div className="mt-0.5 text-xs text-om-muted">{hint}</div>}
        </div>
    );
}

// A plain table: `columns` is [{ key, label, align, render }], `rows` any array.
export function Table({ columns, rows, empty = 'Nessun dato.', rowKey = (r, i) => r.id ?? i, rowClass }) {
    if (!rows || rows.length === 0) {
        return <p className="py-6 text-center text-sm text-om-muted">{empty}</p>;
    }

    return (
        <div className="overflow-x-auto">
            <table className="w-full border-collapse text-sm">
                <thead>
                    <tr className="border-b border-om-line text-left text-[11px] uppercase tracking-wide text-om-muted">
                        {columns.map((c) => (
                            <th key={c.key} className={`px-2 py-2 font-medium ${c.align === 'right' ? 'text-right' : ''}`}>{c.label}</th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, i) => (
                        <tr key={rowKey(row, i)} className={`border-b border-om-line last:border-0 ${rowClass ? rowClass(row) : ''}`}>
                            {columns.map((c) => (
                                <td key={c.key} className={`px-2 py-2 align-middle text-om-ink ${c.align === 'right' ? 'text-right tabular-nums' : ''}`}>
                                    {c.render ? c.render(row, i) : row[c.key]}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export const inputClass = 'w-full rounded-lg border border-om-line bg-om-card px-2.5 py-1.5 text-sm text-om-ink focus:outline-none focus:ring-2 focus:ring-om-accent';

export function Field({ label, error, children, hint }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-medium text-om-muted">{label}</span>
            {children}
            {hint && !error && <span className="mt-1 block text-xs text-om-muted">{hint}</span>}
            {error && <span className="mt-1 block text-xs text-red-600 dark:text-red-400">{error}</span>}
        </label>
    );
}
