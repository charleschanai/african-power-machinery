<template>
    <div class="min-h-screen bg-white lg:grid lg:grid-cols-2">
        <div
            class="relative hidden overflow-hidden bg-gradient-to-br from-emerald-950 via-green-950 to-emerald-900 lg:flex lg:flex-col lg:justify-between lg:p-12"
        >
            <div class="pointer-events-none absolute inset-0">
                <img
                    :src="welcomeBg"
                    alt=""
                    class="absolute inset-0 h-full w-full scale-105 object-cover"
                />
                <div
                    class="absolute inset-0 bg-gradient-to-br from-emerald-950/95 via-emerald-900/75 to-green-950/90 mix-blend-multiply"
                ></div>
                <div class="absolute inset-0 bg-gradient-to-t from-emerald-950/85 via-transparent to-emerald-950/40"></div>
            </div>

            <div class="relative flex items-center gap-3">
                <div
                    class="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-500 to-teal-700 shadow-lg shadow-black/30 ring-1 ring-white/20"
                >
                    <CreditCard class="h-6 w-6 text-white" stroke-width="2" />
                </div>
                <div>
                    <p class="text-base font-bold tracking-tight text-white">eCard System</p>
                    <p class="text-xs font-medium text-emerald-300/80">Digital Guest Entry Management</p>
                </div>
            </div>

            <div class="relative max-w-md">
                <h1 class="text-center text-3xl font-extrabold tracking-tight text-white drop-shadow sm:text-4xl">
                    Welcome to Digital eCards!
                </h1>
                <p class="mt-4 text-center text-sm leading-relaxed text-emerald-100/90">
                    Issue eCards, scan QR codes at the gate and manage every entry from one place.
                </p>
            </div>

            <p class="relative text-xs text-emerald-200/50">&copy; {{ currentYear }} eCard System. All rights reserved.</p>
        </div>

        <div class="flex min-h-screen flex-col justify-center px-5 py-10 sm:px-10 lg:py-0">
            <div class="mb-8 flex items-center gap-3 lg:hidden">
                <div
                    class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-500 to-teal-700 shadow-lg shadow-emerald-600/25"
                >
                    <CreditCard class="h-5 w-5 text-white" stroke-width="2" />
                </div>
                <div>
                    <p class="text-sm font-bold tracking-tight text-slate-900">eCard System</p>
                    <p class="text-xs text-slate-500">Digital Guest Entry Management</p>
                </div>
            </div>

            <div class="mx-auto w-full max-w-md">
                <slot />
            </div>

            <p class="mt-8 text-center text-xs text-slate-400">
                eCard System &middot; v{{ appVersion }}
            </p>
        </div>
    </div>
</template>

<script setup>
import { CreditCard } from '@lucide/vue';
import welcomeBg from '../../images/welcome-bg.jpg';

const appVersion = '1.0.0';
const currentYear = new Date().getFullYear();
</script>
