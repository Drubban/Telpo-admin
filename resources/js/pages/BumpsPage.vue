<script setup>
import { ref, computed, onMounted } from 'vue';
import api, { errorMessage, fieldErrors } from '../lib/api';
import { useList } from '../lib/useList';
import { timeAgo } from '../lib/format';
import Modal from '../components/Modal.vue';
import Pager from '../components/Pager.vue';
import StatusBadge from '../components/StatusBadge.vue';
import BumpMap from '../components/BumpMap.vue';

const { filters, rows, meta, extra, loading, error, load, setPage, reset } = useList('/api/admin/speed-bumps', {
    q: '', route: '', active: '', per_page: 15,
});

const routes = computed(() => extra.value.routes ?? []);
const summary = computed(() => extra.value.summary ?? { total: 0, active: 0, version: '' });

// --- Mapa de vista previa ---
const points = ref([]);
const highlight = ref('');
async function loadMap() {
    try {
        const { data } = await api.get('/api/admin/speed-bumps/map');
        points.value = data.data;
    } catch { /* el mapa es opcional: sin el, la tabla sigue funcionando */ }
}
onMounted(loadMap);
async function refreshAll() { await Promise.all([load(), loadMap()]); }

// --- Sentidos ---
const HEADINGS = [
    [null, 'Ambos sentidos'], [0, 'Norte (0°)'], [45, 'Noreste (45°)'], [90, 'Este (90°)'], [135, 'Sureste (135°)'],
    [180, 'Sur (180°)'], [225, 'Suroeste (225°)'], [270, 'Oeste (270°)'], [315, 'Noroeste (315°)'],
];
function headingLabel(deg) {
    if (deg === null || deg === undefined) return 'Ambos';
    return HEADINGS.find(([v]) => v === deg)?.[1] ?? `${deg}°`;
}
const mapsUrl = (b) => `https://www.google.com/maps?q=${b.latitude},${b.longitude}`;

// --- Alta y edicion ---
const editing = ref(null); // null | 'new' | tope
const form = ref(blank());
const formErrors = ref({});
const formError = ref('');
const saving = ref(false);

function blank() {
    return { name: '', latitude: '', longitude: '', radius_m: '', heading_deg: '', active: true, notes: '', route_ids: [] };
}
function openNew() { editing.value = 'new'; form.value = blank(); formErrors.value = {}; formError.value = ''; }
function openEdit(b) {
    editing.value = b;
    form.value = {
        name: b.name, latitude: String(b.latitude), longitude: String(b.longitude),
        radius_m: b.radius_m ?? '', heading_deg: b.heading_deg ?? '', active: b.active, notes: b.notes ?? '',
        route_ids: b.routes.map((r) => r.id),
    };
    formErrors.value = {};
    formError.value = '';
    highlight.value = b.id;
}
function closeForm() { editing.value = null; }

/** Si se pega "19.8244, -99.1120" en latitud, se reparte en las dos casillas. */
function splitPasted() {
    const m = String(form.value.latitude).match(/^\s*(-?\d+(?:\.\d+)?)\s*[,;\s]\s*(-?\d+(?:\.\d+)?)\s*$/);
    if (m) { form.value.latitude = m[1]; form.value.longitude = m[2]; }
}

const num = (v) => (v === '' || v === null ? null : Number(v));

async function save() {
    saving.value = true;
    formErrors.value = {};
    formError.value = '';
    const body = {
        name: form.value.name.trim(),
        latitude: num(form.value.latitude),
        longitude: num(form.value.longitude),
        radius_m: num(form.value.radius_m),
        heading_deg: num(form.value.heading_deg),
        active: form.value.active,
        notes: form.value.notes?.trim() || null,
        route_ids: form.value.route_ids,
    };
    try {
        if (editing.value === 'new') await api.post('/api/admin/speed-bumps', body);
        else await api.patch(`/api/admin/speed-bumps/${editing.value.id}`, body);
        closeForm();
        await refreshAll();
    } catch (e) {
        formErrors.value = fieldErrors(e);
        formError.value = Object.keys(formErrors.value).length ? '' : errorMessage(e);
    } finally {
        saving.value = false;
    }
}

// --- Eliminar ---
const deleting = ref(null);
const deleteError = ref('');
async function confirmDelete() {
    deleteError.value = '';
    try {
        await api.delete(`/api/admin/speed-bumps/${deleting.value.id}`);
        deleting.value = null;
        await refreshAll();
    } catch (e) {
        deleteError.value = errorMessage(e);
    }
}

// --- Importar ---
const importing = ref(false);
const imp = ref({ text: '', radius_m: '', heading_deg: '', route_ids: [] });
const preview = ref(null);
const importError = ref('');
const importBusy = ref(false);

function openImport() { importing.value = true; imp.value = { text: '', radius_m: '', heading_deg: '', route_ids: [] }; preview.value = null; importError.value = ''; }
function importBody(dry) {
    return { text: imp.value.text, dry_run: dry, radius_m: num(imp.value.radius_m), heading_deg: num(imp.value.heading_deg), route_ids: imp.value.route_ids };
}
async function reviewImport() {
    importBusy.value = true;
    importError.value = '';
    try {
        const { data } = await api.post('/api/admin/speed-bumps/import', importBody(true));
        preview.value = data;
    } catch (e) {
        importError.value = errorMessage(e);
    } finally {
        importBusy.value = false;
    }
}
async function saveImport() {
    importBusy.value = true;
    importError.value = '';
    try {
        await api.post('/api/admin/speed-bumps/import', importBody(false));
        importing.value = false;
        await refreshAll();
    } catch (e) {
        importError.value = errorMessage(e);
    } finally {
        importBusy.value = false;
    }
}

// --- Rutas ---
const managingRoutes = ref(false);
const routeName = ref('');
const routeError = ref('');
const renaming = ref({}); // id -> texto en edicion
async function addRoute() {
    routeError.value = '';
    try {
        await api.post('/api/admin/bump-routes', { name: routeName.value.trim() });
        routeName.value = '';
        await load();
    } catch (e) { routeError.value = errorMessage(e); }
}
async function renameRoute(r) {
    routeError.value = '';
    try {
        await api.patch(`/api/admin/bump-routes/${r.id}`, { name: (renaming.value[r.id] ?? r.name).trim() });
        delete renaming.value[r.id];
        await load();
    } catch (e) { routeError.value = errorMessage(e); }
}
async function removeRoute(r) {
    routeError.value = '';
    if (!window.confirm(`¿Quitar la ruta "${r.name}"? Los topes no se borran, solo pierden esta etiqueta.`)) return;
    try {
        await api.delete(`/api/admin/bump-routes/${r.id}`);
        if (filters.route === r.id) filters.route = '';
        await load();
    } catch (e) { routeError.value = errorMessage(e); }
}
</script>

<template>
    <div class="page-head">
        <div>
            <h1>Topes</h1>
            <p class="sub">
                Las Telpo avisan con un timbre al acercarse a estos puntos. <strong>Todas las unidades reciben todos los topes activos</strong>,
                sin importar la ruta; las rutas solo sirven para organizar.
            </p>
        </div>
        <div class="actions">
            <button class="btn" @click="managingRoutes = true">Rutas</button>
            <button class="btn" @click="openImport">Importar coordenadas</button>
            <button class="btn primary" @click="openNew">Nuevo tope</button>
        </div>
    </div>

    <div class="grid kpis">
        <div class="card kpi"><div class="label">Topes registrados</div><div class="value">{{ summary.total }}</div></div>
        <div class="card kpi"><div class="label">Activos (se envían a las Telpo)</div><div class="value">{{ summary.active }}</div>
            <div class="hint">Versión de la lista: <code>{{ summary.version || '—' }}</code></div></div>
    </div>

    <section class="card" style="margin-bottom:14px">
        <header><h2>Vista previa</h2><span class="faint">Plano simple, sin mapa de fondo</span></header>
        <BumpMap :points="points" :highlight="highlight" />
    </section>

    <div class="filters">
        <div class="field"><label for="q">Buscar</label><input id="q" type="search" v-model="filters.q" placeholder="Nombre o nota" maxlength="60" /></div>
        <div class="field"><label for="rt">Ruta</label>
            <select id="rt" v-model="filters.route"><option value="">Todas</option><option v-for="r in routes" :key="r.id" :value="r.id">{{ r.name }} ({{ r.bumps_count }})</option></select></div>
        <div class="field"><label for="ac">Estado</label>
            <select id="ac" v-model="filters.active"><option value="">Todos</option><option value="1">Activos</option><option value="0">Desactivados</option></select></div>
        <button class="btn" @click="reset">Limpiar</button>
    </div>

    <div v-if="error" class="alert error" role="alert">{{ error }} <button class="btn sm" @click="load">Reintentar</button></div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Nombre</th><th>Rutas</th><th>Coordenadas</th><th class="num">Radio</th><th>Sentido</th><th>Estado</th><th></th></tr>
            </thead>
            <tbody>
                <tr v-if="loading"><td colspan="7"><div class="skeleton"></div></td></tr>
                <tr v-for="b in rows" v-else :key="b.id">
                    <td><strong>{{ b.name }}</strong><div v-if="b.notes" class="faint">{{ b.notes }}</div></td>
                    <td><span v-if="!b.routes.length" class="faint">—</span><StatusBadge v-for="r in b.routes" :key="r.id" :text="r.name" /></td>
                    <td class="mono">{{ b.latitude.toFixed(6) }}, {{ b.longitude.toFixed(6) }} <a :href="mapsUrl(b)" target="_blank" rel="noopener noreferrer">Ver</a></td>
                    <td class="num">{{ b.radius_m ? `${b.radius_m} m` : 'Auto' }}</td>
                    <td>{{ headingLabel(b.heading_deg) }}</td>
                    <td><StatusBadge :kind="b.active ? 'ok' : 'neutral'" :text="b.active ? 'Activo' : 'Desactivado'" /></td>
                    <td class="actions"><button class="btn sm" @click="openEdit(b)">Editar</button><button class="btn sm danger" @click="deleting = b; deleteError = ''">Eliminar</button></td>
                </tr>
            </tbody>
        </table>
        <p v-if="!loading && !rows.length && !error" class="empty">Aún no hay topes con esos filtros. Usa "Importar coordenadas" para pegar varios de una vez.</p>
    </div>
    <Pager :meta="meta" @page="setPage" />

    <!-- Alta / edición -->
    <Modal v-if="editing" :title="editing === 'new' ? 'Nuevo tope' : 'Editar tope'" @close="closeForm">
        <div v-if="formError" class="alert error" role="alert">{{ formError }}</div>
        <form id="bump-form" @submit.prevent="save">
            <div class="field"><label for="b-name">Nombre</label><input id="b-name" v-model="form.name" maxlength="80" required /><span v-if="formErrors.name" class="err">{{ formErrors.name }}</span></div>
            <div class="grid" style="grid-template-columns:1fr 1fr">
                <div class="field"><label for="b-lat">Latitud</label><input id="b-lat" v-model="form.latitude" inputmode="decimal" placeholder="19.824410" required @input="splitPasted" /><span v-if="formErrors.latitude" class="err">{{ formErrors.latitude }}</span></div>
                <div class="field"><label for="b-lng">Longitud</label><input id="b-lng" v-model="form.longitude" inputmode="decimal" placeholder="-99.112018" required /><span v-if="formErrors.longitude" class="err">{{ formErrors.longitude }}</span></div>
            </div>
            <p class="faint" style="margin-top:-6px">Puedes pegar "latitud, longitud" completo en la casilla de latitud.</p>
            <div class="grid" style="grid-template-columns:1fr 1fr">
                <div class="field"><label for="b-rad">Radio de aviso (m)</label><input id="b-rad" v-model="form.radius_m" inputmode="numeric" placeholder="Auto (según velocidad)" /><span v-if="formErrors.radius_m" class="err">{{ formErrors.radius_m }}</span><span class="help">Vacío = el aviso se adelanta según la velocidad (80 a 250 m).</span></div>
                <div class="field"><label for="b-head">Sentido</label>
                    <select id="b-head" v-model="form.heading_deg"><option v-for="[v, l] in HEADINGS" :key="l" :value="v ?? ''">{{ l }}</option></select>
                    <span v-if="formErrors.heading_deg" class="err">{{ formErrors.heading_deg }}</span><span class="help">Si el tope es de un solo carril, elige hacia dónde se circula.</span></div>
            </div>
            <div class="field"><label>Rutas</label>
                <div v-if="!routes.length" class="faint">Aún no hay rutas. Créalas con el botón "Rutas".</div>
                <label v-for="r in routes" :key="r.id" style="font-weight:400"><input type="checkbox" :value="r.id" v-model="form.route_ids" /> {{ r.name }}</label>
                <span v-if="formErrors.route_ids" class="err">{{ formErrors.route_ids }}</span></div>
            <div class="field"><label for="b-notes">Nota (opcional)</label><input id="b-notes" v-model="form.notes" maxlength="255" /></div>
            <label style="font-weight:400"><input type="checkbox" v-model="form.active" /> Activo (se envía a las Telpo)</label>
        </form>
        <template #footer>
            <button class="btn" @click="closeForm">Cancelar</button>
            <button class="btn primary" form="bump-form" :disabled="saving">{{ saving ? 'Guardando…' : 'Guardar' }}</button>
        </template>
    </Modal>

    <!-- Eliminar -->
    <Modal v-if="deleting" title="Eliminar tope" @close="deleting = null">
        <div v-if="deleteError" class="alert error" role="alert">{{ deleteError }}</div>
        <p>¿Eliminar <strong>{{ deleting.name }}</strong>? Las Telpo dejarán de avisar de este tope en su próxima sincronización. Si solo quieres pausarlo, desactívalo.</p>
        <template #footer>
            <button class="btn" @click="deleting = null">Cancelar</button>
            <button class="btn danger" @click="confirmDelete">Eliminar</button>
        </template>
    </Modal>

    <!-- Importar -->
    <Modal v-if="importing" title="Importar coordenadas" wide @close="importing = false">
        <div v-if="importError" class="alert error" role="alert">{{ importError }}</div>
        <template v-if="!preview">
            <p class="muted">Pega una coordenada por línea, con el nombre opcional al final. Se aceptan comas, tabulador o espacios, y puedes copiarlas directo de una hoja de cálculo.</p>
            <div class="field"><label for="i-text">Coordenadas</label>
                <textarea id="i-text" v-model="imp.text" rows="9" spellcheck="false" placeholder="19.824410, -99.112018    Crucero Bocanegra&#10;19.828970, -99.096018    Villas" style="font-family:var(--mono);font-size:12.5px"></textarea></div>
            <div class="grid" style="grid-template-columns:1fr 1fr">
                <div class="field"><label for="i-rad">Radio de aviso (m), opcional</label><input id="i-rad" v-model="imp.radius_m" inputmode="numeric" placeholder="Auto" /></div>
                <div class="field"><label for="i-head">Sentido, opcional</label>
                    <select id="i-head" v-model="imp.heading_deg"><option v-for="[v, l] in HEADINGS" :key="l" :value="v ?? ''">{{ l }}</option></select></div>
            </div>
            <div class="field"><label>Rutas a las que pertenecen</label>
                <label v-for="r in routes" :key="r.id" style="font-weight:400"><input type="checkbox" :value="r.id" v-model="imp.route_ids" /> {{ r.name }}</label>
                <span v-if="!routes.length" class="faint">Aún no hay rutas. Puedes importar sin ruta y asignarlas después.</span></div>
        </template>
        <template v-else>
            <p>
                <StatusBadge kind="ok" :text="`${preview.summary.new} nuevos`" />
                <StatusBadge v-if="preview.summary.duplicates" kind="warn" :text="`${preview.summary.duplicates} ya existen (se omiten)`" />
                <StatusBadge v-if="preview.summary.errors" kind="bad" :text="`${preview.summary.errors} con error`" />
            </p>
            <div class="table-wrap" style="max-height:280px;overflow:auto">
                <table>
                    <thead><tr><th>Línea</th><th>Nombre</th><th>Coordenadas</th><th>Resultado</th></tr></thead>
                    <tbody>
                        <tr v-for="r in preview.rows" :key="r.line"><td>{{ r.line }}</td><td>{{ r.name }}</td><td class="mono">{{ r.latitude.toFixed(6) }}, {{ r.longitude.toFixed(6) }}</td>
                            <td><StatusBadge :kind="r.status === 'new' ? 'ok' : 'warn'" :text="r.status === 'new' ? 'Nuevo' : `Ya existe (${r.duplicate_of})`" /></td></tr>
                        <tr v-for="e in preview.errors" :key="'e' + e.line"><td>{{ e.line }}</td><td colspan="2" class="faint">{{ e.text }}</td><td><StatusBadge kind="bad" :text="e.reason" /></td></tr>
                    </tbody>
                </table>
            </div>
        </template>
        <template #footer>
            <button class="btn" @click="importing = false">Cancelar</button>
            <template v-if="!preview">
                <button class="btn primary" :disabled="importBusy || !imp.text.trim()" @click="reviewImport">{{ importBusy ? 'Revisando…' : 'Revisar' }}</button>
            </template>
            <template v-else>
                <button class="btn" @click="preview = null">Volver</button>
                <button class="btn primary" :disabled="importBusy || !preview.summary.new" @click="saveImport">{{ importBusy ? 'Guardando…' : `Guardar ${preview.summary.new} topes` }}</button>
            </template>
        </template>
    </Modal>

    <!-- Rutas -->
    <Modal v-if="managingRoutes" title="Rutas" @close="managingRoutes = false">
        <div v-if="routeError" class="alert error" role="alert">{{ routeError }}</div>
        <p class="muted">Sirven para organizar y filtrar. No cambian a qué Telpo le llega cada tope.</p>
        <div v-for="r in routes" :key="r.id" class="actions" style="margin-bottom:8px">
            <input :value="renaming[r.id] ?? r.name" @input="renaming[r.id] = $event.target.value" maxlength="60" :aria-label="`Nombre de la ruta ${r.name}`" style="flex:1" />
            <span class="faint">{{ r.bumps_count }} topes</span>
            <button class="btn sm" :disabled="(renaming[r.id] ?? r.name) === r.name" @click="renameRoute(r)">Guardar</button>
            <button class="btn sm danger" @click="removeRoute(r)">Quitar</button>
        </div>
        <p v-if="!routes.length" class="faint">Aún no hay rutas.</p>
        <form class="actions" style="margin-top:14px" @submit.prevent="addRoute">
            <input v-model="routeName" maxlength="60" placeholder="Nombre de la nueva ruta" aria-label="Nueva ruta" style="flex:1" />
            <button class="btn primary" :disabled="!routeName.trim()">Agregar</button>
        </form>
    </Modal>
</template>
