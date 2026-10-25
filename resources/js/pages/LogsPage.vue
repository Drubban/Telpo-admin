<script setup>
import { ref } from 'vue';
import { useRoute } from 'vue-router';
import { useList } from '../lib/useList';
import { formatDateTime } from '../lib/format';
import Modal from '../components/Modal.vue';
import Pager from '../components/Pager.vue';
import StatusBadge from '../components/StatusBadge.vue';
import ExportModal from '../components/ExportModal.vue';

const route = useRoute();
const { filters, rows, meta, extra, loading, error, load, setPage, reset } = useList('/api/admin/events', {
    q: '', type: '', success: '', from: '', to: '', device: typeof route.query.device === 'string' ? route.query.device : '', per_page: 20,
});
const exporting = ref(false);
const detail = ref(null);

const result = (e) => (e.success === null ? ['neutral', 'Informativo'] : e.success ? ['ok', 'Exitoso'] : ['bad', 'Fallido']);
const exportFilters = () => ({ q: filters.q, type: filters.type, success: filters.success, device: filters.device });

const FIELD_NAMES = {
    reason: 'Motivo', package: 'App', attempts: 'Intentos', lock_seconds: 'Segundos de bloqueo', kind: 'Tipo',
    old_unit: 'Unidad anterior', new_unit: 'Unidad nueva', nfc_enabled: 'NFC activo', camera_ok: 'Cámara disponible',
    period_start: 'Inicio del periodo', cycle_total_bytes: 'Consumo acumulado (bytes)', action: 'Acción',
};
const fieldName = (k) => FIELD_NAMES[k] ?? k;
const fieldValue = (v) => (v === true ? 'Sí' : v === false ? 'No' : String(v));
</script>

<template>
    <div class="page-head">
        <div>
            <h1>Registro de eventos</h1>
            <p class="sub">Accesos a Configuración, apagado/reinicio y administración (exitosos y fallidos), cambios de launcher y reportes de consumo.</p>
        </div>
        <div class="actions"><button class="btn" @click="exporting = true">Exportar</button></div>
    </div>

    <div class="filters">
        <div class="field"><label for="q">Buscar</label><input id="q" type="search" v-model="filters.q" placeholder="IMEI o unidad" maxlength="60" /></div>
        <div class="field"><label for="type">Tipo</label>
            <select id="type" v-model="filters.type"><option value="">Todos</option><option v-for="t in extra.types ?? []" :key="t.value" :value="t.value">{{ t.label }}</option></select></div>
        <div class="field"><label for="res">Resultado</label>
            <select id="res" v-model="filters.success"><option value="">Todos</option><option value="1">Exitoso</option><option value="0">Fallido</option></select></div>
        <div class="field"><label for="from">Desde</label><input id="from" type="date" v-model="filters.from" /></div>
        <div class="field"><label for="to">Hasta</label><input id="to" type="date" v-model="filters.to" :min="filters.from || undefined" /></div>
        <button class="btn" @click="reset">Limpiar</button>
    </div>
    <div v-if="filters.device" class="alert warn">Filtrando por un solo equipo. <button class="btn sm" @click="filters.device = ''">Ver todos</button></div>

    <div v-if="error" class="alert error" role="alert">{{ error }} <button class="btn sm" @click="load">Reintentar</button></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Fecha</th><th>Equipo</th><th>Evento</th><th>Resultado</th><th></th></tr></thead>
            <tbody>
                <tr v-if="loading"><td colspan="5"><div class="skeleton"></div></td></tr>
                <tr v-for="e in rows" v-else :key="e.id">
                    <td>{{ formatDateTime(e.occurred_at) }}</td>
                    <td><strong>{{ e.device?.unit_number || '—' }}</strong> <span class="mono faint">{{ e.device?.imei }}</span></td>
                    <td>{{ e.type_label }}</td>
                    <td><StatusBadge :kind="result(e)[0]" :text="result(e)[1]" /></td>
                    <td><button v-if="Object.keys(e.detail || {}).length" class="btn sm" @click="detail = e">Detalle</button></td>
                </tr>
            </tbody>
        </table>
        <p v-if="!loading && !rows.length && !error" class="empty">Sin eventos con esos filtros.</p>
    </div>
    <Pager :meta="meta" @page="setPage" />

    <Modal v-if="detail" :title="detail.type_label" @close="detail = null">
        <p class="muted">{{ formatDateTime(detail.occurred_at) }} · {{ detail.device?.unit_number || 'Sin unidad' }} · <span class="mono">{{ detail.device?.imei }}</span></p>
        <table><tbody>
            <tr v-for="(v, k) in detail.detail" :key="k"><td class="muted">{{ fieldName(k) }}</td><td style="white-space:normal">{{ fieldValue(v) }}</td></tr>
        </tbody></table>
    </Modal>
    <ExportModal v-if="exporting" dataset="events" :filters="exportFilters()" @close="exporting = false" />
</template>
