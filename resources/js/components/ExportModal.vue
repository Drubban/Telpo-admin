<script setup>
import { ref, computed } from 'vue';
import Modal from './Modal.vue';
import { downloadExport } from '../lib/export';
import { errorMessage } from '../lib/api';
import { todayLocal } from '../lib/format';

// dataset: devices | events | usage | plan. filters: filtros activos de la tabla (se exportan tal cual).
const props = defineProps({ dataset: { type: String, required: true }, filters: { type: Object, default: () => ({}) } });
const emit = defineEmits(['close']);

const scope = ref(props.dataset === 'devices' ? 'all' : 'cycle');
const format = ref('xlsx');
const date = ref(props.filters.date || todayLocal());
const busy = ref(false);
const error = ref('');

const scopes = computed(() => {
    const list = [
        { value: 'day', label: 'Un día' },
        { value: 'week', label: 'Una semana (lunes a domingo)' },
        { value: 'month', label: 'Un mes calendario' },
        { value: 'cycle', label: 'Periodo de consumo (corte día 20)' },
        { value: 'all', label: 'Todo' },
    ];
    // El listado de equipos es una foto actual: no tiene rango de fechas.
    if (props.dataset === 'devices') return list.filter((s) => s.value === 'all');
    // La lista de consumo por unidad es siempre de UN periodo (el que contiene la fecha elegida).
    if (props.dataset === 'plan') return list.filter((s) => s.value === 'cycle');
    return list;
});

const previewName = computed(() => `EX-T20-${date.value || 'AAAA-MM-DD'}.${format.value}`);

async function run() {
    busy.value = true;
    error.value = '';
    try {
        const params = { ...props.filters, dataset: props.dataset, format: format.value, scope: scope.value, date: date.value };
        Object.keys(params).forEach((k) => (params[k] === '' || params[k] == null) && delete params[k]);
        await downloadExport(params);
        emit('close');
    } catch (e) {
        error.value = errorMessage(e, 'No se pudo generar el archivo.');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Modal title="Exportar" @close="emit('close')">
        <div v-if="error" class="alert error" role="alert">{{ error }}</div>
        <div class="field">
            <label for="ex-scope">Alcance</label>
            <select id="ex-scope" v-model="scope" :disabled="scopes.length === 1">
                <option v-for="s in scopes" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
        </div>
        <div class="field" v-if="scope !== 'all'">
            <label for="ex-date">Fecha de referencia</label>
            <input id="ex-date" type="date" v-model="date" />
            <span class="help">El día, la semana, el mes o el periodo que contiene esta fecha.</span>
        </div>
        <div class="field">
            <label for="ex-format">Formato</label>
            <select id="ex-format" v-model="format">
                <option value="xlsx">Excel (.xlsx)</option>
                <option value="csv">CSV (.csv)</option>
                <option value="pdf">PDF (.pdf)</option>
            </select>
        </div>
        <p class="muted">Archivo: <code>{{ previewName }}</code>. Se aplican los filtros activos de la tabla.</p>
        <template #footer>
            <button class="btn" @click="emit('close')">Cancelar</button>
            <button class="btn primary" :disabled="busy" @click="run">{{ busy ? 'Generando…' : 'Descargar' }}</button>
        </template>
    </Modal>
</template>
