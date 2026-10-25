<script setup>
import { ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import api, { errorMessage } from '../lib/api';
import { useList } from '../lib/useList';
import { formatBytes, formatDateTime } from '../lib/format';
import { alertsState, refreshAlerts } from '../lib/alerts';
import Pager from '../components/Pager.vue';
import StatusBadge from '../components/StatusBadge.vue';

const router = useRouter();
const { filters, rows, meta, extra, loading, error, load, setPage } = useList('/api/admin/usage-alerts', {
    q: '', threshold: '', cycle: '', unseen: '', per_page: 15,
});
const actionError = ref('');
const busy = ref('');

const cycles = computed(() => extra.value.cycles ?? []);
const selectedCycle = computed({
    get: () => filters.cycle || extra.value.cycle?.start || '',
    set: (v) => { filters.cycle = v; },
});
const channels = computed(() => extra.value.channels ?? { email: false, whatsapp: false });

const CH = { sent: ['ok', 'Enviado'], failed: ['bad', 'Falló'], pending: ['warn', 'Pendiente'], skipped: ['neutral', 'No configurado'] };
const level = (t) => (t >= 75 ? 'bad' : t >= 50 ? 'warn' : 'neutral');

async function act(fn, key) {
    busy.value = key;
    actionError.value = '';
    try {
        await fn();
        await Promise.all([load(), refreshAlerts()]);
    } catch (e) {
        actionError.value = errorMessage(e);
    } finally {
        busy.value = '';
    }
}
const markSeen = (a) => act(() => api.post(`/api/admin/usage-alerts/${a.id}/seen`), a.id);
const resend = (a) => act(() => api.post(`/api/admin/usage-alerts/${a.id}/resend`), `r${a.id}`);
const markAll = () => act(() => api.post('/api/admin/usage-alerts/seen'), 'all');
</script>

<template>
    <div class="page-head">
        <div>
            <h1>Alertas de consumo</h1>
            <p class="sub" v-if="extra.cycle">Periodo: {{ extra.cycle.label }} · paquete de {{ extra.plan_gb }} GB por SIM · avisos al {{ (extra.thresholds ?? []).join(', ') }} %</p>
        </div>
        <div class="actions"><button class="btn" :disabled="busy === 'all' || !alertsState.unseen" @click="markAll">Marcar todas como vistas</button></div>
    </div>

    <div v-if="!channels.email || !channels.whatsapp" class="alert warn" role="status">
        Canales sin configurar:
        <span v-if="!channels.email">correo (define TELPO_ALERT_EMAILS y el correo SMTP en el .env)</span><span v-if="!channels.email && !channels.whatsapp"> · </span>
        <span v-if="!channels.whatsapp">WhatsApp (define TELPO_WHATSAPP_DRIVER y TELPO_ALERT_WHATSAPP)</span>.
        Las alertas siempre aparecen aquí.
    </div>

    <div class="filters">
        <div class="field"><label for="q">Buscar</label><input id="q" type="search" v-model="filters.q" placeholder="IMEI o unidad" maxlength="60" /></div>
        <div class="field"><label for="th">Umbral</label>
            <select id="th" v-model="filters.threshold"><option value="">Todos</option><option v-for="t in extra.thresholds ?? []" :key="t" :value="String(t)">{{ t }} %</option></select></div>
        <div class="field"><label for="cy">Periodo</label>
            <select id="cy" v-model="selectedCycle"><option v-for="c in cycles" :key="c.key" :value="c.start">{{ c.label }}{{ c.is_current ? ' (actual)' : '' }}</option></select></div>
        <div class="field"><label for="un">Vistas</label>
            <select id="un" v-model="filters.unseen"><option value="">Todas</option><option value="1">Solo sin ver</option></select></div>
    </div>

    <div v-if="error || actionError" class="alert error" role="alert">{{ error || actionError }}</div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Aviso</th><th>Unidad</th><th>IMEI</th><th class="num">Consumo</th><th>Periodo</th><th>Correo</th><th>WhatsApp</th><th></th></tr></thead>
            <tbody>
                <tr v-if="loading"><td colspan="8"><div class="skeleton"></div></td></tr>
                <tr v-for="a in rows" v-else :key="a.id" :class="{ clickable: a.device }" @click="a.device && router.push({ name: 'device', params: { id: a.device.id } })">
                    <td><StatusBadge :kind="level(a.threshold)" :text="`${a.threshold} %`" /> <span v-if="!a.seen" class="faint">· nueva</span><div class="faint">{{ formatDateTime(a.created_at) }}</div></td>
                    <td><strong>{{ a.device?.unit_number || '—' }}</strong></td>
                    <td class="mono">{{ a.device?.imei || 'Sin IMEI' }}</td>
                    <td class="num">{{ formatBytes(a.total_bytes) }}<div class="faint">{{ a.percent }} % de {{ formatBytes(a.limit_bytes, 0) }}</div></td>
                    <td>{{ extra.cycle?.label }}</td>
                    <td><StatusBadge :kind="CH[a.email_status][0]" :text="CH[a.email_status][1]" /></td>
                    <td><StatusBadge :kind="CH[a.whatsapp_status][0]" :text="CH[a.whatsapp_status][1]" /><div v-if="a.notify_error" class="faint" :title="a.notify_error">ver detalle</div></td>
                    <td style="white-space:nowrap" @click.stop>
                        <button v-if="!a.seen" class="btn sm" :disabled="busy === a.id" @click="markSeen(a)">Visto</button>
                        <button class="btn sm" :disabled="busy === `r${a.id}`" @click="resend(a)">Reenviar</button>
                    </td>
                </tr>
            </tbody>
        </table>
        <p v-if="!loading && !rows.length && !error" class="empty">Sin alertas en este periodo.</p>
    </div>
    <Pager :meta="meta" @page="setPage" />
</template>
