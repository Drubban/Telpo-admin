<script setup>
// Barras horizontales con clic para filtrar el resto del tablero (filtro cruzado tipo Power BI).
defineProps({
    items: { type: Array, required: true }, // [{ key, label, value }]
    selected: { type: String, default: null },
    format: { type: Function, default: (v) => String(v) },
    selectable: { type: Boolean, default: true },
});
const emit = defineEmits(['select']);
</script>

<template>
    <div v-if="items.length" role="list">
        <div
            v-for="item in items"
            :key="item.key"
            class="hbar-row"
            :class="{ sel: selected === item.key, dim: selected && selected !== item.key }"
            role="listitem"
            :tabindex="selectable ? 0 : -1"
            :title="`${item.label}: ${format(item.value)}`"
            @click="selectable && emit('select', selected === item.key ? null : item.key)"
            @keydown.enter="selectable && emit('select', selected === item.key ? null : item.key)"
        >
            <span class="lbl">{{ item.label }}</span>
            <span class="hbar-track"><span class="hbar-fill" :style="{ width: `${(item.value / Math.max(...items.map((i) => i.value), 1)) * 100}%`, display: 'block' }"></span></span>
            <span class="val">{{ format(item.value) }}</span>
        </div>
    </div>
    <p v-else class="empty">Sin datos en este periodo.</p>
</template>
