<script setup>
defineProps({ kind: { type: String, default: 'neutral' }, text: { type: String, required: true } });
</script>

<template><span class="badge" :class="kind">{{ text }}</span></template>
