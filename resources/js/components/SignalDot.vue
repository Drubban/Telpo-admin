<script setup>
import { computed } from 'vue';

// Semaforo de red: verde estable, amarillo intermitente, rojo apagado/con problemas/sin datos.
// No depende solo del color: lleva un simbolo y un texto (accesible para daltonismo).
const props = defineProps({
    state: { type: Number, default: null },   // 0, 1, 2 o null (sin dato)
    reason: { type: String, default: null },
    label: { type: String, default: '' },     // "Datos móviles" / "Wi-Fi"
    compact: { type: Boolean, default: false },
});

const REASON_TEXT = {
    ok: 'Estable',
    slow: 'Lenta',
    unstable: 'Intermitente',
    no_internet: 'Conectado sin internet',
    off: 'Apagado o sin conexión',
};
const STATE_TEXT = ['Estable', 'Intermitente', 'Con problemas'];
const SYMBOL = ['✓', '!', '✕'];

const info = computed(() => {
    if (props.state === null || props.state === undefined) return { cls: 'sn', symbol: '–', text: 'Sin dato' };
    const s = Math.min(2, Math.max(0, props.state));
    return { cls: `s${s}`, symbol: SYMBOL[s], text: REASON_TEXT[props.reason] ?? STATE_TEXT[s] };
});
const title = computed(() => `${props.label ? props.label + ': ' : ''}${info.value.text}`);
</script>

<template>
    <span class="sig" :class="[info.cls, { compact }]" :title="title" role="img" :aria-label="title">
        <span class="dot" aria-hidden="true">{{ info.symbol }}</span>
        <span v-if="!compact" class="txt"><strong v-if="label">{{ label }}</strong> {{ info.text }}</span>
    </span>
</template>

<style scoped>
.sig { display: inline-flex; align-items: center; gap: 8px; }
.dot {
    display: inline-flex; align-items: center; justify-content: center;
    width: 22px; height: 22px; border-radius: 50%;
    font-size: 13px; font-weight: 700; line-height: 1; color: #fff; flex: none;
}
.compact .dot { width: 18px; height: 18px; font-size: 11px; }
.s0 .dot { background: #1f9d4c; }
.s1 .dot { background: #c98a00; }
.s2 .dot { background: #d1242f; }
.sn .dot { background: #8a8f98; }
.txt { font-size: 14px; }
</style>
