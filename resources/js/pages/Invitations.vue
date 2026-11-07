<template>
    <div class="space-y-6">
        <!-- Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Invitations</h1>
                <p class="page-subtitle">
                    Save each guest once, then send by SMS, remind, or share the card on WhatsApp.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <p v-if="lastLoadedAt" class="text-xs text-slate-400">
                    Updated <span class="font-medium text-slate-500">{{ lastLoadedAt }}</span>
                </p>
                <button
                    type="button"
                    class="btn btn-secondary text-sm"
                    :disabled="refreshing"
                    title="Reload invitations"
                    @click="load()"
                >
                    <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': refreshing }" />
                    Refresh
                </button>
                <button type="button" class="btn btn-primary text-sm" @click="openCreate">
                    <Plus class="h-4 w-4" />
                    Create Invitation
                </button>
            </div>
        </div>

        <!-- SMS not configured -->
        <div
            v-if="smsConfigured === false"
            class="flex items-start gap-3 rounded-xl bg-amber-50 px-4 py-3 ring-1 ring-inset ring-amber-600/20"
        >
            <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
            <div class="text-sm text-amber-900">
                <p class="font-semibold">SMS is not configured.</p>
                <p class="mt-0.5 text-amber-700">
                    Set <code>NEXTSMS_USERNAME</code> and <code>NEXTSMS_PASSWORD</code> in your
                    <code>.env</code>, then run <code>php artisan config:clear</code>. You can still save
                    invitations and share cards on WhatsApp.
                </p>
            </div>
        </div>

        <!--
            Shared links point somewhere a guest cannot use as it stands. Two
            different faults look identical from the table, so they are told
            apart here: a dead address sends every guest to an error, while a
            tunnel warning page sends them to a page that is not their card.
        -->
        <div
            v-if="linkFault"
            class="flex items-start gap-3 rounded-xl px-4 py-3 ring-1 ring-inset"
            :class="linkFault.card"
        >
            <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0" :class="linkFault.icon" />
            <div class="text-sm" :class="linkFault.heading">
                <p class="font-semibold">{{ linkFault.title }}</p>
                <p class="mt-0.5" :class="linkFault.body">
                    Links are being built from
                    <code class="font-semibold">{{ summary.public_url }}</code>. {{ linkFault.reason }}
                </p>
                <p class="mt-0.5" :class="linkFault.body">{{ linkFault.advice }}</p>
            </div>
        </div>

        <!-- Summary -->
        <div v-if="summary" class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <StatCard
                label="Total Invitations"
                :value="summary.total"
                :hint="`${summary.not_sent} not sent yet`"
                :icon="Send"
                tone="indigo"
            />
            <StatCard
                label="Invitations Sent"
                :value="summary.sent"
                :hint="summary.failed
                    ? `${summary.failed} failed to send`
                    : `${summary.not_sent} waiting`"
                :icon="CheckCircle"
                :tone="summary.failed ? 'rose' : 'emerald'"
            />
            <StatCard
                label="Reminders Sent"
                :value="summary.reminder_sent"
                :hint="`${summary.reminder_not_sent} still need one`"
                :icon="BellRing"
                tone="sky"
            />
            <StatCard
                label="Need Reminder"
                :value="summary.reminder_not_sent"
                hint="Sent but not reminded"
                :icon="AlertTriangle"
                tone="amber"
            />
        </div>

        <div v-if="summary?.card_counts" class="grid gap-4 sm:grid-cols-2">
            <button v-for="type in ['single', 'double']" :key="type" type="button" class="rounded-2xl border bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md sm:p-5" :class="cardTypeFilter === type ? 'border-emerald-500 ring-2 ring-emerald-500/20' : 'border-slate-200'" @click="setCardTypeFilter(cardTypeFilter === type ? '' : type)">
                <div class="flex items-start justify-between gap-3">
                    <div><p class="text-xs font-bold uppercase tracking-wider text-slate-400">{{ type }} card invitations</p><p class="mt-1 text-3xl font-extrabold text-slate-900">{{ summary.card_counts[type]?.total ?? 0 }}</p><p class="mt-2 text-xs text-slate-500">{{ summary.card_counts[type]?.sent ?? 0 }} sent <span class="px-1">·</span> {{ summary.card_counts[type]?.not_sent ?? 0 }} not sent</p></div>
                    <span class="rounded-xl p-2.5" :class="type === 'single' ? 'bg-sky-50 text-sky-700' : 'bg-violet-50 text-violet-700'"><CreditCard class="h-5 w-5" /></span>
                </div>
                <p class="mt-3 text-xs font-semibold" :class="cardTypeFilter === type ? 'text-emerald-700' : 'text-slate-400'">{{ cardTypeFilter === type ? 'Showing this card type' : `Show ${type} card invitations` }}</p>
            </button>
        </div>

        <!-- Filters -->
        <div class="card">
            <div class="flex flex-col gap-3 px-5 py-3 sm:flex-row sm:items-center">
                <div class="relative flex-1 sm:max-w-xs">
                    <Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search guest, phone, event or venue..."
                        class="input pl-9!"
                        @input="debouncedSearch"
                    />
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="btn px-3! py-1.5! text-xs" :class="cardTypeFilter === '' ? 'btn-primary' : 'btn-secondary'" @click="setCardTypeFilter('')">All Cards</button>
                    <button type="button" class="btn px-3! py-1.5! text-xs" :class="cardTypeFilter === 'single' ? 'btn-primary' : 'btn-secondary'" @click="setCardTypeFilter('single')">Single Card</button>
                    <button type="button" class="btn px-3! py-1.5! text-xs" :class="cardTypeFilter === 'double' ? 'btn-primary' : 'btn-secondary'" @click="setCardTypeFilter('double')">Double Card</button>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="option in filters"
                        :key="option.value"
                        type="button"
                        class="btn px-3! py-1.5! text-xs"
                        :class="filter === option.value ? 'btn-primary' : 'btn-secondary'"
                        @click="setFilter(option.value)"
                    >
                        {{ option.label }}
                        <span
                            v-if="option.count !== null"
                            class="ml-1 rounded-full px-1.5 py-0.5 text-[10px] font-bold"
                            :class="filter === option.value ? 'bg-white/25' : 'bg-slate-200'"
                        >
                            {{ option.count }}
                        </span>
                    </button>
                </div>
            </div>

            <!-- Bulk bar -->
            <div
                v-if="selected.length"
                class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 bg-emerald-50/60 px-5 py-3"
            >
                <p class="text-sm font-semibold text-emerald-800">
                    {{ selected.length }} invitation{{ selected.length === 1 ? '' : 's' }} selected
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" class="btn btn-secondary px-3! py-1.5! text-xs" @click="clearSelection">
                        Clear
                    </button>
                    <button
                        type="button"
                        class="btn btn-primary px-3! py-1.5! text-xs"
                        :disabled="bulkSending || !smsConfigured"
                        :title="!smsConfigured ? 'SMS is not configured' : 'Send SMS to selected'"
                        @click="confirmBulk = true"
                    >
                        <span v-if="bulkSending" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                        <Smartphone v-else class="h-3.5 w-3.5" />
                        Send SMS to Selected
                    </button>
                </div>
            </div>

            <div v-if="loading" class="flex justify-center py-16">
                <span class="h-8 w-8 animate-spin rounded-full border-4 border-emerald-500/30 border-t-emerald-600"></span>
            </div>

            <div v-else-if="rows.length" class="overflow-x-auto">
                <table class="data-table w-full min-w-[1100px] text-left">
                    <thead>
                        <tr class="border-b border-slate-200">
                            <th class="table-header w-10">
                                <input
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                                    :checked="allOnPageSelected"
                                    :indeterminate="someOnPageSelected"
                                    title="Select all on this page"
                                    @change="toggleSelectAll"
                                />
                            </th>
                            <th class="table-header">Guest</th>
                            <th class="table-header">Phone</th>
                            <th class="table-header">Event</th>
                            <th class="table-header">Card</th>
                            <th class="table-header">Status</th>
                            <th class="table-header">Last Sent</th>
                            <th class="table-header">Reminder</th>
                            <th class="table-header text-right!">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="row in rows" :key="row.id" class="hover:bg-slate-50/60">
                            <td class="table-cell">
                                <input
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                                    :checked="selected.includes(row.id)"
                                    @change="toggleSelect(row.id)"
                                />
                            </td>
                            <td class="table-cell">
                                <p class="text-sm font-semibold text-slate-900">{{ row.guest_name || '—' }}</p>
                            </td>
                            <td class="table-cell">
                                <p
                                    class="font-mono text-sm"
                                    :class="row.can_send ? 'text-slate-600' : 'font-bold text-rose-600'"
                                >
                                    {{ row.phone_display || row.phone || '—' }}
                                </p>
                                <p
                                    v-if="!row.can_send"
                                    class="mt-1 max-w-56 text-xs leading-snug text-rose-600"
                                    :title="row.phone_problem"
                                >
                                    {{ row.phone_problem }}
                                </p>
                            </td>
                            <td class="table-cell">
                                <p v-if="row.event_name" class="text-sm text-slate-700">{{ row.event_name }}</p>
                                <p v-if="row.event_date" class="text-xs text-slate-400">{{ formatDate(row.event_date) }}</p>
                                <p v-if="!row.event_name && !row.event_date" class="text-slate-300">—</p>
                            </td>
                            <td class="table-cell">
                                <div v-if="row.card_image" class="flex items-center gap-2">
                                    <img
                                        :src="row.card_image"
                                        :alt="row.card_number || 'card design'"
                                        class="h-9 w-12 rounded object-cover ring-1 ring-slate-200"
                                    />
                                    <span class="font-mono text-xs font-bold text-slate-700">{{ row.card_number }}</span>
                                    <span v-if="row.card_type" class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide" :class="row.card_type === 'single' ? 'bg-sky-50 text-sky-700' : 'bg-violet-50 text-violet-700'">{{ row.card_type }}</span>
                                </div>
                                <span v-else class="text-slate-300">—</span>
                            </td>
                            <td class="table-cell">
                                <span class="badge" :class="statusBadge(row.status)">{{ row.status_label }}</span>
                                <p v-if="row.last_error" class="mt-1 max-w-40 truncate text-xs text-rose-600" :title="row.last_error">
                                    {{ row.last_error }}
                                </p>
                            </td>
                            <td class="table-cell text-sm text-slate-500">{{ formatTime(row.last_sent_at) }}</td>
                            <td class="table-cell">
                                <span v-if="row.last_reminder_sent_at" class="badge badge-success">
                                    Sent {{ formatDate(row.last_reminder_sent_at) }}
                                </span>
                                <span v-else-if="row.last_sms_sent_at" class="badge badge-warning">Not sent</span>
                                <span v-else class="text-slate-300">—</span>
                            </td>
                            <td class="table-cell">
                                <div class="flex flex-wrap items-center justify-end gap-1.5">
                                    <button
                                        type="button"
                                        class="btn btn-secondary px-2.5! py-1.5! text-xs"
                                        title="View details"
                                        @click="openView(row)"
                                    >
                                        <Eye class="h-3.5 w-3.5" />
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-secondary px-2.5! py-1.5! text-xs"
                                        title="Edit invitation"
                                        @click="openEdit(row)"
                                    >
                                        <Pencil class="h-3.5 w-3.5" />
                                    </button>
                                    <button
                                        type="button"
                                        class="btn px-2.5! py-1.5! text-xs"
                                        :class="row.last_sms_sent_at ? 'btn-secondary' : 'btn-primary'"
                                        :disabled="sendingId === row.id || !smsConfigured"
                                        :title="smsHint(row)"
                                        @click="requestSend(row)"
                                    >
                                        <span v-if="sendingId === row.id" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-slate-400/40 border-t-slate-600"></span>
                                        <Smartphone v-else class="h-3.5 w-3.5" />
                                        SMS
                                    </button>
                                    <button
                                        type="button"
                                        class="btn px-2.5! py-1.5! text-xs"
                                        :class="row.last_sms_sent_at ? 'btn-primary' : 'btn-secondary'"
                                        :disabled="!row.last_sms_sent_at"
                                        :title="smsHint(row, 'Send a reminder')"
                                        @click="openReminder(row)"
                                    >
                                        <BellRing class="h-3.5 w-3.5" />
                                        Remind
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-secondary px-2.5! py-1.5! text-xs"
                                        title="Share the card on WhatsApp"
                                        @mouseenter="prewarmCard(row)"
                                        @focus="prewarmCard(row)"
                                        @click="shareWhatsApp(row)"
                                    >
                                        <MessageCircle class="h-3.5 w-3.5" />
                                        WhatsApp
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-danger px-2.5! py-1.5! text-xs"
                                        title="Delete invitation"
                                        @click="askDelete(row)"
                                    >
                                        <Trash2 class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="flex flex-col items-center justify-center gap-3 py-20 text-center">
                <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400">
                    <Send class="h-7 w-7" stroke-width="1.5" />
                </span>
                <div v-if="hasActiveFilter">
                    <p class="font-semibold text-slate-700">No invitations match this view</p>
                    <p class="mt-0.5 text-sm text-slate-400">
                        {{ summary?.total ?? 0 }} invitation(s) are saved. Clear the search or the filter to see them.
                    </p>
                </div>
                <div v-else>
                    <p class="font-semibold text-slate-700">No invitations saved yet</p>
                    <p class="mt-0.5 text-sm text-slate-400">
                        Save a guest once, then reuse their details for SMS, reminders and WhatsApp.
                    </p>
                </div>
                <button v-if="!hasActiveFilter" type="button" class="btn btn-primary mt-2" @click="openCreate">
                    <Plus class="h-4 w-4" />
                    Create Invitation
                </button>
                <button v-else type="button" class="btn btn-secondary mt-2" @click="clearFilter">
                    <X class="h-4 w-4" />
                    Clear search and filter
                </button>
            </div>

            <div v-if="pagination && pagination.last_page > 1" class="px-5 pb-4">
                <Pagination :pagination="pagination" @change="changePage" />
            </div>
        </div>

        <!-- Create / Edit modal -->
        <Teleport to="body">
            <div v-if="showForm" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/60 p-4 backdrop-blur-sm">
                <div class="w-full max-w-3xl animate-scale-in rounded-2xl bg-white p-6 shadow-2xl">
                    <div class="flex items-center justify-between">
                        <h2 class="card-title">{{ editing ? 'Edit Invitation' : 'Create Invitation' }}</h2>
                        <button type="button" class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100" @click="closeForm">
                            <X class="h-5 w-5" />
                        </button>
                    </div>

                    <form class="mt-5 space-y-5" @submit.prevent="submit">
                        <div>
                            <label class="label">Card Type *</label>
                            <div class="grid gap-3 sm:grid-cols-3">
                                <button type="button" class="rounded-xl border p-3 text-left transition" :class="form.card_type === 'single' ? 'border-emerald-500 bg-emerald-50 ring-2 ring-emerald-500/20' : 'border-slate-200 hover:border-slate-300'" @click="setInvitationCardType('single')"><span class="block text-sm font-bold text-slate-900">Single Card</span><span class="mt-1 block text-xs text-slate-500">Guests with a Single card</span></button>
                                <button type="button" class="rounded-xl border p-3 text-left transition" :class="form.card_type === 'double' ? 'border-emerald-500 bg-emerald-50 ring-2 ring-emerald-500/20' : 'border-slate-200 hover:border-slate-300'" @click="setInvitationCardType('double')"><span class="block text-sm font-bold text-slate-900">Double Card</span><span class="mt-1 block text-xs text-slate-500">Guests with a Double card</span></button>
                                <button type="button" class="rounded-xl border p-3 text-left transition" :class="form.card_type === 'none' ? 'border-emerald-500 bg-emerald-50 ring-2 ring-emerald-500/20' : 'border-slate-200 hover:border-slate-300'" @click="setInvitationCardType('none')"><span class="block text-sm font-bold text-slate-900">No Card</span><span class="mt-1 block text-xs text-slate-500">Invitation without a card link</span></button>
                            </div>
                        </div>
                        <!-- Guest -->
                        <div>
                            <label for="inv-guest" class="label">Guest *</label>
                            <select id="inv-guest" v-model="form.guest_id" class="input" @change="onGuestChange">
                                <option value="">Select a {{ form.card_type === 'none' ? '' : form.card_type + ' card' }} guest...</option>
                                <optgroup v-for="group in guestGroups" :key="group.letter" :label="group.letter">
                                <option v-for="g in group.guests" :key="g.id" :value="g.id">
                                    {{ g.full_name }} — {{ g.phone_display || g.phone || 'no phone' }}
                                </option>
                                </optgroup>
                            </select>
                            <p v-if="formOptions && !guestGroups.length" class="mt-1 text-xs text-slate-400">
                                No guests with {{ form.card_type }} cards found. Add a matching card first.
                            </p>
                        </div>

                        <!-- Phone -->
                        <div>
                            <label for="inv-phone" class="label">Phone Number *</label>
                            <input
                                id="inv-phone"
                                v-model="form.phone"
                                type="tel"
                                class="input"
                                :class="{ '!ring-rose-400': form.phone && !phoneOk }"
                                placeholder="0712345678"
                            />
                            <p v-if="form.phone && !phoneOk" class="mt-1 text-xs font-medium text-rose-600">
                                {{ phoneWarning }}
                            </p>
                            <p v-else-if="form.phone" class="mt-1 text-xs text-slate-500">
                                Will be sent to
                                <span class="font-semibold text-slate-700">{{ phoneAsSent }}</span>
                            </p>
                            <p v-else class="mt-1 text-xs text-slate-400">
                                Saved once and reused for every future send. Local form 0712345678 is converted automatically.
                            </p>
                        </div>

                        <!-- Card -->
                        <div>
                            <label for="inv-card" class="label">{{ form.card_type === 'none' ? 'Card' : `${form.card_type === 'single' ? 'Single' : 'Double'} Card` }}</label>
                            <select id="inv-card" v-model="form.card_id" class="input" @change="onCardChange">
                                <option value="">{{ form.card_type === 'none' ? 'No card' : `Select a ${form.card_type} card...` }}</option>
                                <option v-for="c in selectedGuestCards" :key="c.id" :value="c.id">
                                    {{ c.card_number }}{{ c.template_name ? ` — ${c.template_name}` : '' }}
                                </option>
                            </select>
                            <p class="mt-1 text-xs text-slate-400">
                                Pick an already-uploaded design. There is no need to upload it again.
                            </p>
                        </div>

                        <!-- Event -->
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <label for="inv-event" class="label">Event Name</label>
                                <input id="inv-event" v-model="form.event_name" type="text" class="input" placeholder="Wedding" />
                            </div>
                            <div>
                                <label for="inv-date" class="label">Event Date</label>
                                <input id="inv-date" v-model="form.event_date" type="date" class="input" />
                            </div>
                            <div>
                                <label for="inv-time" class="label">Event Time</label>
                                <input
                                    id="inv-time"
                                    v-model="form.event_time"
                                    type="time"
                                    class="input"
                                />
                            </div>
                            <div>
                                <label for="inv-venue" class="label">Venue</label>
                                <input id="inv-venue" v-model="form.venue" type="text" class="input" placeholder="Keni-Alen, Chini Rombo" />
                            </div>
                        </div>

                        <!-- Everything printed on the card itself -->
                        <div>
                            <label for="inv-details" class="label">Wedding Details (everything on the card)</label>
                            <textarea
                                id="inv-details"
                                v-model="form.wedding_details"
                                rows="6"
                                class="input"
                                placeholder="Type exactly what is written on the card.&#10;&#10;Example:&#10;John Doe &amp; Mary Smith&#10;Request the honour of your presence&#10;Saturday, 10 October 2026&#10;4:00 PM at Garden Paradise&#10;RSVP: 0755 123 456"
                            ></textarea>
                            <p class="mt-1 text-xs text-slate-400">
                                Write it once. It is saved with this invitation and sent
                                automatically with the SMS, the reminder and the WhatsApp card.
                                Keep it short: every line here is an SMS line the guest pays to read.
                            </p>
                        </div>

                        <!-- Invitation message -->
                        <div>
                            <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
                                <label for="inv-message" class="label mb-0!">Invitation Message *</label>
                                <button type="button" class="text-xs font-semibold text-emerald-700 hover:underline" @click="form.message = currentCardDefaults?.message ?? formOptions?.default_message ?? ''">
                                    Use default
                                </button>
                            </div>
                            <textarea id="inv-message" v-model="form.message" rows="7" class="input"></textarea>
                            <p
                                v-if="detailsNotInMessage"
                                class="mt-2 rounded-lg border border-amber-300 bg-amber-50 p-2.5 text-xs font-medium text-amber-800"
                            >
                                Your Wedding Details are saved, but this message does not include
                                them, so the guest would not receive them. Press
                                <strong>Use default</strong> to add them.
                            </p>
                            <div
                                v-if="previewMessage"
                                class="mt-3 rounded-xl border-2 border-emerald-200 bg-emerald-50 p-3"
                            >
                                <p class="mb-1 text-xs font-bold text-emerald-800">Preview for {{ form.guest_id ? selectedGuestName : 'this guest' }}</p>
                                <pre class="whitespace-pre-wrap font-sans text-xs leading-relaxed text-slate-700">{{ previewMessage }}</pre>
                            </div>
                        </div>

                        <!-- Reminder message -->
                        <div>
                            <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
                                <label for="inv-reminder" class="label mb-0!">Reminder Message</label>
                                <button type="button" class="text-xs font-semibold text-emerald-700 hover:underline" @click="form.reminder_message = currentCardDefaults?.reminder_message ?? formOptions?.default_reminder ?? ''">
                                    Use default
                                </button>
                            </div>
                            <textarea id="inv-reminder" v-model="form.reminder_message" rows="5" class="input" placeholder="Optional. A ready-made reminder is used if you leave this empty."></textarea>
                            <div
                                v-if="formReminderPreview"
                                class="mt-3 rounded-xl border-2 border-amber-200 bg-amber-50 p-3"
                            >
                                <p class="mb-1 text-xs font-bold text-amber-800">Reminder preview for {{ form.guest_id ? selectedGuestName : 'this guest' }}</p>
                                <pre class="whitespace-pre-wrap font-sans text-xs leading-relaxed text-slate-700">{{ formReminderPreview }}</pre>
                            </div>
                        </div>

                        <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                            <button type="button" class="btn btn-secondary" @click="closeForm">Cancel</button>
                            <button type="submit" class="btn btn-primary" :disabled="saving">
                                <span v-if="saving" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                                {{ editing ? 'Save Changes' : 'Save Invitation' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <!-- View modal -->
        <Teleport to="body">
            <div v-if="viewing" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-950/60 p-4 backdrop-blur-sm" @click.self="viewing = null">
                <div class="w-full max-w-2xl animate-scale-in rounded-2xl bg-white p-6 shadow-2xl">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="card-title">{{ viewing.guest_name }}</h2>
                            <p class="mt-0.5 text-sm text-slate-500">{{ viewing.phone_display || viewing.phone }}</p>
                        </div>
                        <button type="button" class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100" @click="viewing = null">
                            <X class="h-5 w-5" />
                        </button>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div v-if="viewing.card_image" class="sm:col-span-2">
                            <p class="text-xs font-semibold text-slate-400">Card Design</p>
                            <img :src="viewing.card_image" alt="card design" class="mt-1 max-h-56 rounded-lg object-contain ring-1 ring-slate-200" />
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-slate-400">Event</p>
                            <p class="text-sm text-slate-700">{{ viewing.event_name || '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-slate-400">Date, Time &amp; Venue</p>
                            <p class="text-sm text-slate-700">
                                {{ formatDate(viewing.event_date) }}
                                <span v-if="viewing.event_time">· {{ viewing.event_time }}</span>
                                <span v-if="viewing.venue">· {{ viewing.venue }}</span>
                            </p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-slate-400">Status</p>
                            <span class="badge" :class="statusBadge(viewing.status)">{{ viewing.status_label }}</span>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-slate-400">Last Sent</p>
                            <p class="text-sm text-slate-700">{{ formatTime(viewing.last_sent_at) }}</p>
                        </div>
                        <div v-if="viewing.wedding_details" class="sm:col-span-2">
                            <p class="text-xs font-semibold text-slate-400">Wedding Details (what the guest is told)</p>
                            <pre class="mt-1 whitespace-pre-wrap rounded-lg bg-emerald-50 p-3 text-xs leading-relaxed text-slate-700">{{ viewing.wedding_details }}</pre>
                        </div>
                        <div class="sm:col-span-2">
                            <p class="text-xs font-semibold text-slate-400">Invitation Message</p>
                            <pre class="mt-1 whitespace-pre-wrap rounded-lg bg-slate-50 p-3 text-xs leading-relaxed text-slate-700">{{ viewing.rendered_message || viewing.message }}</pre>
                        </div>
                        <div v-if="viewing.whatsapp_text" class="sm:col-span-2">
                            <p class="text-xs font-semibold text-slate-400">Text sent with the WhatsApp card</p>
                            <pre class="mt-1 whitespace-pre-wrap rounded-lg bg-sky-50 p-3 text-xs leading-relaxed text-slate-700">{{ viewing.whatsapp_text }}</pre>
                        </div>
                        <div v-if="viewing.rendered_reminder" class="sm:col-span-2">
                            <p class="text-xs font-semibold text-slate-400">Reminder Message</p>
                            <pre class="mt-1 whitespace-pre-wrap rounded-lg bg-amber-50 p-3 text-xs leading-relaxed text-slate-700">{{ viewing.rendered_reminder }}</pre>
                        </div>
                    </div>

                    <div class="mt-5 flex flex-wrap justify-end gap-2">
                        <button type="button" class="btn btn-secondary" @click="openEdit(viewing); viewing = null">Edit</button>
                        <button type="button" class="btn btn-primary" :disabled="sendingId === viewing.id" @click="requestSend(viewing); viewing = null">
                            <Smartphone class="h-4 w-4" />
                            Send SMS
                        </button>
                        <button type="button" class="btn btn-primary" @click="shareWhatsApp(viewing); viewing = null">
                            <MessageCircle class="h-4 w-4" />
                            WhatsApp
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Reminder modal -->
        <Teleport to="body">
            <div v-if="reminderFor" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-950/60 p-4 backdrop-blur-sm" @click.self="reminderFor = null">
                <div class="w-full max-w-2xl animate-scale-in rounded-2xl bg-white p-6 shadow-2xl">
                    <div class="flex items-center justify-between">
                        <h2 class="card-title">Send Reminder</h2>
                        <button type="button" class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100" @click="reminderFor = null">
                            <X class="h-5 w-5" />
                        </button>
                    </div>
                    <p class="mt-1 text-sm text-slate-500">
                        To <strong>{{ reminderFor.guest_name }}</strong> at
                        <span class="font-mono">{{ reminderFor.phone_display || reminderFor.phone }}</span>
                    </p>

                    <div class="mt-4">
                        <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
                            <label for="rem-message" class="label mb-0!">Reminder Message</label>
                            <button type="button" class="text-xs font-semibold text-emerald-700 hover:underline" @click="reminderDraft = defaultReminderFor(reminderFor)">
                                Use default
                            </button>
                        </div>
                        <textarea id="rem-message" v-model="reminderDraft" rows="8" class="input font-mono text-xs"></textarea>
                        <p class="mt-1 text-xs text-slate-400">
                            You can edit this before sending. Saving it will keep your edit for next time.
                        </p>
                    </div>

                    <div v-if="reminderPreview" class="mt-3 rounded-xl border-2 border-amber-200 bg-amber-50 p-3">
                        <p class="mb-1 text-xs font-bold text-amber-800">Preview</p>
                        <pre class="whitespace-pre-wrap font-sans text-xs leading-relaxed text-slate-700">{{ reminderPreview }}</pre>
                    </div>

                    <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <button type="button" class="btn btn-secondary" @click="reminderFor = null">Cancel</button>
                        <button type="button" class="btn btn-primary" :disabled="reminding || !smsConfigured" @click="doSendReminder">
                            <span v-if="reminding" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                            <BellRing v-else class="h-4 w-4" />
                            Send Reminder
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <ConfirmDialog
            :open="Boolean(sendWarningFor)"
            :title="sendWarningFor?.can_send === false ? 'Send to this number anyway?' : 'Send SMS'"
            :message="sendWarningFor
                ? `The number for ${sendWarningFor.guest_name} is ${sendWarningFor.phone_display || sendWarningFor.phone}. ${sendWarningFor.phone_problem} The SMS gateway reports success for numbers it cannot reach, so the message may never arrive and you will still be charged. Send it anyway?`
                : ''"
            confirm-label="Send Anyway"
            cancel-label="Cancel"
            :loading="sendingId === sendWarningFor?.id"
            @confirm="doSendSms(sendWarningFor)"
            @close="sendWarningFor = null"
        />

        <ConfirmDialog
            :open="Boolean(deleteTarget)"
            title="Delete Invitation?"
            :message="`This removes the saved invitation for ${deleteTarget?.guest_name ?? 'this guest'}. Their guest and card records stay untouched.`"
            confirm-label="Delete"
            :loading="deleting"
            @confirm="doDelete"
            @close="deleteTarget = null"
        />

        <ConfirmDialog
            :open="confirmBulk"
            title="Send SMS to Selected Invitations?"
            :message="`Each selected guest receives their own saved message on their own saved number via NextSMS. This covers ${selected.length} invitation(s).`"
            confirm-label="Send SMS Now"
            :loading="bulkSending"
            @confirm="doBulkSend"
            @close="confirmBulk = false"
        />
    </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import {
    AlertTriangle,
    BellRing,
    CheckCircle,
    Eye,
    MessageCircle,
    Pencil,
    Plus,
    RefreshCw,
    Search,
    Send,
    Smartphone,
    Trash2,
    X,
} from '@lucide/vue';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import Pagination from '../components/Pagination.vue';
import StatCard from '../components/StatCard.vue';
import { invitationsService } from '../services/invitations';
import { useToastStore } from '../stores/toast';

const toast = useToastStore();

const rows = ref([]);
const pagination = ref(null);
const summary = ref(null);
const formOptions = ref(null);
const smsConfigured = ref(true);
const loading = ref(true);
const refreshing = ref(false);
const lastLoadedAt = ref('');
// A ref, because load() reads it with .value to decide whether this is the very
// first paint. As a plain boolean that read always returned undefined, so
// isFirstLoad was true forever and the 30 second poll blanked the whole page
// with a spinner every half minute, which looked like the page reloading itself.
const hasLoadedOnce = ref(false);
const search = ref('');
const filter = ref('');
const cardTypeFilter = ref('');
let searchTimer = null;
let refreshTimer = null;

const selected = ref([]);
const bulkSending = ref(false);
const confirmBulk = ref(false);

const showForm = ref(false);
const editing = ref(null);
const saving = ref(false);
const form = ref(emptyForm());

const viewing = ref(null);
const sendingId = ref(null);

const reminderFor = ref(null);
const reminderDraft = ref('');
const reminding = ref(false);

const deleteTarget = ref(null);
const deleting = ref(false);
const sendWarningFor = ref(null);

function emptyForm() {
    return {
        card_type: 'single',
        guest_id: '',
        card_id: '',
        phone: '',
        event_name: '',
        event_date: '',
        event_time: '',
        venue: '',
        wedding_details: '',
        message: '',
        reminder_message: '',
    };
}

const filters = computed(() => [
    { value: '', label: 'All', count: summary.value?.total ?? null },
    { value: 'sent', label: 'Sent', count: summary.value?.sent ?? null },
    { value: 'not_sent', label: 'Not Sent', count: summary.value?.not_sent ?? null },
    { value: 'reminder_sent', label: 'Reminders Sent', count: summary.value?.reminder_sent ?? null },
    { value: 'reminder_not_sent', label: 'Reminders Not Sent', count: summary.value?.reminder_not_sent ?? null },
]);

// True when a search term or a filter chip is narrowing the table. The empty
// state says something different for each case, because telling an admin who
// has 12 saved invitations that they have "No invitations saved yet" is simply
// untrue and sends them off creating duplicates.
const hasActiveFilter = computed(() => search.value.trim() !== '' || filter.value !== '' || cardTypeFilter.value !== '');

/*
 * The link check's verdict, as something to show or null when the address is
 * healthy.
 *
 * Only one thing is worth putting in front of the admin: a dead address, which
 * means no guest can open the link, quietly wastes paid SMS and is exactly what
 * happened with the earlier tunnel. That is the one this page speaks up about.
 *
 * Everything else is deliberately silent. A check this machine could not finish
 * says nothing about what a guest's phone will meet, and a tunnel that answers
 * with a one tap notice still delivers the card, so neither is dressed up as a
 * problem the admin has to act on. The command line still reports both in full
 * for anyone who wants to look.
 */
const LINK_FAULTS = {
    broken: {
        card: 'bg-rose-50 ring-rose-600/20',
        icon: 'text-rose-600',
        heading: 'text-rose-900',
        body: 'text-rose-700',
        title: 'Guests will not be able to open the card link.',
        advice: 'Start the tunnel, or set ECARD_PUBLIC_URL in your .env to a public address, then run php artisan config:clear.',
    },
};

const linkFault = computed(() => {
    const s = summary.value;

    if (!s) {
        return null;
    }

    // Tolerates an API that only sends the older yes/no public_url_ok flag, so a
    // half-updated server reports the plain fault instead of hiding a problem.
    const status = s.public_url_status
        ?? (s.public_url_ok === false
            ? { level: 'broken', reason: 'The address these links are built from cannot be opened from a guest phone.' }
            : null);

    // Anything that is not a dead address is not shown. The map is the whole
    // list of what this page raises its voice about, so a verdict that has no
    // entry here is a verdict the admin does not need interrupting their work.
    if (!status || !LINK_FAULTS[status.level]) {
        return null;
    }

    return { ...LINK_FAULTS[status.level], level: status.level, reason: status.reason };
});

const pageIds = computed(() => rows.value.map((r) => r.id));
const allOnPageSelected = computed(() => pageIds.value.length > 0 && pageIds.value.every((id) => selected.value.includes(id)));
const someOnPageSelected = computed(() => pageIds.value.some((id) => selected.value.includes(id)) && !allOnPageSelected.value);

const selectedGuest = computed(() =>
    (formOptions.value?.guests ?? []).find((g) => Number(g.id) === Number(form.value.guest_id)) ?? null
);
const selectedGuestCards = computed(() => (selectedGuest.value?.cards ?? []).filter((card) => card.card_type === form.value.card_type));
const currentCardDefaults = computed(() => formOptions.value?.card_defaults?.[form.value.card_type === 'double' ? 'double' : 'single'] ?? null);
const guestGroups = computed(() => {
    const collator = new Intl.Collator('en', { sensitivity: 'base' });
    const eligible = (formOptions.value?.guests ?? [])
        .filter((guest) => form.value.card_type === 'none' || (guest.cards ?? []).some((card) => card.card_type === form.value.card_type))
        .sort((a, b) => collator.compare(a.full_name, b.full_name));
    const groups = new Map();
    eligible.forEach((guest) => {
        const initial = (guest.full_name?.trim()?.[0] ?? '#').toLocaleUpperCase('en');
        const letter = /^[A-Z]$/.test(initial) ? initial : '#';
        if (!groups.has(letter)) groups.set(letter, []);
        groups.get(letter).push(guest);
    });
    return [...groups.entries()]
        .sort(([a], [b]) => a === '#' ? 1 : b === '#' ? -1 : a.localeCompare(b))
        .map(([letter, guests]) => ({ letter, guests }));
});
const selectedGuestName = computed(() => selectedGuest.value?.full_name ?? 'Guest');

/** Client-side token fill so the preview matches what the server will send. */
function fillTokens(template) {
    if (!template) return '';
    const guest = selectedGuest.value;
    const card = selectedGuestCards.value.find((c) => Number(c.id) === Number(form.value.card_id));
    const map = {
        guest_name: guest?.full_name ?? '',
        first_name: (guest?.full_name ?? '').split(' ')[0] ?? '',
        event_name: form.value.event_name || card?.event_name || '',
        event_date: form.value.event_date
            ? new Date(form.value.event_date).toLocaleDateString('en-GB')
            : '',
        event_time: form.value.event_time || card?.event_time || '',
        venue: form.value.venue || card?.venue || '',
        card_number: card?.card_number ?? '',
        card_link: card?.e_card_url ?? '',
        phone: form.value.phone ?? '',
        // The whole block written on the card, kept on its own lines exactly
        // as the admin typed it. The server renders it the same way, so what
        // is shown here is what the guest reads.
        wedding_details: (form.value.wedding_details || '').trim(),
    };
    return template
        .replace(/\{([a-z_]+)\}/gi, (_, key) => map[key] ?? '')
        .replace(/[ \t]+$/gm, '')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

/**
 * Client-side mirror of PhoneHelper so the admin sees the problem while typing
 * instead of only after pressing Send. The server re-checks it regardless.
 *
 * The rule here has to accept exactly what the server accepts. It used to
 * accept only the bare 255XXXXXXXX form, which made the field refuse every
 * local number including the very one its own placeholder suggested, and it
 * called a number the server repairs perfectly well "too many digits". A
 * message the server can send has to pass here, or the admin is told a
 * perfectly good number is broken.
 */
function phoneDigits(phone) {
    return String(phone ?? '').replace(/\D+/g, '');
}

/** Mirrors PhoneHelper::toE164(). */
function phoneToE164(phone) {
    const d = phoneDigits(phone);
    if (d === '') return null;

    // 255 followed by a zero-prefixed local number, 2550XXXXXXXX: the country
    // code was glued onto an already local number, so drop the stray zero.
    if (d.length === 13 && d.startsWith('2550')) return '255' + d.slice(4);
    if (d.length === 12 && d.startsWith('255')) return d;
    if (d.length === 10 && d.startsWith('0')) return '255' + d.slice(1);
    if (d.length === 9 && d.startsWith('7')) return '255' + d;
    if (d.length === 13 && d.startsWith('255')) return d;

    return d;
}

/** Mirrors PhoneHelper::isDeliverable(). */
function phoneIsDeliverable(phone) {
    return /^255[67]\d{8}$/.test(phoneToE164(phone) ?? '');
}

/** Mirrors PhoneHelper::suggest(). */
function phoneSuggest(phone) {
    const e164 = phoneToE164(phone);
    if (e164 === null || phoneIsDeliverable(e164)) return null;

    const m = phoneDigits(phone).match(/^2550([67]\d{8})$/);
    return m ? '255' + m[1] : null;
}

const phoneOk = computed(() => phoneIsDeliverable(form.value.phone));

const phoneWarning = computed(() => {
    if (phoneDigits(form.value.phone) === '') return 'The phone number is required.';

    const suggestion = phoneSuggest(form.value.phone);
    if (suggestion !== null) {
        return 'This number has an extra digit. Did you mean ' + suggestion + '?';
    }

    return 'Not a valid Tanzanian mobile number. Use 0712345678 or 255712345678.';
});

/**
 * What the SMS will actually be sent to. Shown while typing so the admin can
 * see the number the gateway receives, which is not the number they typed
 * whenever they used a local form.
 */
const phoneAsSent = computed(() => {
    const e164 = phoneToE164(form.value.phone);
    return e164 ? '+' + e164 : '';
});

const previewMessage = computed(() => fillTokens(form.value.message));

/**
 * Catches the silent failure where the details are typed and saved but the
 * message never mentions them, so they are stored and never sent. The default
 * templates include the token, so this only fires on a hand-written message.
 */
const detailsNotInMessage = computed(() => {
    const details = (form.value.wedding_details || '').trim();
    if (details === '') return false;
    return !/\{wedding_details\}/.test(form.value.message || '');
});

const formReminderPreview = computed(() =>
    fillTokens(form.value.reminder_message || currentCardDefaults.value?.reminder_message || formOptions.value?.default_reminder || '')
);

const reminderPreview = computed(() => {
    if (!reminderFor.value) return '';
    const template = reminderDraft.value || defaultReminderFor(reminderFor.value);
    const map = {
        guest_name: reminderFor.value.guest_name ?? '',
        first_name: (reminderFor.value.guest_name ?? '').split(' ')[0] ?? '',
        event_name: reminderFor.value.event_name ?? '',
        event_date: reminderFor.value.event_date
            ? new Date(reminderFor.value.event_date).toLocaleDateString('en-GB')
            : '',
        event_time: reminderFor.value.event_time ?? '',
        venue: reminderFor.value.venue ?? '',
        card_number: reminderFor.value.card_number ?? '',
        card_link: reminderFor.value.e_card_url ?? '',
        phone: reminderFor.value.phone ?? '',
        wedding_details: (reminderFor.value.wedding_details || '').trim(),
    };
    return template
        .replace(/\{([a-z_]+)\}/gi, (_, key) => map[key] ?? '')
        .replace(/[ \t]+$/gm, '')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
});

function statusBadge(status) {
    return { pending: 'badge-neutral', sent: 'badge-success', failed: 'badge-danger' }[status] || 'badge-neutral';
}

/** Explains why a send button is disabled, instead of leaving the admin guessing. */
function smsHint(row, otherwise = 'Send invitation SMS') {
    if (!smsConfigured.value) return 'SMS is not configured. Set NEXTSMS_USERNAME and NEXTSMS_PASSWORD.';
    if (row && row.can_send === false) return row.phone_problem || 'The phone number is not valid.';
    return otherwise;
}

function formatTime(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
}

function formatDate(value) {
    if (!value) return '';
    return new Date(value).toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

/**
 * Loads the list.
 *
 * The first call shows the full-page spinner. Every later call, including the
 * 30 second background poll, swaps the rows in silently: blanking the table
 * and flashing a spinner every half minute made the page look broken and
 * interrupted anything the admin was doing. A `background` flag is passed by
 * the poller and the Refresh button.
 */
async function load({ background = false } = {}) {
    // Only the very first load blanks the page. An explicit flag is used rather
    // than "are there rows", because a filter that legitimately returns nothing
    // must not put the page back into the first-load state.
    const isFirstLoad = !hasLoadedOnce.value;

    refreshing.value = true;
    if (isFirstLoad) loading.value = true;

    try {
        const [listData, sumData] = await Promise.all([
            invitationsService.list({
                page: pagination.value?.current_page || 1,
                q: search.value.trim() || undefined,
                filter: filter.value || undefined,
                card_type: cardTypeFilter.value || undefined,
            }),
            invitationsService.summary(),
        ]);

        rows.value = listData.data;
        pagination.value = { current_page: listData.current_page, last_page: listData.last_page };
        summary.value = sumData;
        smsConfigured.value = sumData.sms_configured !== false;
        lastLoadedAt.value = new Date().toLocaleTimeString('en-GB', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });

        // Keep the selection, but only for rows that are still on this page.
        selected.value = selected.value.filter((id) => rows.value.some((r) => r.id === id));

        // Deleting the last row of the last page leaves the admin on a page that
        // no longer exists, and the table would report "No invitations saved yet"
        // while the counters beside it still showed rows. Step back to the last
        // page that does have rows instead.
        if (!rows.value.length && listData.last_page > 0 && listData.current_page > listData.last_page) {
            pagination.value.current_page = listData.last_page;
            return load({ background });
        }
    } catch (e) {
        // A failed background poll should not spam the admin with a toast
        // every 30 seconds; only report it if the page is already in use.
        if (!background || rows.value.length) {
            toast.error('Failed to load invitations. Check your connection.');
        }
    } finally {
        hasLoadedOnce.value = true;
        refreshing.value = false;
        loading.value = false;
    }
}

/**
 * The form needs the guest list with each guest's cards plus the event
 * defaults, which is a heavier query than the table. It is fetched once and
 * refreshed whenever the create form is opened so newly added guests show up.
 */
async function loadFormOptions() {
    try {
        formOptions.value = await invitationsService.formOptions();
    } catch (e) {
        toast.error('Failed to load guests and cards.');
    }
}

function setFilter(value) {
    filter.value = value;
    if (pagination.value) pagination.value.current_page = 1;
    load();
}

function setCardTypeFilter(value) {
    cardTypeFilter.value = value;
    if (pagination.value) pagination.value.current_page = 1;
    selected.value = [];
    load();
}

function debouncedSearch() {
    clearTimeout(searchTimer);
    if (pagination.value) pagination.value.current_page = 1;
    searchTimer = setTimeout(load, 400);
}

function changePage(page) {
    if (pagination.value) pagination.value.current_page = page;
    load();
}

function toggleSelect(id) {
    selected.value = selected.value.includes(id)
        ? selected.value.filter((x) => x !== id)
        : [...selected.value, id];
}

function toggleSelectAll() {
    if (allOnPageSelected.value) {
        selected.value = selected.value.filter((id) => !pageIds.value.includes(id));
    } else {
        selected.value = [...new Set([...selected.value, ...pageIds.value])];
    }
}

function clearSelection() {
    selected.value = [];
}

function onGuestChange() {
    form.value.phone = selectedGuest.value?.phone ?? '';
    form.value.card_id = '';
}

function setInvitationCardType(type) {
    if (form.value.card_type === type) return;
    form.value.card_type = type;
    form.value.guest_id = '';
    form.value.card_id = '';
    form.value.phone = '';
    applyCardDefaults(type);
}

function applyCardDefaults(type) {
    const defaults = formOptions.value?.card_defaults?.[type === 'double' ? 'double' : 'single'];
    if (!defaults) return;
    form.value.wedding_details = defaults.wedding_details;
    form.value.message = defaults.message;
    form.value.reminder_message = defaults.reminder_message;
    form.value.event_name = defaults.event_name;
    form.value.event_date = defaults.event_date;
    form.value.event_time = defaults.event_time;
    form.value.venue = defaults.venue;
}

function onCardChange() {
    const card = selectedGuestCards.value.find((c) => Number(c.id) === Number(form.value.card_id));
    if (!card) return;
    form.value.event_name ||= card.event_name || '';
    form.value.event_date ||= card.event_date || '';
    form.value.venue ||= card.venue || '';
}

function clearFilter() {
    search.value = '';
    filter.value = '';
    cardTypeFilter.value = '';
    pagination.value = { current_page: 1, last_page: 1 };
    load();
}

async function openCreate() {
    await loadFormOptions();
    editing.value = null;
    form.value = emptyForm();
    applyCardDefaults(form.value.card_type);
    showForm.value = true;
}

async function openEdit(row) {
    await loadFormOptions();
    editing.value = row;
    form.value = {
        card_type: 'single',
        guest_id: row.guest_id ?? '',
        card_id: row.card_id ?? '',
        phone: row.phone ?? '',
        event_name: row.event_name ?? '',
        event_date: row.event_date ?? '',
        event_time: row.event_time ?? '',
        venue: row.venue ?? '',
        wedding_details: row.wedding_details ?? '',
        message: row.message ?? '',
        reminder_message: row.reminder_message ?? '',
    };
    const guest = (formOptions.value?.guests ?? []).find((item) => Number(item.id) === Number(row.guest_id));
    const existingCard = (guest?.cards ?? []).find((card) => Number(card.id) === Number(row.card_id));
    form.value.card_type = existingCard?.card_type ?? 'none';
    showForm.value = true;
}

function closeForm() {
    showForm.value = false;
    editing.value = null;
}

async function submit() {
    if (!form.value.guest_id) {
        toast.error('Please choose a guest.');
        return;
    }
    saving.value = true;
    try {
        const payload = {
            guest_id: Number(form.value.guest_id),
            card_id: form.value.card_id ? Number(form.value.card_id) : null,
            phone: form.value.phone,
            event_name: form.value.event_name,
            event_date: form.value.event_date,
            event_time: form.value.event_time,
            venue: form.value.venue,
            wedding_details: form.value.wedding_details,
            message: form.value.message,
            reminder_message: form.value.reminder_message,
        };
        if (editing.value) {
            await invitationsService.update(editing.value.id, payload);
            toast.success('Invitation updated.');
        } else {
            await invitationsService.create(payload);
            toast.success('Invitation saved.');
        }
        closeForm();
        await load();
    } catch (e) {
        const errors = e.response?.data?.errors;
        toast.error(errors ? Object.values(errors).flat()[0] : 'Failed to save the invitation.');
    } finally {
        saving.value = false;
    }
}

async function openView(row) {
    try {
        viewing.value = await invitationsService.show(row.id);
    } catch {
        toast.error('Failed to load the invitation details.');
    }
}

/**
 * Sends the invitation, but asks first when the number failed the format
 * check. The gateway accepts numbers it cannot reach and reports success, so
 * without this the admin pays for a message that silently never arrives.
 * The admin may know better than the format check, so the choice is theirs.
 */
function requestSend(row) {
    if (row.can_send === false) {
        sendWarningFor = row;
        return;
    }

    doSendSms(row);
}

async function doSendSms(row) {
    sendingId.value = row.id;
    sendWarningFor = null;
    try {
        const res = await invitationsService.sendSms(row.id);
        sendWarningFor = null;

        if (res.warning) {
            toast.warning(res.message, 9000);
            toast.warning(res.warning, 12000);
        } else {
            toast[res.success ? 'success' : 'warning'](res.message);
        }

        await load();
    } catch (e) {
        toast.error(e.response?.data?.message || 'Failed to send the SMS.');
        await load();
    } finally {
        sendingId.value = null;
    }
}

function openReminder(row) {
    reminderFor.value = row;
    reminderDraft.value = row.reminder_message || defaultReminderFor(row);
}

function defaultReminderFor(row) {
    const type = row?.card_type === 'double' ? 'double' : 'single';
    return formOptions.value?.card_defaults?.[type]?.reminder_message ?? formOptions.value?.default_reminder ?? '';
}

async function doSendReminder() {
    reminding.value = true;
    try {
        const res = await invitationsService.sendReminder(reminderFor.value.id, reminderDraft.value);
        toast[res.success ? 'success' : 'warning'](res.message);
        if (res.success) {
            reminderFor.value = null;
            await load();
        }
    } catch (e) {
        toast.error(e.response?.data?.message || 'Failed to send the reminder.');
    } finally {
        reminding.value = false;
    }
}

/**
 * Manual WhatsApp share using the device share sheet with the actual card
 * artwork, falling back to a wa.me deep link.
 *
 * No Business API and no background automation: the admin picks WhatsApp in
 * the share sheet themselves, which is what the brief asked for.
 *
 * The card designs are multi-megabyte, so each image is fetched at most once
 * and kept as a File. Hovering the button warms the cache, which means the
 * click that actually shares can call navigator.share() without first waiting
 * on a network round trip. That matters: browsers only allow navigator.share()
 * while the click's user activation is still valid, and an await on a large
 * download can consume it.
 */
const cardFileCache = new Map();

async function fetchCardFile(row) {
    const url = row.card_image;
    if (!url) return null;
    if (cardFileCache.has(url)) return cardFileCache.get(url);

    try {
        const response = await fetch(url);
        if (!response.ok) return null;

        const blob = await response.blob();
        const extension = (url.split('?')[0].split('.').pop() || 'png').toLowerCase();
        const type = extension === 'jpg' || extension === 'jpeg' ? 'image/jpeg' : `image/${extension}`;

        const file = new File([blob], `card-${row.card_number || row.id}.${extension}`, { type });

        if (typeof navigator.canShare !== 'function' || !navigator.canShare({ files: [file] })) {
            return null;
        }

        cardFileCache.set(url, file);
        return file;
    } catch (e) {
        return null;
    }
}

/** Warms the cache when the admin hovers the button, so the click is fast. */
function prewarmCard(row) {
    if (row.card_image && !cardFileCache.has(row.card_image)) {
        fetchCardFile(row);
    }
}

/**
 * Shares the card artwork together with the wedding information.
 *
 * The caption comes from the message the server rendered, which is the same
 * saved message the SMS uses. It used to be assembled here by hand, which meant
 * the WhatsApp text quietly drifted away from the SMS text and neither carried
 * the card details.
 */
async function shareWhatsApp(row) {
    if (!row.card_image) {
        toast.warning('This invitation has no card design to share. Edit it and pick a card first.');
        return;
    }

    const text = row.whatsapp_text || '';

    if (!text.trim()) {
        toast.warning('This invitation has no message to send with the card. Edit it and add a message first.');
        return;
    }

    const digits = String(row.phone || '').replace(/\D+/g, '');

    // Fast path: the image is already cached, so share() can be called while
    // the user activation from the click is still alive.
    let file = cardFileCache.get(row.card_image) ?? null;

    if (!file) {
        file = await fetchCardFile(row);

        // The await above may have used up the activation window.
        const stillActive = navigator.userActivation?.isActive ?? true;

        if (file && stillActive) {
            try {
                await navigator.share({ files: [file], text, title: 'Your E-Card' });
                await invitationsService.markWhatsAppShared(row.id);
                toast.success('Card shared. Select WhatsApp in the share sheet.');
                return;
            } catch (e) {
                // A cancelled share sheet should not fall through to wa.me.
                if (e?.name === 'AbortError') return;
            }
        }
    } else {
        try {
            await navigator.share({ files: [file], text, title: 'Your E-Card' });
            await invitationsService.markWhatsAppShared(row.id);
            toast.success('Card shared. Select WhatsApp in the share sheet.');
            return;
        } catch (e) {
            if (e?.name === 'AbortError') return;
        }
    }

    if (digits.length >= 10) {
        window.open(`https://wa.me/${digits}?text=${encodeURIComponent(text)}`, '_blank');
        await invitationsService.markWhatsAppShared(row.id);
        toast.info('WhatsApp opened. Attach the card image from your gallery.');
    } else {
        toast.error('This guest has no valid phone number for WhatsApp.');
    }
}

function askDelete(row) {
    deleteTarget.value = row;
}

async function doDelete() {
    deleting.value = true;
    try {
        await invitationsService.remove(deleteTarget.value.id);
        toast.success('Invitation deleted.');
        deleteTarget.value = null;
        await load();
    } catch {
        toast.error('Failed to delete the invitation.');
    } finally {
        deleting.value = false;
    }
}

async function doBulkSend() {
    bulkSending.value = true;
    try {
        const res = await invitationsService.bulkSendSms(selected.value);
        toast[res.success ? 'success' : 'warning'](res.message);
        confirmBulk.value = false;
        selected.value = [];
        await load();
    } catch (e) {
        toast.error(e.response?.data?.message || 'Failed to send the SMS messages.');
    } finally {
        bulkSending.value = false;
    }
}

onMounted(() => {
    load();
    loadFormOptions();
    // Background poll, so a send or reminder that finishes on the server shows
    // up in the counters without the admin having to press Refresh.
    //
    // It is held back whenever the admin might be in the middle of something:
    // a send, a save, the create or edit dialog, or a half typed search. A poll
    // landing on any of those would swap the rows out from under them, and with
    // a partial search term it would show the wrong list entirely.
    refreshTimer = setInterval(() => {
        const busy =
            refreshing.value ||
            bulkSending.value ||
            sendingId.value ||
            reminding.value ||
            saving.value ||
            showForm.value ||
            search.value.trim() !== '' ||
            document.activeElement?.closest('form') !== null;

        if (!busy) {
            load({ background: true });
        }
    }, 30000);
});

onUnmounted(() => {
    clearTimeout(searchTimer);
    clearInterval(refreshTimer);
});
</script>
