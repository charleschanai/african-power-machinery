<template>
    <canvas ref="canvasRef" :class="className"></canvas>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue';
import QRCode from 'qrcode';

const props = defineProps({
    value: { type: String, required: true },
    size: { type: Number, default: 180 },
    className: { type: String, default: '' },
});

const canvasRef = ref(null);

async function render() {
    if (!canvasRef.value || !props.value) return;
    await QRCode.toCanvas(canvasRef.value, props.value, {
        width: props.size,
        margin: 1,
        errorCorrectionLevel: 'M',
        color: { dark: '#0f172a', light: '#ffffff' },
    });
}

onMounted(render);
watch(() => props.value, render);

defineExpose({ canvasRef });
</script>
