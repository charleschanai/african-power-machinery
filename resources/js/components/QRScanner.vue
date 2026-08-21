<template>
    <div>
        <div class="relative overflow-hidden rounded-2xl bg-slate-950 ring-1 ring-slate-800">
            <video
                ref="videoRef"
                class="aspect-square w-full object-cover opacity-90"
                muted
                playsinline
            ></video>

            <div
                class="absolute inset-0 flex flex-col items-center justify-center bg-slate-950/85 text-center"
                :class="{ 'hidden': isScanning }"
            >
                <span
                    v-if="status === 'starting'"
                    class="mb-4 h-10 w-10 animate-spin rounded-full border-4 border-emerald-500/30 border-t-emerald-400"
                ></span>
                <span v-if="status === 'error'" class="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-rose-500/15 text-rose-400 ring-1 ring-inset ring-rose-500/30">
                    <CameraOff class="h-7 w-7" />
                </span>
                <span v-if="status === 'idle'" class="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-white/5 text-emerald-400 ring-1 ring-inset ring-white/10">
                    <ScanLine class="h-7 w-7" />
                </span>
                <p class="max-w-xs px-6 text-sm font-medium text-white">
                    {{ statusMessage }}
                </p>
                <button
                    v-if="status === 'error'"
                    type="button"
                    class="btn btn-primary mt-5"
                    @click="start"
                >
                    <RefreshCw class="h-4 w-4" />
                    Try Again
                </button>
            </div>

            <div
                v-if="isScanning"
                class="pointer-events-none absolute inset-0 flex items-center justify-center"
            >
                <div class="relative h-60 w-60">
                    <div class="absolute inset-0 rounded-3xl border-2 border-emerald-400/80 shadow-[0_0_0_9999px_rgba(2,6,23,0.4)]"></div>
                    <div class="absolute inset-x-6 top-0 h-0.5 animate-pulse rounded-full bg-emerald-400"></div>
                    <div class="absolute inset-x-6 bottom-0 h-0.5 animate-pulse rounded-full bg-emerald-400"></div>
                    <div class="absolute left-0 inset-y-6 w-0.5 animate-pulse rounded-full bg-emerald-400"></div>
                    <div class="absolute right-0 inset-y-6 w-0.5 animate-pulse rounded-full bg-emerald-400"></div>
                    <div class="absolute left-1/2 top-8 -translate-x-1/2 text-xs font-semibold tracking-widest text-emerald-300">
                        PLEASE PLACE CARD INSIDE THE FRAME
                    </div>
                </div>
            </div>

            <div
                v-if="decoding"
                class="absolute inset-x-0 bottom-0 bg-emerald-600/95 py-2.5 text-center text-sm font-semibold text-white backdrop-blur"
            >
                <span class="inline-flex items-center gap-2">
                    <span class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                    Reading...
                </span>
            </div>
        </div>

        <button
            type="button"
            class="btn mt-4 w-full"
            :class="isScanning ? 'btn-danger' : 'btn-primary'"
            :disabled="status === 'starting'"
            @click="isScanning ? stop() : start()"
        >
            <span v-if="status === 'starting'" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
            <template v-else>
                <Camera v-if="!isScanning" class="h-4 w-4" />
                <CameraOff v-else class="h-4 w-4" />
            </template>
            {{ isScanning ? 'Stop Camera' : 'Start Camera' }}
        </button>

        <p class="mt-3 flex items-center justify-center gap-1.5 text-center text-xs text-slate-500">
            <Info class="h-3.5 w-3.5" />
            Make sure the card is inside the frame and clearly visible.
        </p>
    </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { BrowserMultiFormatReader } from '@zxing/library';
import { Camera, CameraOff, Info, RefreshCw, ScanLine } from '@lucide/vue';

const emit = defineEmits(['decoded', 'error']);

const videoRef = ref(null);
const isScanning = ref(false);
const status = ref('idle');
const statusMessage = ref('');
const decoding = ref(false);

let codeReader = null;
let lastCode = null;
let lastDecodeAt = 0;
let stream = null;
let closed = false;

function statusText(key) {
    const map = {
        idle: 'Press the button to start the camera.',
        starting: 'Preparing the camera...',
        scanning: 'Scanning card...',
        success: 'Card scanned!',
        error: 'The camera could not be opened. Make sure you have allowed the camera to be used.',
        stop: 'Camera stopped.',
    };
    return map[key] || key;
}

function updateStatus(key) {
    status.value = key;
    statusMessage.value = statusText(key);
}

async function start() {
    if (isScanning.value) return;

    closed = false;
    updateStatus('starting');

    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        const camera = devices.find((d) => d.kind === 'videoinput');

        const videoConfig = camera
            ? {
                  audio: false,
                  video: {
                      deviceId: { exact: camera.deviceId },
                      facingMode: 'environment',
                  },
              }
            : { audio: false, video: { facingMode: 'environment' } };

        codeReader = new BrowserMultiFormatReader();
        stream = await navigator.mediaDevices.getUserMedia(videoConfig);

        if (videoRef.value) {
            videoRef.value.srcObject = stream;
        }

        await codeReader.decodeFromVideoDevice(undefined, videoRef.value, (result) => {
            if (result) {
                handleDecode(result.getText());
            }
        });

        isScanning.value = true;
        updateStatus('scanning');
    } catch (error) {
        updateStatus('error');
        emit('error', error);
        stop();
    }
}

function handleDecode(text) {
    const now = Date.now();

    if (lastCode === text && now - lastDecodeAt < 3000) {
        return;
    }

    lastCode = text;
    lastDecodeAt = now;
    decoding.value = true;

    emit('decoded', text);

    setTimeout(() => {
        decoding.value = false;
    }, 800);
}

function stop() {
    if (codeReader) {
        try {
            codeReader.reset();
        } catch {
            // ignore
        }
    }
    codeReader = null;

    if (stream) {
        stream.getTracks().forEach((track) => track.stop());
        stream = null;
    }

    if (videoRef.value) {
        videoRef.value.srcObject = null;
    }

    isScanning.value = false;
    closed = true;
    updateStatus('stop');
}

onMounted(() => {
    if (!navigator.mediaDevices?.getUserMedia) {
        updateStatus('error');
        statusMessage.value = 'Camera is not supported on this device. Please use the name search or card number.';
    }
});

onBeforeUnmount(() => {
    closed = true;
    stop();
});
</script>
