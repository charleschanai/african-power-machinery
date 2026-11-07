<template>
    <div>
        <div class="relative overflow-hidden rounded-2xl bg-slate-950 ring-1 ring-slate-800">
            <video
                ref="videoRef"
                class="aspect-square w-full object-cover opacity-90"
                autoplay
                muted
                playsinline
            ></video>

            <div
                class="absolute inset-0 flex flex-col items-center justify-center bg-slate-950/85 px-4 text-center"
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
                <p class="max-w-xs px-1 text-sm font-medium text-white">
                    {{ statusMessage }}
                </p>
                <button
                    v-if="status === 'error' && errorHint"
                    type="button"
                    class="btn btn-secondary mt-5 text-xs"
                    @click="errorHintVisible = !errorHintVisible"
                >
                    <Info class="h-4 w-4" />
                    {{ errorHintVisible ? 'Hide help' : 'How do I fix this?' }}
                </button>
                <p
                    v-if="status === 'error' && errorHint && errorHintVisible"
                    class="mt-3 max-w-xs rounded-xl bg-white/5 px-4 py-3 text-left text-xs leading-relaxed text-slate-300 ring-1 ring-inset ring-white/10"
                >
                    {{ errorHint }}
                </p>
                <button
                    v-if="status === 'error' && canRetry"
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
                    <div class="absolute left-1/2 top-8 -translate-x-1/2 whitespace-nowrap text-xs font-semibold tracking-widest text-emerald-300">
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
            :disabled="status === 'starting' || !cameraSupported"
            @click="isScanning ? stop() : start()"
        >
            <span v-if="status === 'starting'" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
            <template v-else>
                <Camera v-if="!isScanning" class="h-4 w-4" />
                <CameraOff v-else class="h-4 w-4" />
            </template>
            {{ isScanning ? 'Stop Camera' : 'Start Camera' }}
        </button>

        <p
            v-if="showSupportWarning"
            class="mt-3 flex items-start justify-center gap-1.5 text-center text-xs text-amber-600"
        >
            <Info class="mt-px h-3.5 w-3.5 shrink-0" />
            <span>{{ supportWarning }}</span>
        </p>

        <p class="mt-3 flex items-center justify-center gap-1.5 text-center text-xs text-slate-500">
            <Info class="h-3.5 w-3.5" />
            Make sure the card is inside the frame and clearly visible.
        </p>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { BrowserMultiFormatReader } from '@zxing/library';
import { Camera, CameraOff, Info, RefreshCw, ScanLine } from '@lucide/vue';

const emit = defineEmits(['decoded', 'error']);

const videoRef = ref(null);
const isScanning = ref(false);
const status = ref('idle');
const statusMessage = ref('');
const decoding = ref(false);
const errorKind = ref(null);
const errorHintVisible = ref(false);
const isSecureContext = ref(true);

let codeReader = null;
let lastCode = null;
let lastDecodeAt = 0;
let stream = null;

/**
 * getUserMedia constraints, tried in order until one is granted.
 *
 * `facingMode` is `ideal` (a preference, not a demand) and `deviceId` is never
 * used on purpose: `enumerateDevices()` only returns real, non-empty device ids
 * *after* the camera permission has been granted. Calling it beforehand returns
 * `deviceId === ''`, and `deviceId: { exact: '' }` can never be satisfied, so
 * getUserMedia rejects with OverconstrainedError. That was why the camera never
 * opened on Android/iOS while it appeared to work on some desktops.
 */
const CAMERA_CONSTRAINTS = [
    { audio: false, video: { facingMode: { ideal: 'environment' } } },
    { audio: false, video: { facingMode: { ideal: 'user' } } },
    { audio: false, video: true },
];

/** How long to wait for ZXing to start before showing the live preview anyway. */
const DECODER_START_TIMEOUT = 3000;

const STATUS_TEXT = {
    idle: 'Press the button to start the camera.',
    starting: 'Preparing the camera...',
    scanning: 'Scanning card...',
    success: 'Card scanned!',
    stop: 'Camera stopped.',
};

const ERROR_TEXT = {
    insecure:
        'The camera only works on a secure HTTPS address. This page was opened over plain HTTP.',
    unsupported:
        'This browser cannot open a camera. Please use the name search or card number instead.',
    denied:
        'Camera access was denied. Please allow the camera for this site, then tap Try Again.',
    blocked:
        'The camera is blocked for this site. It has to be re-enabled in your browser settings before it can be used.',
    notfound:
        'No camera was found on this device. Please use the name search or card number instead.',
    busy:
        'The camera is already in use by another app. Close any app or browser tab using it, then try again.',
    overconstrained:
        'The camera could not be started with the requested settings. Please try again.',
    ended:
        'The camera was stopped by the system. Tap Try Again to restart it.',
    unknown: 'The camera could not be opened. Please try again.',
};

const ERROR_HINTS = {
    insecure:
        'Browsers only expose the camera over HTTPS (localhost is the only plain-HTTP exception). Reopen this page with its https:// address, or configure an SSL certificate for this site.',
    notfound:
        'Make sure the operating system allows the browser to use the camera and that a camera is actually connected.',
    overconstrained:
        'Something is already holding the camera, such as a video call app or another tab with the scanner open.',
    ended:
        'This normally happens after the phone locks its screen or the app was sent to the background.',
    unknown: '',
};

function isIos() {
    return (
        /iP(hone|ad|od)/.test(navigator.userAgent) ||
        (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
    );
}

function permissionHelp() {
    return isIos()
        ? 'On iPhone: Settings > Privacy & Security > Camera, or Safari > Settings > Camera for this website, turn it on, then reload the page.'
        : 'On Android: browser menu > Settings > Site settings > Camera, allow it for this site, then reload the page. On desktop, click the camera icon in the address bar.';
}

const cameraSupported = computed(
    () => isSecureContext.value && !!navigator.mediaDevices?.getUserMedia
);

const showSupportWarning = computed(
    () => !isSecureContext.value || !navigator.mediaDevices?.getUserMedia
);

const supportWarning = computed(() =>
    !isSecureContext.value
        ? 'This page was opened over plain HTTP, so the browser hides the camera. Use the https:// address.'
        : 'This browser does not support camera access. Use the name or card number search instead.'
);

const errorHint = computed(() => {
    if (errorKind.value === 'denied' || errorKind.value === 'blocked') {
        return permissionHelp();
    }

    return ERROR_HINTS[errorKind.value] ?? '';
});

/**
 * Retrying only helps for transient or user-fixable problems. Offering "Try
 * Again" for plain HTTP or an unsupported browser just produces the same
 * failure again.
 */
const canRetry = computed(
    () => !['insecure', 'unsupported'].includes(errorKind.value)
);

function setStatus(key) {
    status.value = key;
    statusMessage.value = STATUS_TEXT[key] || key;
}

function fail(kind) {
    errorKind.value = kind;
    errorHintVisible.value = false;
    status.value = 'error';
    statusMessage.value = ERROR_TEXT[kind] || ERROR_TEXT.unknown;
}

/**
 * Turns a getUserMedia rejection into a stable kind so the user is told what
 * actually went wrong instead of a generic "camera error".
 */
function toErrorKind(error) {
    const name = error?.name || '';
    const message = String(error?.message || '').toLowerCase();

    if (
        name === 'NotAllowedError' ||
        name === 'PermissionDeniedError' ||
        name === 'PermissionDismissedError' ||
        message.includes('permission')
    ) {
        return 'denied';
    }

    if (name === 'SecurityError' || message.includes('insecure')) {
        return 'insecure';
    }

    if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
        return 'notfound';
    }

    if (name === 'NotReadableError' || name === 'TrackStartError') {
        return 'busy';
    }

    if (name === 'OverconstrainedError' || name === 'ConstraintNotSatisfiedError') {
        return 'overconstrained';
    }

    // Thrown when the camera API is hidden entirely, e.g. inside a cross-origin
    // iframe, or by a browser with no camera support.
    if (name === 'NotSupportedError' || name === 'NotSupported') {
        return 'unsupported';
    }

    if (!isSecureContext.value) {
        return 'insecure';
    }

    return 'unknown';
}

function prepareVideoElement(video) {
    // iOS only plays inline instead of taking over the whole screen when these
    // are set, and older iOS versions ignore the HTML attribute form.
    video.autoplay = true;
    video.muted = true;
    video.playsInline = true;
    video.setAttribute('playsinline', 'true');
    video.setAttribute('muted', 'true');
}

/**
 * Opens the camera exactly once.
 *
 * The old code called getUserMedia itself and then called `decodeFromVideoDevice`,
 * which opens a *second* stream for the same camera and replaces the one on the
 * video element. iOS Safari only tolerates a single camera stream, and the first
 * stream was leaked because only that first one was tracked.
 */
async function openCamera() {
    if (!window.isSecureContext) {
        isSecureContext.value = false;
        throw Object.assign(new Error('Insecure context'), { name: 'SecurityError' });
    }

    const media = navigator.mediaDevices;

    if (!media?.getUserMedia) {
        throw Object.assign(new Error('getUserMedia is unsupported'), {
            name: 'NotSupportedError',
        });
    }

    let lastError = null;

    for (const constraints of CAMERA_CONSTRAINTS) {
        try {
            return await media.getUserMedia(constraints);
        } catch (error) {
            lastError = error;

            // Neither of these is fixed by asking for different constraints, so
            // there is no point in trying the remaining ones.
            if (error?.name === 'NotAllowedError' || error?.name === 'SecurityError') {
                throw error;
            }
        }
    }

    throw lastError || new Error('No camera available');
}

/**
 * Phones release the camera when the app is backgrounded or the screen locks.
 * Without this the preview silently freezes on a black frame.
 */
function watchStream(activeStream) {
    const track = activeStream?.getVideoTracks?.()[0];

    if (!track || track.readyState === 'ended') return;

    track.addEventListener('ended', () => {
        // Ignore teardown we caused ourselves.
        if (stream !== activeStream) return;

        releaseCamera();
        fail('ended');
    });
}

async function start() {
    if (isScanning.value || status.value === 'starting') return;

    errorKind.value = null;
    setStatus('starting');

    let acquired;

    try {
        acquired = await openCamera();
    } catch (error) {
        fail(toErrorKind(error));
        releaseCamera();
        emit('error', error);
        return;
    }

    // Adopt the stream straight away so it can never leak, whatever happens next.
    stream = acquired;

    try {
        const video = videoRef.value;

        if (!video) {
            throw new Error('Video element is missing');
        }

        prepareVideoElement(video);

        if (codeReader) {
            try {
                codeReader.reset();
            } catch {
                // The previous reader had nothing to clean up.
            }
            codeReader = null;
        }

        codeReader = new BrowserMultiFormatReader();

        // decodeFromStream re-uses our stream. decodeFromVideoDevice() would
        // call getUserMedia again for the same camera.
        const decodeSession = codeReader.decodeFromStream(acquired, video, (result) => {
            if (result && stream === acquired) {
                handleDecode(result.getText());
            }
        });

        // ZXing only resolves once the video fires 'playing'. If the browser
        // refuses to play, the preview would be hidden behind the loading
        // overlay forever, so never block the UI on that promise.
        await Promise.race([
            decodeSession,
            new Promise((resolve) => setTimeout(resolve, DECODER_START_TIMEOUT)),
        ]);

        watchStream(acquired);

        isScanning.value = true;
        setStatus('scanning');
    } catch (error) {
        fail(toErrorKind(error));
        releaseCamera();
        emit('error', error);
    }
}

/**
 * Releases every camera resource without touching the status, so a real error
 * message is not immediately overwritten by "Camera stopped.".
 */
function releaseCamera() {
    if (codeReader) {
        try {
            codeReader.reset();
        } catch {
            // ignore
        }
        codeReader = null;
    }

    if (stream) {
        stream.getTracks().forEach((track) => track.stop());
        stream = null;
    }

    if (videoRef.value) {
        videoRef.value.srcObject = null;
    }

    isScanning.value = false;
}

function stop() {
    releaseCamera();
    setStatus('stop');
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

/**
 * If camera permission is already blocked, say so up front instead of letting a
 * failing getUserMedia produce a confusing message.
 */
async function preflightPermission() {
    if (!navigator.permissions?.query) return;

    try {
        const permission = await navigator.permissions.query({ name: 'camera' });

        if (permission.state === 'denied') {
            fail('blocked');
            return;
        }

        permission.onchange = () => {
            if (permission.state !== 'denied') return;

            releaseCamera();
            fail('blocked');
        };
    } catch {
        // Safari's Permissions API does not know the 'camera' descriptor.
    }
}

onMounted(async () => {
    isSecureContext.value = window.isSecureContext !== false;

    if (!cameraSupported.value) {
        fail(isSecureContext.value ? 'unsupported' : 'insecure');
        return;
    }

    setStatus('idle');
    await preflightPermission();
});

onBeforeUnmount(() => {
    releaseCamera();
});
</script>
