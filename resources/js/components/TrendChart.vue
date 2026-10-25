<script setup>
import { computed, ref } from 'vue';
import { formatDay } from '../lib/format';

// Grafica de columnas (apiladas o no) o de area, en SVG propio.
// series: [{ name, color, values: number[] }]; labels: ['2026-10-20', ...]
const props = defineProps({
    labels: { type: Array, required: true },
    series: { type: Array, required: true },
    kind: { type: String, default: 'columns' }, // columns | area
    stacked: Boolean,
    format: { type: Function, default: (v) => String(v) },
    height: { type: Number, default: 220 },
});

const W = 640;
const padL = 52;
const padR = 10;
const padT = 10;
const padB = 26;
const hover = ref(null);

const n = computed(() => props.labels.length);
const totals = computed(() => props.labels.map((_, i) => props.series.reduce((s, x) => s + (x.values[i] || 0), 0)));
const maxValue = computed(() => {
    const raw = props.stacked || props.series.length === 1
        ? Math.max(...totals.value, 0)
        : Math.max(...props.series.flatMap((s) => s.values), 0);
    return niceMax(raw);
});

function niceMax(v) {
    if (v <= 0) return 1;
    const pow = 10 ** Math.floor(Math.log10(v));
    const f = v / pow;
    return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10) * pow;
}

const innerW = W - padL - padR;
const innerH = computed(() => props.height - padT - padB);
const step = computed(() => innerW / Math.max(n.value, 1));
const y = (v) => padT + innerH.value - (v / maxValue.value) * innerH.value;
const xCenter = (i) => padL + step.value * i + step.value / 2;
const ticks = computed(() => [0, 0.25, 0.5, 0.75, 1].map((t) => t * maxValue.value));
const labelEvery = computed(() => Math.max(1, Math.ceil(n.value / 10)));

const bars = computed(() => {
    const out = [];
    const w = Math.min(28, step.value * 0.7);
    props.labels.forEach((_, i) => {
        let base = 0;
        props.series.forEach((s, si) => {
            const v = s.values[i] || 0;
            if (v <= 0) return;
            const slot = props.stacked ? 1 : props.series.length;
            const bw = props.stacked ? w : w / slot;
            const x = props.stacked ? xCenter(i) - w / 2 : xCenter(i) - w / 2 + si * bw;
            const y0 = props.stacked ? y(base + v) : y(v);
            const h = props.stacked ? y(base) - y(base + v) : y(0) - y(v);
            out.push({ x, y: y0, w: Math.max(bw - 1, 1), h: Math.max(h, 1), color: s.color, key: `${i}-${si}` });
            if (props.stacked) base += v;
        });
    });
    return out;
});

const area = computed(() => {
    if (props.kind !== 'area' || !n.value) return null;
    const s = props.series[0];
    const pts = s.values.map((v, i) => [xCenter(i), y(v || 0)]);
    const line = pts.map((p, i) => `${i ? 'L' : 'M'}${p[0].toFixed(1)},${p[1].toFixed(1)}`).join(' ');
    const fill = `${line} L${pts[pts.length - 1][0].toFixed(1)},${y(0)} L${pts[0][0].toFixed(1)},${y(0)} Z`;
    return { line, fill, pts, color: s.color };
});

const hasData = computed(() => totals.value.some((v) => v > 0));
const tip = ref({ x: 0, y: 0 });
function move(e) { tip.value = { x: e.clientX + 12, y: e.clientY + 12 }; }
</script>

<template>
    <div>
        <svg v-if="hasData" class="chart" :viewBox="`0 0 ${W} ${height}`" role="img" :aria-label="series.map((s) => s.name).join(', ')" @mouseleave="hover = null" @mousemove="move">
            <g v-for="t in ticks" :key="t">
                <line class="axis" :x1="padL" :x2="W - padR" :y1="y(t)" :y2="y(t)" />
                <text :x="padL - 6" :y="y(t) + 4" text-anchor="end">{{ format(t) }}</text>
            </g>
            <template v-if="kind === 'columns'">
                <rect v-for="b in bars" :key="b.key" class="bar" :x="b.x" :y="b.y" :width="b.w" :height="b.h" :style="{ fill: b.color }" rx="2" />
            </template>
            <template v-else-if="area">
                <path :d="area.fill" :style="{ fill: area.color }" opacity="0.18" />
                <path :d="area.line" fill="none" :style="{ stroke: area.color }" stroke-width="2" stroke-linejoin="round" />
            </template>
            <!-- zonas de hover por dia -->
            <rect v-for="(l, i) in labels" :key="`h${i}`" :x="padL + step * i" :y="padT" :width="step" :height="innerH" fill="transparent" @mouseenter="hover = i" />
            <line v-if="hover !== null" class="axis" :x1="xCenter(hover)" :x2="xCenter(hover)" :y1="padT" :y2="padT + innerH" stroke-dasharray="3 3" />
            <text v-for="(l, i) in labels" :key="`l${i}`" v-show="i % labelEvery === 0" :x="xCenter(i)" :y="height - 8" text-anchor="middle">{{ formatDay(l) }}</text>
        </svg>
        <p v-else class="empty">Sin datos en este periodo.</p>
        <div v-if="hover !== null" class="tooltip" :style="{ left: `${tip.x}px`, top: `${tip.y}px` }">
            <div>{{ formatDay(labels[hover], true) }}</div>
            <div v-for="s in series" :key="s.name">{{ s.name }}: {{ format(s.values[hover] || 0) }}</div>
        </div>
        <div v-if="series.length > 1" class="legend">
            <span v-for="s in series" :key="s.name"><i :style="{ background: s.color }"></i>{{ s.name }}</span>
        </div>
    </div>
</template>
