<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import api, { errorMessage } from '../lib/api';
import { formatBytes, toMb, formatNumber } from '../lib/format';
import HBarChart from '../components/HBarChart.vue';
import TrendChart from '../components/TrendChart.vue';

const router = useRouter();
const data = ref(null);
const loading = ref(true);
const error = ref('');
const cycle = ref('');
const device = ref(null); // hash del equipo seleccionado (filtro cruzado)

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const params = {};
        if (cycle.value) params.cycle = cycle.value;
        if (device.value) params.device = device.value;
        const { data: res } = await api.get('/api/admin/dashboard', { params });
        data.value = res;
        if (!cycle.value) cycle.value = res.cycle.start;
    } catch (e) {
        error.value = errorMessage(e, 'No se pudo cargar el resumen.');
    } finally {
        loading.value = false;
    }
}
onMounted(load);
watch([cycle, device], load);

const k = computed(() => data.value?.kpis);
const unitItems = computed(() => (data.value?.usage_by_unit ?? []).map((u) => ({ key: u.device, label: u.label, value: u.bytes })));
const dailyLabels = computed(() => (data.value?.usage_daily ?? []).map((d) => d.day));
const dailySeries = computed(() => [{ name: 'Consumo diario', color: 'var(--s1)', values: (data.value?.usage_daily ?? []).map((d) => toMb(d.bytes)) }]);
const attemptLabels = computed(() => (data.value?.attempts_by_day ?? []).map((d) => d.day));
const attemptSeries = computed(() => [
    { name: 'Exitosos', color: 'var(--s3)', values: (data.value?.attempts_by_day ?? []).map((d) => d.ok) },
    { name: 'Fallidos', color: 'var(--s2)', values: (data.value?.attempts_by_day ?? []).map((d) => d.failed) },
]);
const typeItems = computed(() => (data.value?.events_by_type ?? []).map((t) => ({ key: t.type, label: t.label, value: t.total, failed: t.failed })));
const mbFormat = (v) => `${v >= 10 ? Math.round(v) : v.toFixed(1)} MB`;
const selectedLabel = computed(() => unitItems.value.find((u) => u.key === device.value)?.label);
</script>

<template>
    <div class="page-head">
        <div>
            <h1>Resumen</h1>
            <p class="sub" v-if="data">Periodo de consumo: {{ data.cycle.label }}</p>
        </div>
        <div class="actions">
            <button v-if="device" class="btn sm" @click="device = null">Quitar filtro: {{ selectedLabel || 'equipo' }} ✕</button>
            <div class="field" style="margin:0">
                <label for="cycle" class="sr-only">Periodo</label>
                <select id="cycle" v-model="cycle" v-if="data">
                    <option v-for="c in data.cycles" :key="c.key" :value="c.start">{{ c.label }}{{ c.is_current ? ' (actual)' : '' }}</option>
                </select>
            </div>
        </div>
    </div>

    <div v-if="error" class="alert error" role="alert">{{ error }} <button class="btn sm" @click="load">Reintentar</button></div>
    <div v-if="data?.credentials_missing?.length" class="alert warn">
        Faltan contraseñas por definir: las Telpo no podrán validar accesos hasta que las captures.
        <router-link to="/passwords">Ir a Contraseñas</router-link>
    </div>

    <div v-if="loading && !data" class="grid kpis"><div v-for="i in 4" :key="i" class="card"><div class="skeleton" style="width:60%"></div><div class="skeleton" style="height:28px;margin-top:10px"></div></div></div>

    <template v-if="data">
        <div class="grid kpis">
            <div class="card kpi"><div class="label">Equipos en línea</div><div class="value">{{ k.devices_online }} <span class="faint" style="font-size:16px">/ {{ k.devices_total }}</span></div><div class="hint">{{ k.devices_offline }} sin reportar</div></div>
            <div class="card kpi"><div class="label">Consumo del periodo</div><div class="value">{{ formatBytes(k.cycle_bytes) }}</div><div class="hint">{{ k.devices_reporting }} equipos reportando</div></div>
            <div class="card kpi"><div class="label">Promedio por equipo</div><div class="value">{{ formatBytes(k.average_bytes) }}</div><div class="hint">solo equipos que reportan</div></div>
            <div class="card kpi"><div class="label">Intentos fallidos</div><div class="value">{{ formatNumber(k.attempts_failed) }}</div><div class="hint">{{ formatNumber(k.attempts_ok) }} exitosos</div></div>
            <div class="card kpi"><div class="label">NFC / cámara con problema</div><div class="value">{{ k.nfc_problems }}</div><div class="hint"><router-link :to="{ name: 'devices', query: { nfc: 'problem' } }">Ver equipos</router-link></div></div>
        </div>

        <div class="grid cols-2">
            <section class="card">
                <header><h2>Consumo por unidad (top 10)</h2><span class="faint">clic para filtrar</span></header>
                <HBarChart :items="unitItems" :selected="device" :format="formatBytes" @select="(key) => (device = key)" />
            </section>
            <section class="card">
                <header><h2>Consumo diario</h2><span class="faint">MB por día</span></header>
                <TrendChart kind="area" :labels="dailyLabels" :series="dailySeries" :format="mbFormat" />
            </section>
        </div>
        <div class="grid cols-2">
            <section class="card">
                <header><h2>Intentos de acceso por día</h2><span class="faint">Configuración, apagado, administración y launcher</span></header>
                <TrendChart stacked :labels="attemptLabels" :series="attemptSeries" :format="(v) => String(Math.round(v))" />
            </section>
            <section class="card">
                <header><h2>Eventos por tipo</h2><router-link to="/logs">Ver registro</router-link></header>
                <div v-if="typeItems.length">
                    <div v-for="t in typeItems" :key="t.key" style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border)">
                        <span>{{ t.label }}</span>
                        <span><strong>{{ t.value }}</strong> <span v-if="t.failed" class="badge bad" style="margin-left:6px">{{ t.failed }} fallidos</span></span>
                    </div>
                </div>
                <p v-else class="empty">Sin eventos en este periodo.</p>
            </section>
        </div>
    </template>
</template>
