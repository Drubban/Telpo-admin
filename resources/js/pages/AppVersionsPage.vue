<script setup>
import { ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import api, { errorMessage } from '../lib/api';
import { useList } from '../lib/useList';
import { timeAgo } from '../lib/format';
import Pager from '../components/Pager.vue';
import StatusBadge from '../components/StatusBadge.vue';

const router = useRouter();
const { filters, rows, meta, extra, loading, error, load, setPage } = useList('/api/admin/app-versions', {
    q: '', state: '', per_page: 15,
});

const summary = computed(() => extra.value.summary ?? { total: 0, current: 0, outdated: 0, missing: 0, unknown: 0 });
const target = computed(() => extra.value.target ?? { code: null, name: null, source: 'none' });

const STATES = {
    current: ['ok', 'Al día'], outdated: ['warn', 'Actualizar'], missing: ['bad', 'No instalada'], unknown: ['neutral', 'Sin reporte'],
};
function stateOf(d) {
    const b = d.billing_app;
    if (!b.checked_at) return 'unknown';
    if (!b.installed) return 'missing';
    return b.version_code !== null && b.version_code >= (target.value.code ?? 0) ? 'current' : 'outdated';
}

// Version objetivo manual (vacio = automatica).
const editing = ref(false);
const form = ref({ version_code: '', version_name: '' });
const saving = ref(false);
const formError = ref('');
function startEdit() {
    form.value = { version_code: target.value.source === 'manual' ? target.value.code : '', version_name: target.value.name ?? '' };
    formError.value = '';
    editing.value = true;
}
async function save() {
    saving.value = true;
    formError.value = '';
    try {
        await api.put('/api/admin/app-versions/target', {
            version_code: form.value.version_code === '' ? null : Number(form.value.version_code),
            version_name: form.value.version_name || null,
        });
        editing.value = false;
        await load();
    } catch (e) {
        formError.value = errorMessage(e);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="page-head">
        <div>
            <h1>Versión de la app de cobro</h1>
            <p class="sub">Paquete <span class="mono">{{ extra.package }}</span> · versión objetivo:
                <strong>{{ target.name || (target.code ? `código ${target.code}` : 'aún sin datos') }}</strong>
                <span class="faint">({{ target.source === 'manual' ? 'fijada por ti' : 'la más alta de la flota' }})</span></p>
        </div>
        <div class="actions"><button class="btn" @click="startEdit">Fijar versión objetivo</button></div>
    </div>

    <div v-if="editing" class="card" style="margin-bottom:16px">
        <p class="sub" style="margin-top:0">Pon el <strong>código de versión</strong> (versionCode) de la app que quieres que tengan todas las unidades. Déjalo vacío para usar automáticamente la versión más alta que reporte la flota.</p>
        <div class="filters">
            <div class="field"><label for="vc">Código de versión</label><input id="vc" type="number" min="1" v-model="form.version_code" placeholder="Automático" /></div>
            <div class="field"><label for="vn">Nombre (opcional)</label><input id="vn" type="text" maxlength="40" v-model="form.version_name" placeholder="ej. 2.5.0" /></div>
            <div class="field"><label>&nbsp;</label><span><button class="btn" :disabled="saving" @click="save">Guardar</button> <button class="btn sm" @click="editing = false">Cancelar</button></span></div>
        </div>
        <div v-if="formError" class="alert error" role="alert">{{ formError }}</div>
    </div>

    <div class="grid kpis">
        <div class="card kpi"><div class="label">Al día</div><div class="value">{{ summary.current }} <span class="faint" style="font-size:16px">/ {{ summary.total }}</span></div></div>
        <div class="card kpi"><div class="label">Por actualizar</div><div class="value">{{ summary.outdated }}</div></div>
        <div class="card kpi"><div class="label">No instalada</div><div class="value">{{ summary.missing }}</div></div>
        <div class="card kpi"><div class="label">Sin reporte</div><div class="value">{{ summary.unknown }}</div><div class="hint">Aún no sincronizan con la app nueva</div></div>
    </div>

    <div class="filters">
        <div class="field"><label for="q">Buscar</label><input id="q" type="search" v-model="filters.q" placeholder="IMEI o unidad" maxlength="60" /></div>
        <div class="field"><label for="st">Estado</label>
            <select id="st" v-model="filters.state">
                <option value="">Todos</option><option value="outdated">Por actualizar</option><option value="current">Al día</option>
                <option value="missing">No instalada</option><option value="unknown">Sin reporte</option>
            </select></div>
    </div>

    <div v-if="error" class="alert error" role="alert">{{ error }} <button class="btn sm" @click="load">Reintentar</button></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Unidad</th><th>IMEI</th><th>Versión instalada</th><th>Estado</th><th>Revisada</th></tr></thead>
            <tbody>
                <tr v-if="loading"><td colspan="5"><div class="skeleton"></div></td></tr>
                <tr v-for="d in rows" v-else :key="d.id" class="clickable" @click="router.push({ name: 'device', params: { id: d.id } })">
                    <td><strong>{{ d.unit_number || '—' }}</strong></td>
                    <td class="mono">{{ d.imei || 'Sin IMEI' }}</td>
                    <td>{{ d.billing_app.installed ? (d.billing_app.version || '?') + (d.billing_app.version_code !== null ? ` (${d.billing_app.version_code})` : '') : '—' }}</td>
                    <td><StatusBadge :kind="STATES[stateOf(d)][0]" :text="STATES[stateOf(d)][1]" /></td>
                    <td>{{ timeAgo(d.billing_app.checked_at) }}</td>
                </tr>
            </tbody>
        </table>
        <p v-if="!loading && !rows.length && !error" class="empty">Sin equipos para mostrar.</p>
    </div>
    <Pager :meta="meta" @page="setPage" />
</template>
