import { reactive, ref, watch, onMounted, onBeforeUnmount } from 'vue';
import api, { errorMessage } from './api';
import { debounce } from './format';

/**
 * Lista paginada con filtros. Al cambiar un filtro vuelve a la pagina 1; el buscador espera 300 ms.
 * Si llegan respuestas fuera de orden se descartan las viejas.
 */
export function useList(url, defaults = {}) {
    const filters = reactive({ ...defaults });
    const page = ref(1);
    const rows = ref([]);
    const meta = ref(null);
    const extra = ref({});
    const loading = ref(true);
    const error = ref('');
    let ticket = 0;
    let alive = true;

    function params() {
        const p = { page: page.value };
        for (const [k, v] of Object.entries(filters)) {
            if (v !== '' && v !== null && v !== undefined) p[k] = v;
        }
        return p;
    }

    async function load() {
        const mine = ++ticket;
        loading.value = true;
        error.value = '';
        try {
            const { data } = await api.get(url, { params: params() });
            if (!alive || mine !== ticket) return;
            rows.value = data.data ?? [];
            meta.value = data.meta ?? null;
            const { data: _d, meta: _m, links: _l, ...rest } = data;
            extra.value = rest;
        } catch (e) {
            if (!alive || mine !== ticket) return;
            error.value = errorMessage(e, 'No se pudo cargar la información.');
        } finally {
            if (alive && mine === ticket) loading.value = false;
        }
    }

    const reloadFromStart = () => { page.value = 1; load(); };
    const reloadDebounced = debounce(reloadFromStart, 300);

    watch(() => filters.q, reloadDebounced);
    for (const key of Object.keys(defaults)) {
        if (key !== 'q') watch(() => filters[key], reloadFromStart);
    }

    function setPage(p) { page.value = p; load(); }
    function reset() { Object.assign(filters, defaults); }

    onMounted(load);
    onBeforeUnmount(() => { alive = false; });

    return { filters, page, rows, meta, extra, loading, error, load, setPage, reset };
}
