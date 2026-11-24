import { Head, Link, router, usePage } from '@inertiajs/react';
import PageTitle from '@core/components/PageTitle';
import { BASE } from './format';
import { Btn, Chip } from './ui';

// The screens, in the order an order travels through them.
const TABS = [
    ['', 'Stabilimento'],
    ['/conferimenti', 'Conferimenti'],
    ['/ordini', '1 · Ordini del giorno'],
    ['/piano', '2 · Piano linee'],
    ['/squadre', '3 · Squadre'],
    ['/fogli', '4 · Fogli di lavorazione'],
    ['/margini', '5 · Margini'],
    ['/missioni', 'Missioni bins'],
];

function resetDemo() {
    if (window.confirm('Ripristinare i dati demo? Il piano, le squadre e i fogli di oggi tornano alla situazione delle 10:00.')) {
        router.post(`${BASE}/demo/ripristina`);
    }
}

/**
 * The frame of every screen of the module: company name, the "simulated data"
 * label, the demo day and clock, and the navigation between the screens.
 *
 * `active` is the tab path ('/piano'); `dayPicker` lets the screen be opened on
 * an earlier day of the simulated fortnight.
 */
export default function Shell({ title, active = '', dayPicker = false, actions = null, children }) {
    const { orto } = usePage().props;

    const changeDay = (e) => {
        const value = e.target.value;
        router.get(`${BASE}${active}`, value === orto.day ? {} : { giorno: value }, { preserveState: false });
    };

    return (
        <>
            <Head title={`${title} · ${orto.company}`} />
            <PageTitle>{`${orto.company} · ${title}`}</PageTitle>

            <div className="mb-4 rounded-xl border border-om-line bg-om-card">
                <div className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                    <div className="min-w-0">
                        <div className="truncate text-lg font-semibold leading-tight text-om-ink">{orto.company}</div>
                        <div className="text-xs text-om-muted">Ortofrutta · dall'ordine al margine per produttore</div>
                    </div>
                    <Chip tone="warn" title="Persone, produttori, clienti, prezzi e costi sono inventati.">DATI SIMULATI</Chip>
                    {orto.loaded && (
                        <div className="text-sm text-om-ink">
                            <span className="text-om-muted">Giornata demo </span>
                            <span className="font-medium capitalize">{orto.day_label}</span>
                            <span className="text-om-muted"> · ore </span>
                            <span className="font-semibold tabular-nums">{orto.clock}</span>
                        </div>
                    )}
                    <div className="ml-auto flex flex-wrap items-center gap-2">
                        {dayPicker && orto.loaded && orto.days.length > 0 && (
                            <select
                                value={orto.viewing}
                                onChange={changeDay}
                                aria-label="Giorno"
                                className="rounded-lg border border-om-line bg-om-card px-2 py-1.5 text-sm text-om-ink"
                            >
                                {orto.days.map((d) => <option key={d.value} value={d.value}>{d.label}</option>)}
                            </select>
                        )}
                        {actions}
                        <Btn onClick={resetDemo} title="Ricarica i dati simulati dai file CSV">Ripristina dati demo</Btn>
                    </div>
                </div>

                <nav className="flex gap-1 overflow-x-auto border-t border-om-line px-2 py-1.5" aria-label="Schermate">
                    {TABS.map(([path, label]) => (
                        <Link
                            key={path}
                            href={`${BASE}${path}`}
                            className={`whitespace-nowrap rounded-lg px-3 py-1.5 text-sm ${path === active ? 'bg-om-ink font-medium text-om-on-ink' : 'text-om-muted hover:bg-om-chip hover:text-om-ink'}`}
                        >
                            {label}
                        </Link>
                    ))}
                </nav>
            </div>

            {dayPicker && orto.loaded && !orto.is_demo_day && (
                <p className="mb-4 rounded-lg border border-om-line bg-om-chip px-3 py-2 text-sm text-om-muted">
                    Stai guardando <span className="font-medium capitalize text-om-ink">{orto.viewing_label}</span>, una giornata già chiusa: è in sola lettura.
                </p>
            )}

            {orto.loaded ? children : (
                <div className="rounded-xl border border-dashed border-om-line bg-om-card px-6 py-12 text-center">
                    <p className="text-base font-medium text-om-ink">I dati demo non sono ancora stati caricati.</p>
                    <p className="mx-auto mt-1 max-w-xl text-sm text-om-muted">
                        Il modulo lavora su due settimane di dati simulati, letti dai file CSV della cartella seed.
                    </p>
                    <Btn variant="primary" className="mt-4" onClick={() => router.post(`${BASE}/demo/ripristina`)}>Carica i dati demo</Btn>
                </div>
            )}
        </>
    );
}
