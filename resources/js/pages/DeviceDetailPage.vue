<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api, { errorMessage } from '../lib/api';
import { formatBytes, toMb, formatDateTime, timeAgo, todayLocal } from '../lib/format';
import TrendChart from '../components/TrendChart.vue';
import StatusBadge from '../components/StatusBadge.vue';

const route = useRoute();
const router = useRouter();
const device = ref(null);
const usage = ref(null);
const events = ref([]);
const cycle = ref('');
const currentBumpsVersion = ref('');
// --- Topes por los que paso (avisos registrados por la Telpo) ---
const passes = ref(null);
const passesError = ref('');
const range = ref('7'); // dias hacia atras: 1 = hoy, 7, 30 o 'custom'
const customFrom = ref('');
const customTo = ref('');
function isoDaysAgo(n) {
    const d = new Date(`${todayLocal()}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() - n);
    return d.toISOString().slice(0, 10);
}
async function loadPasses() {
    passesError.value = '';
    try {
        const params = range.value === 'custom'
            ? { from: customFrom.value || undefined, to: customTo.value || undefined }
            : { from: isoDaysAgo(Number(range.value) - 1), to: todayLocal() };
        const { data } = await api.get(`/api/admin/devices/${route.params.id}/bump-passes`, { params });
        passes.value = data;
    } catch (e) {
        passesError.value = errorMessage(e, 'No se pudieron cargar los topes.');
    }
}
watch(range, loadPasses);
watch([customFrom, customTo], () => { if (range.value === 'custom') loadPasses(); });

const loading = ref(true);
const error = ref('');

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const id = route.params.id;
        const params = cycle.value ? { cycle: cycle.value } : {};
        const [d, u, ev] = await Promise.all([
            api.get(`/api/admin/devices/${id}`, { params }),
            api.get(`/api/admin/devices/${id}/usage`, { params }),
            api.get('/api/admin/events', { params: { device: id, per_page: 10 } }),
        ]);
        device.value = d.data.data;
        currentBumpsVersion.value = d.data.bumps_version ?? '';
        usage.value = u.data;
        events.value = ev.data.data;
        if (!cycle.value) cycle.value = u.data.cycle.start;
    } catch (e) {
        if (e.response?.status === 404) { router.replace({ name: 'devices' }); return; }
        error.value = errorMessage(e, 'No se pudo cargar el equipo.');
    } finally {
        loading.value = false;
    }
}
onMounted(() => { load(); loadPasses(); });
watch(cycle, (v, old) => { if (old) load(); });

const labels = computed(() => (usage.value?.days ?? []).map((d) => d.day));
const series = computed(() => [{ name: 'Consumo diario', color: 'var(--s1)', values: (usage.value?.days ?? []).map((d) => toMb(d.bytes)) }]);
const gpsInfo = (s) => ({
    ok: ['ok', 'Activo'], gps_off: ['bad', 'GPS apagado'], no_permission: ['bad', 'Sin permiso'],
    no_fix: ['warn', 'Sin señal GPS'], no_bumps: ['warn', 'Sin topes'], not_running: ['bad', 'Detenido'],
}[s] ?? ['neutral', 'Sin dato']);
const bumpsUpToDate = computed(() => !!device.value?.bumps_version && device.value.bumps_version === currentBumpsVersion.value);
const mbFormat = (v) => `${v >= 10 ? Math.round(v) : v.toFixed(1)} MB`;
const result = (e) => (e.success === null ? ['neutral', 'Informativo'] : e.success ? ['ok', 'Exitoso'] : ['bad', 'Fallido']);
</script>

<template>
    <p><router-link to="/devices">← Equipos</router-link></p>
    <div v-if="error" class="alert error" role="alert">{{ error }} <button class="btn sm" @click="load">Reintentar</button></div>
    <p v-if="loading && !device" class="empty">Cargando…</p>

    <template v-if="device">
        <div class="page-head">
            <div>
                <h1>Unidad {{ device.unit_number || 'sin asignar' }}</h1>
                <p class="sub"><span class="mono">{{ device.imei || 'Sin IMEI' }}</span> · {{ device.model || 'Telpo' }}<span v-if="device.label"> · {{ device.label }}</span></p>
            </div>
            <div class="actions">
                <select v-model="cycle" aria-label="Periodo" v-if="usage">
                    <option v-for="c in usage.cycles" :key="c.key" :value="c.start">{{ c.label }}{{ c.is_current ? ' (actual)' : '' }}</option>
                </select>
            </div>
        </div>

        <div class="grid kpis">
            <div class="card kpi"><div class="label">Consumo del periodo</div><div class="value">{{ formatBytes(usage?.total_bytes ?? 0) }}</div></div>
            <div class="card kpi"><div class="label">Última conexión</div><div class="value" style="font-size:20px">{{ timeAgo(device.last_seen_at) }}</div><div class="hint">{{ formatDateTime(device.last_seen_at) }}</div></div>
            <div class="card kpi"><div class="label">NFC</div><div class="value" style="font-size:20px"><StatusBadge v-if="device.nfc_enabled === null" text="Sin dato" /><StatusBadge v-else :kind="device.nfc_enabled ? 'ok' : 'bad'" :text="device.nfc_enabled ? 'Activo' : 'Apagado'" /></div></div>
            <div class="card kpi"><div class="label">Cámara (escáner QR)</div><div class="value" style="font-size:20px"><StatusBadge v-if="device.camera_ok === null" text="Sin dato" /><StatusBadge v-else :kind="device.camera_ok ? 'ok' : 'bad'" :text="device.camera_ok ? 'Disponible' : 'Bloqueada'" /></div></div>
            <div class="card kpi"><div class="label">Device Owner</div><div class="value" style="font-size:20px"><StatusBadge v-if="device.is_device_owner === null" text="Sin dato" /><StatusBadge v-else :kind="device.is_device_owner ? 'ok' : 'warn'" :text="device.is_device_owner ? 'Sí' : 'No'" /></div><div class="hint">App v{{ device.app_version || '?' }}</div></div>
            <div class="card kpi"><div class="label">App de cobro</div><div class="value" style="font-size:20px"><StatusBadge v-if="!device.billing_app?.checked_at" text="Sin dato" /><StatusBadge v-else-if="!device.billing_app.installed" kind="bad" text="No instalada" /><template v-else>{{ device.billing_app.version || '?' }}</template></div><div class="hint"><router-link to="/app-versions">Ver versiones</router-link></div></div>
        </div>

        <section class="card" style="margin-bottom:14px">
            <header><h2>Aviso de topes</h2><router-link to="/bumps">Administrar topes</router-link></header>
            <div class="actions" style="gap:16px">
                <span><StatusBadge :kind="gpsInfo(device.gps_status)[0]" :text="gpsInfo(device.gps_status)[1]" /></span>
                <span class="muted">Topes en el equipo: <strong>{{ device.bump_count ?? '—' }}</strong></span>
                <span class="muted">Avisos de hoy: <strong>{{ device.bump_alerts_today ?? '—' }}</strong></span>
                <span><StatusBadge v-if="device.bumps_version" :kind="bumpsUpToDate ? 'ok' : 'warn'" :text="bumpsUpToDate ? 'Lista al día' : 'Lista pendiente de actualizar'" /></span>
                <span class="faint" v-if="device.bump_stats_at">Reportado {{ timeAgo(device.bump_stats_at) }}</span>
            </div>
        </section>

        <section class="card" style="margin-bottom:14px">
            <header><h2>Topes por los que pasó</h2>
                <span class="actions">
                    <select v-model="range" aria-label="Rango de fechas">
                        <option value="1">Hoy</option><option value="7">Últimos 7 días</option><option value="30">Últimos 30 días</option><option value="custom">Fechas…</option>
                    </select>
                    <template v-if="range === 'custom'"><input type="date" v-model="customFrom" aria-label="Desde" /><input type="date" v-model="customTo" aria-label="Hasta" /></template>
                </span>
            </header>
            <div v-if="passesError" class="alert error" role="alert">{{ passesError }}</div>
            <template v-else-if="passes">
                <p class="muted" style="margin-top:0">{{ passes.total }} avisos en {{ passes.distinct }} {{ passes.distinct === 1 ? 'tope' : 'topes' }} · del {{ passes.range.from }} al {{ passes.range.to }}</p>
                <div class="table-wrap" v-if="passes.by_bump.length">
                    <table>
                        <thead><tr><th>Tope</th><th class="num">Veces</th><th>Primera vez</th><th>Última vez</th></tr></thead>
                        <tbody>
                            <tr v-for="b in passes.by_bump" :key="b.bump_id">
                                <td><strong>{{ b.name }}</strong> <span v-if="b.removed" class="faint">(eliminado de la lista)</span></td>
                                <td class="num">{{ b.passes }}</td><td>{{ formatDateTime(b.first_at) }}</td><td>{{ formatDateTime(b.last_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="empty">Sin avisos de topes en este rango. Si el equipo debería haber pasado por alguno, revisa que el GPS esté activo arriba.</p>
                <details v-if="passes.recent.length" style="margin-top:10px">
                    <summary class="muted">Últimos avisos</summary>
                    <div class="table-wrap"><table><tbody>
                        <tr v-for="(r, i) in passes.recent" :key="i"><td>{{ formatDateTime(r.at) }}</td><td>{{ r.name }}</td><td class="faint">{{ r.distance_m != null ? r.distance_m + ' m' : '' }}</td></tr>
                    </tbody></table></div>
                </details>
            </template>
        </section>

        <section class="card" style="margin-bottom:14px">
            <header><h2>Consumo diario</h2><span class="faint">MB por día</span></header>
            <TrendChart kind="columns" :labels="labels" :series="series" :format="mbFormat" />
        </section>

        <section class="card">
            <header><h2>Últimos eventos</h2><router-link :to="{ name: 'logs', query: { device: device.id } }">Ver todos</router-link></header>
            <div class="table-wrap" v-if="events.length">
                <table><tbody>
                    <tr v-for="e in events" :key="e.id"><td>{{ formatDateTime(e.occurred_at) }}</td><td>{{ e.type_label }}</td><td><StatusBadge :kind="result(e)[0]" :text="result(e)[1]" /></td></tr>
                </tbody></table>
            </div>
            <p v-else class="empty">Este equipo aún no reporta eventos.</p>
        </section>
    </template>
</template>
