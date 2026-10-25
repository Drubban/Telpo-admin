<script setup>
import { ref, onMounted } from 'vue';
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
