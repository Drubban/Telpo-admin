<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api, { errorMessage } from '../lib/api';
import { formatBytes, toMb, formatDateTime, timeAgo, todayLocal } from '../lib/format';
import TrendChart from '../components/TrendChart.vue';
import StatusBadge from '../components/StatusBadge.vue';
import SignalDot from '../components/SignalDot.vue';

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
onMounted(() => { load(); loadPasses(); loadSignal(); });
watch(cycle, (v, old) => { if (old) load(); });

const labels = computed(() => (usage.value?.days ?? []).map((d) => d.day));
const series = computed(() => [{ name: 'Consumo diario', color: 'var(--s1)', values: (usage.value?.days ?? []).map((d) => toMb(d.bytes)) }]);
const gpsInfo = (s) => ({
    ok: ['ok', 'Activo'], gps_off: ['bad', 'GPS apagado'], no_permission: ['bad', 'Sin permiso'],
    no_fix: ['warn', 'Sin señal GPS'], no_bumps: ['warn', 'Sin topes'], not_running: ['bad', 'Detenido'],
}[s] ?? ['neutral', 'Sin dato']);
const bumpsUpToDate = computed(() => !!device.value?.bumps_version && device.value.bumps_version === currentBumpsVersion.value);
const mbFormat = (v) => `${v >= 10 ? Math.round(v) : v.toFixed(1)} MB`;
// --- Semaforo de red: ultimo estado y tira de las ultimas 24 h (una celda por muestra de 10 min) ---
const signalData = ref(null);
const signalError = ref('');
const SLOTS = 144;
const SLOT_MS = 10 * 60 * 1000;
async function loadSignal() {
    signalError.value = '';
    try {
        const { data } = await api.get(`/api/admin/devices/${route.params.id}/signal`, { params: { hours: 24 } });
        signalData.value = data;
    } catch (e) {
        signalError.value = errorMessage(e, 'No se pudo cargar el historial de señal.');
    }
}
const STATE_WORD = ['Verde (estable)', 'Amarillo (intermitente)', 'Rojo (con problemas)'];
const signalStrip = computed(() => {
    const byslot = new Map();
    for (const s of signalData.value?.samples ?? []) byslot.set(Math.floor(new Date(s.at).getTime() / SLOT_MS), s);
    const last = Math.floor(Date.now() / SLOT_MS);
    const cells = { mobile: [], wifi: [] };
    for (let i = SLOTS - 1; i >= 0; i--) {
        const slot = last - i;
        const s = byslot.get(slot);
        const hour = new Date(slot * SLOT_MS).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Mexico_City' });
        for (const k of ['mobile', 'wifi']) {
            const v = s ? s[k] : null;
            const rtt = s?.[`${k}_rtt_ms`];
            cells[k].push({ key: slot, cls: v === null ? 'sn' : `s${v}`, title: `${hour} · ${v === null ? 'Sin dato' : STATE_WORD[v]}${rtt ? ` · ${rtt} ms` : ''}` });
        }
    }
    return cells;
});

// --- Telefono de la linea (lo lee la Telpo de la SIM; si no puede, se captura aqui) ---
const phoneInput = ref('');
const phoneSaving = ref(false);
const phoneError = ref('');
async function savePhone() {
    phoneSaving.value = true;
    phoneError.value = '';
    try {
        const clean = phoneInput.value.replace(/[\s\-()]/g, '');
        const { data } = await api.patch(`/api/admin/devices/${route.params.id}`, { phone_number: clean || null });
        device.value = { ...device.value, ...data.data };
        phoneInput.value = '';
    } catch (e) {
        phoneError.value = errorMessage(e, 'No se pudo guardar el teléfono.');
    } finally {
        phoneSaving.value = false;
    }
}

// --- Detalle del consumo por aplicacion ---
const breakdown = computed(() => {
    const b = device.value?.usage_breakdown;
    if (!b?.apps?.length) return null;
    const rows = b.apps
        .map((a) => ({ ...a, total: a.rx_bytes + a.tx_bytes }))
        .sort((x, y) => y.total - x.total);
    const sum = rows.reduce((t, r) => t + r.total, 0) || 1;
    return { rows: rows.map((r) => ({ ...r, share: Math.round((r.total / sum) * 1000) / 10 })), sum, reportedAt: b.reported_at };
});
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
            <div class="card kpi"><div class="label">App de cobro</div><div class="value" style="font-size:20px"><StatusBadge v-if="!device.billing_app?.checked_at" text="Sin dato" /><StatusBadge v-else-if="!device.billing_app.installed" kind="bad" text="No instalada" /><template v-else>{{ device.billing_app.version || '?' }}</template></div><div class="hint">Serie: <span class="mono">{{ device.serial || '—' }}</span> · <router-link to="/app-versions">Ver versiones</router-link></div></div>
            <div class="card kpi"><div class="label">Teléfono de la línea</div><div class="value" style="font-size:20px"><span v-if="device.phone_number" class="mono">{{ device.phone_number }}</span><StatusBadge v-else text="Sin dato" /></div><div class="hint">{{ device.phone_source === 'sim' ? 'Leído de la SIM' : device.phone_source === 'manual' ? 'Capturado a mano' : 'La SIM no lo entrega' }}</div></div>
        </div>

        <section class="card" v-if="!device.phone_number || device.phone_source === 'manual'" style="margin-bottom:14px">
            <header><h2>Teléfono de la línea</h2></header>
            <p class="muted" style="margin-top:0">Algunas SIM no guardan su número y Android no puede leerlo. Captúralo aquí; si después la Telpo logra leerlo de la SIM, ese valor lo reemplaza.</p>
            <form class="actions" @submit.prevent="savePhone">
                <input v-model="phoneInput" type="tel" inputmode="tel" placeholder="Ej. 5512345678" aria-label="Teléfono" />
                <button class="btn" type="submit" :disabled="phoneSaving">{{ phoneSaving ? 'Guardando…' : 'Guardar' }}</button>
            </form>
            <div v-if="phoneError" class="alert error" role="alert">{{ phoneError }}</div>
        </section>

        <section class="card" style="margin-bottom:14px">
            <header><h2>Señal de red</h2><span class="faint" v-if="device.signal?.checked_at">Última revisión {{ timeAgo(device.signal.checked_at) }}</span></header>
            <div class="sig-now">
                <SignalDot :state="device.signal?.mobile ?? null" :reason="device.signal?.mobile_reason" label="Datos móviles" />
                <SignalDot :state="device.signal?.wifi ?? null" :reason="device.signal?.wifi_reason" label="Wi-Fi" />
            </div>
            <p class="faint" style="margin:6px 0 12px">La Telpo revisa su conexión cada 10 minutos (:00, :10, :20…). Verde: estable · Amarillo: intermitente · Rojo: apagada, con problemas o sin datos. Si la Telpo estuvo sin red, sus revisiones llegan cuando vuelve a sincronizar.</p>
            <div v-if="signalError" class="alert error" role="alert">{{ signalError }} <button class="btn sm" @click="loadSignal">Reintentar</button></div>
            <template v-else>
                <div class="strip-label">Datos móviles · últimas 24 h</div>
                <div class="strip"><span v-for="c in signalStrip.mobile" :key="'m' + c.key" class="cell" :class="c.cls" :title="c.title"></span></div>
                <div class="strip-label">Wi-Fi · últimas 24 h</div>
                <div class="strip"><span v-for="c in signalStrip.wifi" :key="'w' + c.key" class="cell" :class="c.cls" :title="c.title"></span></div>
                <p class="faint" v-if="signalData?.summary?.count" style="margin-top:8px">
                    Datos móviles: {{ signalData.summary.mobile.ok }} % estable, {{ signalData.summary.mobile.unstable }} % intermitente, {{ signalData.summary.mobile.problem }} % con problemas ·
                    Wi-Fi: {{ signalData.summary.wifi.ok }} % estable, {{ signalData.summary.wifi.unstable }} % intermitente, {{ signalData.summary.wifi.problem }} % con problemas
                </p>
                <p class="faint" v-else-if="signalData" style="margin-top:8px">Aún no hay revisiones de señal. Llegan cuando la Telpo tiene la app nueva y sincroniza.</p>
            </template>
        </section>

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
            <header><h2>En qué se usa el consumo</h2><span class="faint" v-if="breakdown">Reportado {{ timeAgo(breakdown.reportedAt) }}</span></header>
            <div class="table-wrap" v-if="breakdown">
                <table>
                    <thead><tr><th>Aplicación</th><th class="num">Descarga</th><th class="num">Subida</th><th class="num">Total</th><th class="num">% del total</th></tr></thead>
                    <tbody>
                        <tr v-for="r in breakdown.rows" :key="r.package">
                            <td><strong>{{ r.label || r.package }}</strong><div class="faint mono" v-if="r.label && r.label !== r.package">{{ r.package }}</div></td>
                            <td class="num">{{ formatBytes(r.rx_bytes) }}</td><td class="num">{{ formatBytes(r.tx_bytes) }}</td>
                            <td class="num">{{ formatBytes(r.total) }}</td><td class="num">{{ r.share }} %</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="empty">Aún no hay detalle. Aparece cuando la Telpo (con la app nueva) sincroniza; usa el permiso de uso de la app que ya se concede al aprovisionar.</p>
            <p class="faint" v-if="breakdown">Datos móviles del periodo actual, por aplicación. Android los atribuye a la app que los usó; "Sistema Android" incluye servicios del equipo.</p>
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

<style scoped>
.sig-now { display: flex; flex-wrap: wrap; gap: 20px; margin: 4px 0; }
.strip-label { font-size: 13px; margin: 10px 0 4px; }
.strip { display: grid; grid-template-columns: repeat(144, minmax(0, 1fr)); gap: 1px; }
.cell { height: 20px; border-radius: 1px; background: #8a8f98; opacity: 0.45; }
.cell.s0 { background: #1f9d4c; opacity: 1; }
.cell.s1 { background: #c98a00; opacity: 1; }
.cell.s2 { background: #d1242f; opacity: 1; }
</style>
