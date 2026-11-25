// Number and label formatting shared by every screen of the module (Italian).

// Always group thousands: Italian formatting leaves four-digit numbers ungrouped (5590), which reads badly next to 22.220.
const nf = (digits) => new Intl.NumberFormat('it-IT', { minimumFractionDigits: digits, maximumFractionDigits: digits, useGrouping: 'always' });
const cache = {};
const fmt = (digits) => (cache[digits] ??= nf(digits));

const missing = (v) => v === null || v === undefined || Number.isNaN(Number(v));

export const num = (v, digits = 0) => (missing(v) ? '—' : fmt(digits).format(Number(v)));
export const kg = (v) => (missing(v) ? '—' : `${fmt(0).format(Number(v))} kg`);
export const eur = (v, digits = 2) => (missing(v) ? '—' : `${fmt(digits).format(Number(v))} €`);
// Costs and margins per kilogram are read to the tenth of a cent.
export const eurKg = (v) => (missing(v) ? '—' : `${fmt(3).format(Number(v))} €/kg`);
export const pct = (v, digits = 1) => (missing(v) ? '—' : `${fmt(digits).format(Number(v) * 100)}%`);

// "1 ordine", "3 ordini".
export const plural = (n, one, many) => `${num(n)} ${Number(n) === 1 ? one : many}`;

export const ORDER_TYPES = { axa: 'A per A', axb: 'A per B', stock: 'Scorta' };

export const SETUP_LABELS = {
    startup: 'Avvio linea',
    variety: 'Cambio varietà',
    format: 'Cambio formato',
    packaging: 'Cambio imballo',
};

export const PRIORITIES = { 1: 'Alta', 2: 'Normale', 3: 'Bassa' };

export const BASE = '/admin/stabilimento';
