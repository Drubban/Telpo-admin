<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import api, { errorMessage, fieldErrors } from '../lib/api';
import { formatDateTime } from '../lib/format';
import Modal from '../components/Modal.vue';
import StatusBadge from '../components/StatusBadge.vue';

const items = ref([]);
const loading = ref(true);
const error = ref('');
const notice = ref('');
const editing = ref(null);
const form = ref({ new_password: '', new_password_confirmation: '', admin_password: '' });
const errors = ref({});
const formError = ref('');
const saving = ref(false);

const DESCRIPTIONS = {
    settings: 'Se pide al abrir Configuración del sistema desde el launcher.',
    power: 'Se pide al usar el botón de Reiniciar / Apagar.',
    admin: 'Se pide para salir o quitar el launcher, cambiar de launcher y entrar a los ajustes de la app (IMEI, unidad).',
};

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get('/api/admin/credentials');
        items.value = data.data;
    } catch (e) {
        error.value = errorMessage(e, 'No se pudieron cargar las contraseñas.');
    } finally {
        loading.value = false;
    }
}
onMounted(load);

// --- Contrasenas temporales (15 minutos) ---
const devices = ref([]);
const tempForm = ref({ device: '', kind: 'power' });
const tempBusy = ref(false);
const tempError = ref('');
const tempResult = ref(null);
const nowMs = ref(Date.now());
let ticker = null;

async function loadDevices() {
    try {
        const { data } = await api.get('/api/admin/devices', { params: { per_page: 100, status: 'active', sort: 'unit_number', dir: 'asc' } });
        devices.value = data.data;
    } catch (e) {
        tempError.value = errorMessage(e, 'No se pudo cargar la lista de equipos.');
    }
}
onMounted(() => {
    loadDevices();
    ticker = setInterval(() => { nowMs.value = Date.now(); }, 1000);
});
onBeforeUnmount(() => clearInterval(ticker));

const selectedDevice = computed(() => devices.value.find((d) => d.id === tempForm.value.device) ?? null);
const remainingMs = computed(() => (tempResult.value ? new Date(tempResult.value.expires_at).getTime() - nowMs.value : 0));
const tempExpired = computed(() => tempResult.value !== null && remainingMs.value <= 0);
const countdown = computed(() => {
    const total = Math.max(0, Math.floor(remainingMs.value / 1000));
    return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
});
const codeSpaced = computed(() => (tempResult.value ? tempResult.value.code.replace(/(\d{4})(\d{4})/, '$1 $2') : ''));
const expiresClock = computed(() => (tempResult.value
    ? new Date(tempResult.value.expires_at).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Mexico_City' })
    : ''));

async function generateTemp() {
    tempBusy.value = true;
    tempError.value = '';
    tempResult.value = null;
    try {
        const { data } = await api.post(`/api/admin/devices/${tempForm.value.device}/temp-codes/${tempForm.value.kind}`);
        tempResult.value = data.data;
        nowMs.value = Date.now();
    } catch (e) {
        tempError.value = errorMessage(e, 'No se pudo generar la contraseña temporal.');
    } finally {
        tempBusy.value = false;
    }
}

function open(item) {
    editing.value = item;
    form.value = { new_password: '', new_password_confirmation: '', admin_password: '' };
    errors.value = {};
    formError.value = '';
}

async function save() {
    saving.value = true;
    errors.value = {};
    formError.value = '';
    try {
        const { data } = await api.put(`/api/admin/credentials/${editing.value.kind}`, form.value);
        notice.value = data.message;
        editing.value = null;
        await load();
    } catch (e) {
        errors.value = fieldErrors(e);
        formError.value = Object.keys(errors.value).length ? '' : errorMessage(e);
    } finally {
        form.value.new_password = form.value.new_password_confirmation = form.value.admin_password = '';
        saving.value = false;
    }
}
</script>

<template>
    <div class="page-head">
        <div>
            <h1>Contraseñas</h1>
            <p class="sub">Tres contraseñas distintas para las Telpo. No se pueden consultar: solo definir una nueva. Cada equipo las recibe cifradas en su siguiente sincronización.</p>
        </div>
    </div>

    <div v-if="notice" class="alert ok" role="status">{{ notice }}</div>
    <div v-if="error" class="alert error" role="alert">{{ error }} <button class="btn sm" @click="load">Reintentar</button></div>

    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr))">
        <section v-for="item in items" :key="item.kind" class="card">
            <header><h2>{{ item.label }}</h2><StatusBadge :kind="item.is_set ? 'ok' : 'warn'" :text="item.is_set ? 'Definida' : 'Sin definir'" /></header>
            <p class="muted">{{ DESCRIPTIONS[item.kind] }}</p>
            <p class="faint" v-if="item.is_set">Versión {{ item.version }} · {{ formatDateTime(item.updated_at) }}<span v-if="item.updated_by"> · {{ item.updated_by }}</span></p>
            <button class="btn primary" @click="open(item)">{{ item.is_set ? 'Cambiar contraseña' : 'Definir contraseña' }}</button>
        </section>
    </div>
    <p v-if="loading && !items.length" class="empty">Cargando…</p>

    <section class="card" style="margin-top:14px">
        <header><h2>Contraseñas temporales (15 minutos)</h2></header>
        <p class="muted">Genera un código para que un operador abra Configuración o use Reiniciar/Apagar sin conocer la contraseña fija. Sirve solo en el equipo elegido, solo para ese tipo, y deja de funcionar a los 15 minutos. La Telpo lo valida sin conexión.</p>
        <div v-if="tempError" class="alert error" role="alert">{{ tempError }}</div>
        <form class="filters" @submit.prevent="generateTemp">
            <div class="field"><label for="tdev">Equipo</label>
                <select id="tdev" v-model="tempForm.device" required>
                    <option value="" disabled>Elige una unidad…</option>
                    <option v-for="d in devices" :key="d.id" :value="d.id">{{ d.unit_number || 'Sin unidad' }} · {{ d.imei || 'sin IMEI' }}</option>
                </select></div>
            <div class="field"><label for="tkind">Para</label>
                <select id="tkind" v-model="tempForm.kind"><option value="power">Reiniciar y apagar</option><option value="settings">Configuración del equipo</option></select></div>
            <button class="btn primary" :disabled="tempBusy || !tempForm.device">{{ tempBusy ? 'Generando…' : 'Generar código' }}</button>
        </form>
        <div v-if="selectedDevice && !selectedDevice.temp_code_ready" class="alert" style="background:#fff4d6;color:#5c3d00;border:1px solid #c98a00" role="status">Esta Telpo aún no recibe su llave para contraseñas temporales. Debe sincronizar una vez (cada 15 minutos si tiene internet); hasta entonces el código no le servirá.</div>

        <div v-if="tempResult" class="temp-box" :class="{ expired: tempExpired }" role="status" aria-live="polite">
            <template v-if="!tempExpired">
                <div class="temp-label">{{ tempResult.label }} · {{ selectedDevice?.unit_number || 'unidad' }}</div>
                <div class="temp-code">{{ codeSpaced }}</div>
                <div class="temp-meta">Vence a las {{ expiresClock }} · quedan <strong>{{ countdown }}</strong></div>
                <div class="temp-meta" v-if="!tempResult.device_ready">La Telpo todavía no tiene la llave: este código no funcionará hasta que sincronice.</div>
            </template>
            <template v-else>
                <div class="temp-label">Código vencido</div>
                <div class="temp-meta">Ya no sirve. Genera uno nuevo si el operador lo necesita.</div>
            </template>
        </div>
    </section>

    <Modal v-if="editing" :title="editing.label" @close="editing = null">
        <div v-if="formError" class="alert error" role="alert">{{ formError }}</div>
        <form id="pw-form" @submit.prevent="save" autocomplete="off">
            <div class="field"><label for="np">Nueva contraseña</label><input id="np" type="password" v-model="form.new_password" autocomplete="new-password" maxlength="64" /><span v-if="errors.new_password" class="err" role="alert">{{ errors.new_password }}</span><span class="help">Mínimo 6 caracteres y distinta a las otras dos.</span></div>
            <div class="field"><label for="npc">Confirmar contraseña</label><input id="npc" type="password" v-model="form.new_password_confirmation" autocomplete="new-password" maxlength="64" /></div>
            <div class="field"><label for="ap">Tu contraseña de acceso a esta web</label><input id="ap" type="password" v-model="form.admin_password" autocomplete="current-password" /><span v-if="errors.admin_password" class="err" role="alert">{{ errors.admin_password }}</span></div>
        </form>
        <template #footer>
            <button class="btn" @click="editing = null">Cancelar</button>
            <button class="btn primary" form="pw-form" :disabled="saving || !form.new_password || !form.admin_password">{{ saving ? 'Guardando…' : 'Guardar' }}</button>
        </template>
    </Modal>
</template>

<style scoped>
.temp-box { margin-top: 14px; padding: 16px; border: 2px solid #1f9d4c; border-radius: 12px; text-align: center; }
.temp-box.expired { border-color: #8a8f98; opacity: 0.8; }
.temp-label { font-size: 14px; margin-bottom: 6px; }
.temp-code { font-family: ui-monospace, Consolas, monospace; font-size: 40px; font-weight: 700; letter-spacing: 4px; }
.temp-meta { font-size: 14px; margin-top: 6px; }
</style>
