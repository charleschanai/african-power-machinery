<template>
    <div class="min-h-screen bg-white lg:grid lg:grid-cols-2">
        <div
            class="relative hidden overflow-hidden bg-gradient-to-br from-emerald-950 via-green-950 to-emerald-900 lg:flex lg:flex-col lg:justify-between lg:p-12"
        >
            <div class="pointer-events-none absolute inset-0">
                <div class="absolute -left-24 -top-24 h-80 w-80 rounded-full bg-emerald-500/20 blur-3xl"></div>
                <div class="absolute -bottom-32 -right-20 h-96 w-96 rounded-full bg-teal-500/20 blur-3xl"></div>
                <div
                    class="absolute inset-0 opacity-[0.04]"
                    style="background-image: radial-gradient(circle at 1px 1px, white 1px, transparent 0); background-size: 28px 28px;"
                ></div>
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
                <div class="mb-10 flex justify-center">
                    <div class="relative h-72 w-60 sm:h-80 sm:w-72">
                        <div
                            class="absolute inset-0 translate-x-4 translate-y-3 rotate-6 overflow-hidden rounded-[20px] bg-gradient-to-br from-amber-100 via-white to-emerald-50 shadow-2xl shadow-black/40 ring-1 ring-amber-200/40"
                        >
                            <div class="flex h-full flex-col items-center justify-center gap-2 p-6">
                                <Flower2 class="h-6 w-6 text-amber-400/80" />
                                <p class="text-xs font-semibold uppercase tracking-[0.3em] text-amber-700/70">eCard</p>
                                <div class="mt-2 h-px w-16 bg-amber-300/60"></div>
                                <p class="text-center font-serif text-sm italic text-emerald-800/70">
                                    An invitation to
                                    <br />
                                    remember
                                </p>
                            </div>
                        </div>
                        <div
                            class="absolute inset-0 -translate-x-2 translate-y-1 -rotate-3 overflow-hidden rounded-[20px] bg-white shadow-2xl shadow-black/40 ring-1 ring-amber-200/40"
                        >
                            <img :src="theWedding" alt="Wedding invitation" class="h-full w-full object-cover" />
                            <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 via-black/30 to-transparent px-5 pb-5 pt-14">
                                <div class="flex items-center justify-center gap-2">
                                    <Flower2 class="h-4 w-4 text-amber-300" />
                                    <p class="text-center font-serif text-base font-semibold text-white sm:text-lg">
                                        Save the Wedding
                                    </p>
                                    <Flower2 class="h-4 w-4 scale-x-[-1] text-amber-300" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <h1 class="text-center text-3xl font-extrabold tracking-tight text-white sm:text-4xl">
                    Welcome to Digital eCards!
                </h1>
                <p class="mt-4 text-center text-sm leading-relaxed text-emerald-100/80">
                    Secure digital cards for guest entry. Issue eCards, scan QR codes at the gate and manage every
                    entry from one place.
                </p>

                <ul class="mt-10 space-y-4">
                    <li class="flex items-center gap-3 text-sm font-medium text-emerald-50">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white/10 ring-1 ring-white/20">
                            <ScanLine class="h-4.5 w-4.5 text-emerald-300" stroke-width="2" />
                        </span>
                        QR code entry scanning at the gate
                    </li>
                    <li class="flex items-center gap-3 text-sm font-medium text-emerald-50">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white/10 ring-1 ring-white/20">
                            <ShieldCheck class="h-4.5 w-4.5 text-emerald-300" stroke-width="2" />
                        </span>
                        Secure access with full audit trail
                    </li>
                    <li class="flex items-center gap-3 text-sm font-medium text-emerald-50">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white/10 ring-1 ring-white/20">
                            <Send class="h-4.5 w-4.5 text-emerald-300" stroke-width="2" />
                        </span>
                        Share eCards instantly via WhatsApp
                    </li>
                </ul>
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
import { CreditCard, Flower2, ScanLine, Send, ShieldCheck } from '@lucide/vue';
import theWedding from '../../images/the-wedding.jpg';

const appVersion = '1.0.0';
const currentYear = new Date().getFullYear();
</script>
