const TZ = 'America/Mexico_City';
const BASE = 1024;

/** Bytes a texto legible (1 MB = 1024 * 1024 bytes, igual que en los exportes). */
export function formatBytes(bytes, decimals = 1) {
    const n = Number(bytes) || 0;
    if (n < BASE) return `${n} B`;
    const units = ['KB', 'MB', 'GB', 'TB'];
    let value = n;
    let i = -1;
    do {
        value /= BASE;
        i += 1;
    } while (value >= BASE && i < units.length - 1);
    return `${value.toFixed(value >= 100 ? 0 : decimals)} ${units[i]}`;
}

/** Numero de MB para ejes de graficas. */
export function toMb(bytes) {
    return (Number(bytes) || 0) / (BASE * BASE);
}

export function formatNumber(n) {
    return new Intl.NumberFormat('es-MX').format(Number(n) || 0);
}

/** 'YYYY-MM-DD' -> '20 oct'. */
export function formatDay(day, withYear = false) {
    if (!day) return '';
    const [y, m, d] = String(day).split('-').map(Number);
    const date = new Date(Date.UTC(y, m - 1, d));
    return new Intl.DateTimeFormat('es-MX', {
        day: 'numeric',
        month: 'short',
        ...(withYear ? { year: 'numeric' } : {}),
        timeZone: 'UTC',
    }).format(date).replace('.', '');
}

export function formatDateTime(iso) {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('es-MX', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: TZ,
    }).format(new Date(iso)).replace('.', '');
}

/** 'hace 5 min' / 'hace 2 h' / 'hace 3 d'. */
export function timeAgo(iso) {
    if (!iso) return 'Nunca';
    const seconds = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000));
    if (seconds < 90) return 'Hace un momento';
    const minutes = Math.round(seconds / 60);
    if (minutes < 60) return `Hace ${minutes} min`;
    const hours = Math.round(minutes / 60);
    if (hours < 48) return `Hace ${hours} h`;
    return `Hace ${Math.round(hours / 24)} d`;
}

/** Fecha de hoy en la zona horaria de operacion, como 'YYYY-MM-DD'. */
export function todayLocal() {
    return new Intl.DateTimeFormat('en-CA', { timeZone: TZ }).format(new Date());
}

export function debounce(fn, ms = 300) {
    let timer;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), ms);
    };
}
