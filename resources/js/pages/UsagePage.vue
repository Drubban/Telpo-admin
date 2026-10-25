<script setup>
import { ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import { useList } from '../lib/useList';
import { formatBytes, formatNumber } from '../lib/format';
import Pager from '../components/Pager.vue';
import ExportModal from '../components/ExportModal.vue';

const router = useRouter();
const { filters, rows, meta, extra, loading, error, load, setPage } = useList('/api/admin/usage', {
    q: '', unit: '', cycle: '', band: '', per_page: 15,
});
// null | 'plan' (lista por unidad y categoria) | 'usage' (detalle diario)
const exporting = ref(null);

const cycles = computed(() => extra.value.cycles ?? []);
const summary = computed(() => extra.value.summary ?? {});
const plan = computed(() => extra.value.plan ?? { gb: 0, bands: [] });
const selectedCycle = computed({
    get: () => filters.cycle || extra.value.cycle?.start || '',
    set: (v) => { filters.cycle = v; },
});

// Lo que se exporta: los filtros de la tabla, incluida la categoria y el periodo elegido.
const exportFilters = computed(() => ({ q: filters.q, unit: filters.unit, band: filters.band }));
const exportDate = computed(() => selectedCycle.value);

const pct = (r) => (r.plan_percent ?? 0);
const barWidth = (r) => `${Math.min(100, pct(r))}%`;
const bandText = (b) => (b === 0 ? 'Normal' : b >= 100 ? 'Excedido' : `${b} %`);
function toggleBand(key) { filters.band = filters.band === key ? '' : key; }
</script>

<template>
    <div class="page-head">
        <div>
            <h1>Consumo de datos</h1>
            <p class="sub" v-if="extra.cycle">Periodo: {{ extra.cycle.label }} · {{ summary.elapsed_days }} días transcurridos · paquete de {{ plan.gb }} GB por SIM</p>
        </div>
        <div class="actions">
            <button class="btn" @click="exporting = 'usage'">Detalle diario</button>
            <button class="btn primary" @click="exporting = 'plan'">Exportar lista{{ filters.band !== '' ? ' (categoría)' : '' }}</button>
        </div>
    </div>

    <div class="grid kpis">
        <div class="card kpi"><div class="label">Total del periodo</div><div class="value">{{ formatBytes(summary.total_bytes ?? 0) }}</div></div>
        <div class="card kpi"><div class="label">Equipos reportando</div><div class="value">{{ formatNumber(summary.reporting ?? 0) }} <span class="faint" style="font-size:16px">/ {{ formatNumber(summary.devices ?? 0) }}</span></div></div>
        <div class="card kpi"><div class="label">Promedio por equipo</div><div class="value">{{ formatBytes(summary.average_bytes ?? 0) }}</div></div>
    </div>

    <div class="band-chips" role="group" aria-label="Filtrar por categoría de consumo">
        <button v-for="b in plan.bands" :key="b.key" type="button" class="band-chip" :class="[`band-${b.key}`, { active: filters.band === b.key }]"
                :aria-pressed="filters.band === b.key" @click="toggleBand(b.key)">
            <strong>{{ b.count }}</strong>{{ b.label }}
        </button>
    </div>

    <div class="filters">
        <div class="field"><label for="q">Buscar</label><input id="q" type="search" v-model="filters.q" placeholder="IMEI o unidad" maxlength="60" /></div>
        <div class="field"><label for="unit">Unidad</label>
            <select id="unit" v-model="filters.unit"><option value="">Todas</option><option v-for="u in extra.units ?? []" :key="u" :value="u">{{ u }}</option></select></div>
        <div class="field"><label for="cy">Periodo</label>
            <select id="cy" v-model="selectedCycle"><option v-for="c in cycles" :key="c.key" :value="c.start">{{ c.label }}{{ c.is_current ? ' (actual)' : '' }}</option></select></div>
        <div class="field"><label for="bd">Categoría</label>
            <select id="bd" v-model="filters.band"><option value="">Todas</option><option v-for="b in plan.bands" :key="b.key" :value="b.key">{{ b.label }}</option></select></div>
    </div>

    <div v-if="error" class="alert error" role="alert">{{ error }} <button class="btn sm" @click="load">Reintentar</button></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Unidad</th><th>IMEI</th><th class="num">Consumo</th><th style="width:26%">% del paquete</th><th>Categoría</th><th>Último reporte</th></tr></thead>
            <tbody>
                <tr v-if="loading"><td colspan="6"><div class="skeleton"></div></td></tr>
                <tr v-for="r in rows" v-else :key="r.id" class="clickable band-row" :class="`band-${r.band}`" @click="router.push({ name: 'device', params: { id: r.id } })">
                    <td><strong>{{ r.unit_number || '—' }}</strong></td>
                    <td class="mono">{{ r.imei || 'Sin IMEI' }}</td>
                    <td class="num">{{ formatBytes(r.cycle_bytes) }}</td>
                    <td><div class="hbar-track" style="display:inline-block;width:65%;vertical-align:middle"><div class="band-fill" :style="{ width: barWidth(r) }"></div></div>
                        <span class="num" style="margin-left:8px">{{ pct(r).toFixed(1) }} %</span></td>
                    <td><span class="band-badge">{{ bandText(r.band) }}</span></td>
                    <td>{{ r.last_report_day || '—' }}</td>
                </tr>
            </tbody>
        </table>
        <p v-if="!loading && !rows.length && !error" class="empty">Sin equipos para mostrar.</p>
    </div>
    <Pager :meta="meta" @page="setPage" />
    <ExportModal v-if="exporting" :dataset="exporting" :filters="{ ...exportFilters, ...(exporting === 'plan' ? { date: exportDate } : {}) }" @close="exporting = null" />
</template>
