<script setup>
import { onMounted, onBeforeUnmount, ref } from 'vue';

defineProps({ title: { type: String, required: true }, wide: Boolean });
const emit = defineEmits(['close']);
const box = ref(null);

function onKey(e) {
    if (e.key === 'Escape') emit('close');
}
onMounted(() => {
    document.addEventListener('keydown', onKey);
    document.body.style.overflow = 'hidden';
    box.value?.querySelector('input, select, button.primary')?.focus();
});
onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKey);
    document.body.style.overflow = '';
});
</script>

<template>
    <Teleport to="body">
        <div class="modal-back" @mousedown.self="emit('close')">
            <div ref="box" class="modal" :class="{ wide }" role="dialog" aria-modal="true" :aria-label="title">
                <header>
                    <h2>{{ title }}</h2>
                    <button class="btn sm ghost" @click="emit('close')" aria-label="Cerrar">✕</button>
                </header>
                <div class="body"><slot /></div>
                <footer v-if="$slots.footer"><slot name="footer" /></footer>
            </div>
        </div>
    </Teleport>
</template>
