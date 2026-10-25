<script setup>
import { computed } from 'vue';

const props = defineProps({ meta: { type: Object, default: null } });
const emit = defineEmits(['page']);
const from = computed(() => props.meta?.from ?? 0);
</script>

<template>
    <div v-if="meta && meta.total > 0" class="pager">
        <span>Mostrando {{ from }}–{{ meta.to }} de {{ meta.total }}</span>
        <span class="actions">
            <button class="btn sm" :disabled="meta.current_page <= 1" @click="emit('page', meta.current_page - 1)">Anterior</button>
            <span>Página {{ meta.current_page }} de {{ meta.last_page }}</span>
            <button class="btn sm" :disabled="meta.current_page >= meta.last_page" @click="emit('page', meta.current_page + 1)">Siguiente</button>
        </span>
    </div>
</template>
