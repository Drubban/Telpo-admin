<script setup>
import { computed, ref } from 'vue';

// Vista previa de los topes sobre un plano simple (sin mapa de fondo, para no gastar datos ni
// depender de un proveedor de mapas). Sirve para ver si las coordenadas se agrupan donde deben.
const props = defineProps({
    points: { type: Array, default: () => [] }, // { id, name, latitude, longitude, active }
    highlight: { type: String, default: '' },
});
const emit = defineEmits(['select']);

const W = 640;
const H = 260;
const PAD = 24;
const hover = ref(null);

const projected = computed(() => {
    const pts = props.points.filter((p) => Number.isFinite(p.latitude) && Number.isFinite(p.longitude));
    if (!pts.length) return [];
    const lats = pts.map((p) => p.latitude);
    const lngs = pts.map((p) => p.longitude);
    const minLat = Math.min(...lats), maxLat = Math.max(...lats);
    const minLng = Math.min(...lngs), maxLng = Math.max(...lngs);
    // Corrige que un grado de longitud mide menos que uno de latitud.
    const k = Math.cos((((minLat + maxLat) / 2) * Math.PI) / 180);
    const spanX = Math.max((maxLng - minLng) * k, 1e-5);
    const spanY = Math.max(maxLat - minLat, 1e-5);
    const scale = Math.min((W - PAD * 2) / spanX, (H - PAD * 2) / spanY);
    const offX = (W - spanX * scale) / 2;
    const offY = (H - spanY * scale) / 2;
    return pts.map((p) => ({
        ...p,
        x: offX + (p.longitude - minLng) * k * scale,
        y: H - (offY + (p.latitude - minLat) * scale),
    }));
});
</script>

<template>
    <div class="bump-map">
        <svg :viewBox="`0 0 ${W} ${H}`" class="chart" role="img" :aria-label="`Plano con ${points.length} topes`" @mouseleave="hover = null">
            <rect x="0.5" y="0.5" :width="W - 1" :height="H - 1" rx="8" class="axis" fill="none" />
            <g v-for="p in projected" :key="p.id">
                <circle :cx="p.x" :cy="p.y" :r="p.id === highlight ? 8 : 5" :fill="p.active ? 'var(--s1)' : 'var(--text-3)'"
                    :stroke="p.id === highlight ? 'var(--s4)' : 'none'" stroke-width="2" class="bar"
                    @mouseenter="hover = p" @click="emit('select', p)" />
            </g>
            <text v-if="!projected.length" :x="W / 2" :y="H / 2" text-anchor="middle">Aún no hay topes para mostrar</text>
        </svg>
        <p class="faint" style="margin:6px 0 0">{{ hover ? `${hover.name} · ${hover.latitude.toFixed(6)}, ${hover.longitude.toFixed(6)}` : 'Pasa el cursor sobre un punto para ver su nombre. El norte queda arriba.' }}</p>
    </div>
</template>
