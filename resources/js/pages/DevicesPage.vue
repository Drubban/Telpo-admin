<script setup>
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api, { errorMessage, fieldErrors } from '../lib/api';
import { useList } from '../lib/useList';
import { formatBytes, timeAgo } from '../lib/format';
import Modal from '../components/Modal.vue';
import Pager from '../components/Pager.vue';
import StatusBadge from '../components/StatusBadge.vue';
import ExportModal from '../components/ExportModal.vue';

const route = useRoute();
const router = useRouter();
const { filters, rows, meta, extra, loading, error, load, setPage, reset } = useList('/api/admin/devices', {
    q: '', status: '', unit: '', online: '', nfc: route.query.nfc === 'problem' ? 'problem' : '',
    sort: 'unit_number', dir: 'asc', per_page: 15,
});

const exporting = ref(false);
const editing = ref(null);
const form = ref({ unit_number: '', label: '', status: 'active' });
const formErrors = ref({});
const formError = ref('');
const saving = ref(false);

function sortBy(col) {
    if (filters.sort === col) filters.dir = filters.dir === 'asc' ? 'desc' : 'asc';
    else { filters.sort = col; filters.dir = 'asc'; }
}
const arrow = (col) => (filters.sort === col ? (filters.dir === 'asc' ? ' ▲' : ' ▼') : '');

function open(d) { router.push({ name: 'device', params: { id: d.id } }); }
function edit(d) {
    editing.value = d;
    form.value = { unit_number: d.unit_number ?? '', label: d.label ?? '', status: d.status === 'inactive' ? 'inactive' : 'active' };
    formErrors.value = {};
    formError.value = '';
}

async function save() {
    saving.value = true;
    formErrors.value = {};
    formError.value = '';
    try {
        const body = { unit_number: form.value.unit_number || null, label: form.value.label || null };
        if (editing.value.status !== 'released') body.status = form.value.status;
        await api.patch(`/api/admin/devices/${editing.value.id}`, body);
        editing.value = null;
        await load();
    } catch (e) {
        formErrors.value = fieldErrors(e);
        formError.value = Object.keys(formErrors.value).length ? '' : errorMessage(e);
    } finally {
        saving.value = false;
    }
}

const statusInfo = (s) => ({ active: ['ok', 'Activo'], inactive: ['warn', 'Desactivado'], released: ['neutral', 'Liberado'] }[s] ?? ['neutral', s]);
const exportFilters = () => ({ q: filters.q, status: filters.status, unit: filters.unit });
</script>

<template>
    <div class="page-head">
        <div>
            <h1>Equipos</h1>
            <p class="sub">Cada Telpo se identifica por su IMEI. La unidad se asigna aquí o desde la app del equipo.</p>
        </div>
        <div class="actions"><button class="btn" @click="exporting = true">Exportar</button></div>
    </div>

    <div class="filters">
        <div class="field"><label for="q">Buscar</label><input id="q" type="search" v-model="filters.q" placeholder="IMEI, unidad, serie o etiqueta" maxlength="60" /></div>
        <div class="field"><label for="unit">Unidad</label>
            <select id="unit" v-model="filters.unit"><option value="">Todas</option><option v-for="u in extra.units ?? []" :key="u" :value="u">{{ u }}</option></select></div>
        <div class="field"><label for="st">Estado</label>
            <select id="st" v-model="filters.status"><option value="">Todos</option><option value="active">Activo</option><option value="inactive">Desactivado</option><option value="released">Liberado</option></select></div>
        <div class="field"><label for="on">Conexión</label>
            <select id="on" v-model="filters.online"><option value="">Todas</option><option value="yes">En línea</option><option value="no">Sin reportar</option></select></div>
        <div class="field"><label for="nfc">NFC / cámara</label>
            <select id="nfc" v-model="filters.nfc"><option value="">Todos</option><option value="problem">Con problema</option></select></div>
        <button class="btn" @click="reset">Limpiar</button>
    </div>

    <div v-if="error" class="alert error" role="alert">{{ error }} <button class="btn sm" @click="load">Reintentar</button></div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th class="sortable" @click="sortBy('unit_number')">Unidad{{ arrow('unit_number') }}</th>
                    <th class="sortable" @click="sortBy('imei')">IMEI{{ arrow('imei') }}</th>
                    <th>Estado</th>
                    <th>NFC</th>
                    <th>Cámara</th>
                    <th class="sortable" @click="sortBy('last_seen_at')">Última conexión{{ arrow('last_seen_at') }}</th>
                    <th class="sortable num" @click="sortBy('cycle_bytes')">Consumo del periodo{{ arrow('cycle_bytes') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="loading"><td colspan="8"><div class="skeleton"></div></td></tr>
                <tr v-for="d in rows" v-else :key="d.id" class="clickable" @click="open(d)">
                    <td><strong>{{ d.unit_number || '—' }}</strong><div v-if="d.label" class="faint">{{ d.label }}</div></td>
                    <td class="mono">{{ d.imei || 'Sin IMEI' }}</td>
                    <td><StatusBadge :kind="statusInfo(d.status)[0]" :text="statusInfo(d.status)[1]" /> <StatusBadge v-if="d.online" kind="ok" text="En línea" /></td>
                    <td><StatusBadge v-if="d.nfc_enabled === null" text="Sin dato" /><StatusBadge v-else :kind="d.nfc_enabled ? 'ok' : 'bad'" :text="d.nfc_enabled ? 'Activo' : 'Apagado'" /></td>
                    <td><StatusBadge v-if="d.camera_ok === null" text="Sin dato" /><StatusBadge v-else :kind="d.camera_ok ? 'ok' : 'bad'" :text="d.camera_ok ? 'Disponible' : 'Bloqueada'" /></td>
                    <td>{{ timeAgo(d.last_seen_at) }}</td>
                    <td class="num">{{ formatBytes(d.cycle_bytes) }}</td>
                    <td @click.stop><button class="btn sm" @click="edit(d)">Editar</button></td>
                </tr>
            </tbody>
        </table>
        <p v-if="!loading && !rows.length && !error" class="empty">No hay equipos con esos filtros. Los equipos aparecen aquí cuando la app se da de alta.</p>
    </div>
    <Pager :meta="meta" @page="setPage" />

    <Modal v-if="editing" title="Editar equipo" @close="editing = null">
        <div v-if="formError" class="alert error" role="alert">{{ formError }}</div>
        <p class="muted">IMEI: <code>{{ editing.imei || 'sin IMEI' }}</code></p>
        <form id="edit-form" @submit.prevent="save">
            <div class="field"><label for="f-unit">Unidad</label><input id="f-unit" v-model="form.unit_number" maxlength="40" /><span v-if="formErrors.unit_number" class="err">{{ formErrors.unit_number }}</span><span class="help">La Telpo adopta el cambio en su próxima sincronización.</span></div>
            <div class="field"><label for="f-label">Etiqueta (opcional)</label><input id="f-label" v-model="form.label" maxlength="80" /><span v-if="formErrors.label" class="err">{{ formErrors.label }}</span></div>
            <div class="field" v-if="editing.status !== 'released'"><label for="f-st">Estado</label>
                <select id="f-st" v-model="form.status"><option value="active">Activo</option><option value="inactive">Desactivado (no podrá sincronizar)</option></select>
                <span v-if="formErrors.status" class="err">{{ formErrors.status }}</span></div>
            <p v-else class="muted">Este equipo fue liberado (launcher quitado). Debe darse de alta de nuevo desde el equipo.</p>
        </form>
        <template #footer>
            <button class="btn" @click="editing = null">Cancelar</button>
            <button class="btn primary" form="edit-form" :disabled="saving">{{ saving ? 'Guardando…' : 'Guardar' }}</button>
        </template>
    </Modal>
    <ExportModal v-if="exporting" dataset="devices" :filters="exportFilters()" @close="exporting = false" />
</template>
