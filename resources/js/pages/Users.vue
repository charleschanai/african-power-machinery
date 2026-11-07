<template>
    <div class="space-y-6">
        <div class="page-header">
            <div>
                <h1 class="page-title">Users</h1>
                <p class="page-subtitle">Add attendants and admins, and control who can sign in.</p>
            </div>
            <div class="flex gap-2">
                <button type="button" class="btn btn-secondary" @click="load">
                    <RefreshCw :class="['h-4 w-4', loading && 'animate-spin']" />
                    Refresh
                </button>
                <button type="button" class="btn btn-primary" @click="openCreate">
                    <UserPlus class="h-4 w-4" />
                    Add User
                </button>
            </div>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <Search class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input
                    v-model="search"
                    type="text"
                    class="input pl-10!"
                    placeholder="Search user by name or email..."
                    @input="debouncedSearch"
                />
            </div>
            <div class="flex gap-2">
                <select v-model="roleFilter" class="input w-auto!" @change="load">
                    <option value="">All roles</option>
                    <option value="admin">Admin</option>
                    <option value="staff">Attendant</option>
                </select>
                <select v-model="statusFilter" class="input w-auto!" @change="load">
                    <option value="">All</option>
                    <option value="active">Active only</option>
                    <option value="inactive">Deactivated only</option>
                </select>
            </div>
        </div>

        <div class="card overflow-hidden">
            <div v-if="loading" class="flex justify-center py-16">
                <span class="h-8 w-8 animate-spin rounded-full border-4 border-emerald-500/30 border-t-emerald-600"></span>
            </div>

            <div v-else-if="users.length" class="overflow-x-auto">
                <table class="data-table w-full min-w-[820px] text-left">
                    <thead>
                        <tr class="border-b border-slate-200">
                            <th class="table-header">User</th>
                            <th class="table-header">Role</th>
                            <th class="table-header">2FA</th>
                            <th class="table-header text-center">Entries Handled</th>
                            <th class="table-header">Status</th>
                            <th class="table-header text-right!">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="user in users" :key="user.id" :class="!user.is_active && 'opacity-60'">
                            <td class="table-cell">
                                <div class="flex items-center gap-3">
                                    <span
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-sm font-bold ring-1 ring-inset"
                                        :class="
                                            user.role === 'admin'
                                                ? 'bg-slate-900 text-white ring-slate-900/10'
                                                : 'bg-emerald-50 text-emerald-700 ring-emerald-600/10'
                                        "
                                    >
                                        {{ user.name?.charAt(0)?.toUpperCase() }}
                                    </span>
                                    <div class="min-w-0">
                                        <p class="flex items-center gap-1.5 font-semibold text-slate-900">
                                            {{ user.name }}
                                            <span
                                                v-if="user.id === auth.user?.id"
                                                class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-500"
                                            >
                                                You
                                            </span>
                                        </p>
                                        <p class="truncate text-xs text-slate-500">{{ user.email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="table-cell">
                                <span :class="user.role === 'admin' ? 'badge badge-dark' : 'badge badge-success'">
                                    <ShieldCheck v-if="user.role === 'admin'" class="h-3 w-3" />
                                    <UserRound v-else class="h-3 w-3" />
                                    {{ user.role === 'admin' ? 'Admin' : 'Attendant' }}
                                </span>
                            </td>
                            <td class="table-cell">
                                <span v-if="user.two_factor_enabled" class="badge badge-success">
                                    <ShieldCheck class="h-3 w-3" />
                                    On
                                </span>
                                <span v-else class="badge badge-warning">
                                    <ShieldAlert class="h-3 w-3" />
                                    Off
                                </span>
                            </td>
                            <td class="table-cell text-center">
                                <span class="tabular-nums">{{ user.processed_entries_count ?? 0 }}</span>
                            </td>
                            <td class="table-cell">
                                <span v-if="user.is_active" class="badge badge-success">
                                    <UserCheck class="h-3 w-3" />
                                    Active
                                </span>
                                <span v-else class="badge badge-danger">
                                    <UserX class="h-3 w-3" />
                                    Deactivated
                                </span>
                            </td>
                            <td class="table-cell">
                                <div class="flex justify-end gap-1.5">
                                    <button
                                        type="button"
                                        class="btn btn-secondary px-3! py-1.5! text-xs"
                                        @click="openPassword(user)"
                                    >
                                        <KeyRound class="h-3.5 w-3.5" />
                                        Password
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-secondary px-3! py-1.5! text-xs"
                                        @click="openEdit(user)"
                                    >
                                        <Pencil class="h-3.5 w-3.5" />
                                        Edit
                                    </button>
                                    <button
                                        v-if="user.is_active"
                                        type="button"
                                        class="btn btn-danger px-3! py-1.5! text-xs"
                                        :disabled="user.id === auth.user?.id"
                                        :title="user.id === auth.user?.id ? 'You cannot deactivate your own account' : ''"
                                        @click="askDeactivate(user)"
                                    >
                                        <UserX class="h-3.5 w-3.5" />
                                        Deactivate
                                    </button>
                                    <button
                                        v-else
                                        type="button"
                                        class="btn btn-secondary px-3! py-1.5! text-xs"
                                        @click="doActivate(user)"
                                    >
                                        <UserCheck class="h-3.5 w-3.5" />
                                        Reactivate
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="flex flex-col items-center justify-center gap-3 py-16 text-center">
                <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400">
                    <UsersRound class="h-7 w-7" stroke-width="1.5" />
                </span>
                <div>
                    <p class="font-semibold text-slate-700">No users</p>
                    <p class="mt-0.5 text-sm text-slate-400">Click "Add User" to create an account.</p>
                </div>
            </div>

            <div class="px-5 py-4">
                <Pagination :pagination="pagination" @change="changePage" />
            </div>
        </div>

        <Teleport to="body">
            <!-- Add / edit -->
            <div
                v-if="showModal"
                class="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/60 p-4 backdrop-blur-sm sm:items-center"
                @click.self="showModal = false"
            >
                <div class="w-full max-w-md animate-scale-in rounded-2xl bg-white p-6 shadow-2xl">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <span
                                class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 ring-1 ring-inset ring-emerald-600/10"
                            >
                                <UserPlus v-if="!editing" class="h-5 w-5" />
                                <Pencil v-else class="h-5 w-5" />
                            </span>
                            <div>
                                <h3 class="text-lg font-bold tracking-tight text-slate-900">
                                    {{ editing ? 'Edit User' : 'Add User' }}
                                </h3>
                                <p class="text-xs text-slate-400">
                                    {{
                                        editing
                                            ? 'Change details or role. Leave password blank to keep it.'
                                            : 'Create an account. Share the password with them directly.'
                                    }}
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
                            @click="showModal = false"
                        >
                            <X class="h-5 w-5" />
                        </button>
                    </div>

                    <form class="mt-6 space-y-4" @submit.prevent="save">
                        <div>
                            <label for="user_name" class="label">Full Name</label>
                            <input id="user_name" v-model="form.name" type="text" class="input" required />
                        </div>
                        <div>
                            <label for="user_email" class="label">Email</label>
                            <input id="user_email" v-model="form.email" type="email" class="input" required />
                        </div>
                        <div>
                            <label for="user_role" class="label">Role</label>
                            <select id="user_role" v-model="form.role" class="input" required>
                                <option value="staff">Attendant — scans cards at the gate</option>
                                <option value="admin">Admin — full access</option>
                            </select>
                        </div>
                        <div v-if="!editing || passwordTouched">
                            <label for="user_password" class="label">Password</label>
                            <input
                                id="user_password"
                                v-model="form.password"
                                type="password"
                                class="input"
                                :required="!editing"
                                minlength="8"
                            />
                            <label for="user_password_confirmation" class="label mt-3">Confirm Password</label>
                            <input
                                id="user_password_confirmation"
                                v-model="form.password_confirmation"
                                type="password"
                                class="input"
                                :required="!editing"
                                minlength="8"
                            />
                            <p class="mt-1.5 text-xs text-slate-400">At least 8 characters.</p>
                        </div>
                        <button
                            v-else
                            type="button"
                            class="btn btn-secondary w-full"
                            @click="passwordTouched = true"
                        >
                            <KeyRound class="h-4 w-4" />
                            Set a new password
                        </button>

                        <p
                            v-if="formError"
                            class="flex items-start gap-2 rounded-xl bg-rose-50 px-3 py-2.5 text-sm text-rose-700 ring-1 ring-inset ring-rose-600/10"
                        >
                            <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" />
                            <span>{{ formError }}</span>
                        </p>

                        <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                            <button type="button" class="btn btn-secondary" @click="showModal = false">
                                Cancel
                            </button>
                            <button type="submit" class="btn btn-primary" :disabled="saving">
                                <span
                                    v-if="saving"
                                    class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"
                                ></span>
                                <template v-else>
                                    <Check v-if="editing" class="h-4 w-4" />
                                    <UserPlus v-else class="h-4 w-4" />
                                </template>
                                {{ editing ? 'Update' : 'Add' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Reset password -->
            <div
                v-if="showPasswordModal"
                class="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/60 p-4 backdrop-blur-sm sm:items-center"
                @click.self="showPasswordModal = false"
            >
                <div class="w-full max-w-md animate-scale-in rounded-2xl bg-white p-6 shadow-2xl">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <span
                                class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-50 text-amber-600 ring-1 ring-inset ring-amber-600/10"
                            >
                                <KeyRound class="h-5 w-5" />
                            </span>
                            <div>
                                <h3 class="text-lg font-bold tracking-tight text-slate-900">Reset Password</h3>
                                <p class="text-xs text-slate-400">
                                    {{ passwordTarget?.name }} will be logged out of every device.
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
                            @click="showPasswordModal = false"
                        >
                            <X class="h-5 w-5" />
                        </button>
                    </div>

                    <form class="mt-6 space-y-4" @submit.prevent="savePassword">
                        <div>
                            <label for="reset_password" class="label">New Password</label>
                            <input
                                id="reset_password"
                                v-model="passwordForm.password"
                                type="password"
                                class="input"
                                required
                                minlength="8"
                            />
                        </div>
                        <div>
                            <label for="reset_password_confirmation" class="label">Confirm Password</label>
                            <input
                                id="reset_password_confirmation"
                                v-model="passwordForm.password_confirmation"
                                type="password"
                                class="input"
                                required
                                minlength="8"
                            />
                        </div>

                        <p
                            v-if="formError"
                            class="flex items-start gap-2 rounded-xl bg-rose-50 px-3 py-2.5 text-sm text-rose-700 ring-1 ring-inset ring-rose-600/10"
                        >
                            <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" />
                            <span>{{ formError }}</span>
                        </p>

                        <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                            <button type="button" class="btn btn-secondary" @click="showPasswordModal = false">
                                Cancel
                            </button>
                            <button type="submit" class="btn btn-primary" :disabled="saving">
                                <span
                                    v-if="saving"
                                    class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"
                                ></span>
                                <template v-else><KeyRound class="h-4 w-4" /></template>
                                Reset Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <ConfirmDialog
            :open="deactivateTarget !== null"
            title="Deactivate User"
            :message="deactivateMessage"
            confirm-label="Deactivate"
            @confirm="doDeactivate"
            @close="deactivateTarget = null"
        />
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import {
    AlertCircle,
    Check,
    KeyRound,
    Pencil,
    RefreshCw,
    Search,
    ShieldAlert,
    ShieldCheck,
    UserCheck,
    UserPlus,
    UserRound,
    UsersRound,
    UserX,
    X,
} from '@lucide/vue';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import Pagination from '../components/Pagination.vue';
import { usersService } from '../services/users';
import { useAuthStore } from '../stores/auth';
import { useToastStore } from '../stores/toast';

const toast = useToastStore();
const auth = useAuthStore();

const users = ref([]);
const pagination = ref(null);
const loading = ref(true);
const search = ref('');
const roleFilter = ref('');
const statusFilter = ref('');

const showModal = ref(false);
const editing = ref(null);
const saving = ref(false);
const formError = ref('');
const passwordTouched = ref(false);
const form = reactive({
    name: '',
    email: '',
    role: 'staff',
    password: '',
    password_confirmation: '',
});

const showPasswordModal = ref(false);
const passwordTarget = ref(null);
const passwordForm = reactive({ password: '', password_confirmation: '' });

const deactivateTarget = ref(null);
let searchTimer = null;

const deactivateMessage = computed(() => {
    if (! deactivateTarget.value) return '';
    return (
        `Deactivate ${deactivateTarget.value.name}? They will be signed out immediately and unable to ` +
        'sign in again. Their entry history is kept, so past scans at the gate still show who handled them.'
    );
});

async function load(page = 1) {
    loading.value = true;
    try {
        const params = { page };
        if (search.value.trim()) params.q = search.value.trim();
        if (roleFilter.value) params.role = roleFilter.value;
        if (statusFilter.value) params.status = statusFilter.value;

        const data = await usersService.index(params);
        users.value = data.data;
        pagination.value = {
            current_page: data.current_page,
            last_page: data.last_page,
        };
    } catch {
        toast.error('Failed to fetch users.');
    } finally {
        loading.value = false;
    }
}

function changePage(page) {
    load(page);
}

function debouncedSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => load(1), 400);
}

function openCreate() {
    editing.value = null;
    passwordTouched.value = false;
    formError.value = '';
    Object.assign(form, {
        name: '',
        email: '',
        role: 'staff',
        password: '',
        password_confirmation: '',
    });
    showModal.value = true;
}

function openEdit(user) {
    editing.value = user;
    passwordTouched.value = false;
    formError.value = '';
    Object.assign(form, {
        name: user.name,
        email: user.email,
        role: user.role,
        password: '',
        password_confirmation: '',
    });
    showModal.value = true;
}

function openPassword(user) {
    passwordTarget.value = user;
    formError.value = '';
    Object.assign(passwordForm, { password: '', password_confirmation: '' });
    showPasswordModal.value = true;
}

async function save() {
    saving.value = true;
    formError.value = '';

    try {
        if (editing.value) {
            const payload = { name: form.name, email: form.email, role: form.role };
            if (passwordTouched.value) {
                payload.password = form.password;
                payload.password_confirmation = form.password_confirmation;
            }
            const res = await usersService.update(editing.value.id, payload);
            toast.success(res.message || 'User updated.');
        } else {
            const res = await usersService.store({
                name: form.name,
                email: form.email,
                role: form.role,
                password: form.password,
                password_confirmation: form.password_confirmation,
            });
            toast.success(res.message || 'User added.');
        }

        showModal.value = false;
        load(pagination.value?.current_page || 1);
    } catch (e) {
        const errors = e.response?.data?.errors;
        formError.value = errors
            ? Object.values(errors).flat().join(' ')
            : e.response?.data?.message || 'Could not save the user.';
    } finally {
        saving.value = false;
    }
}

async function savePassword() {
    saving.value = true;
    formError.value = '';

    try {
        const res = await usersService.resetPassword(
            passwordTarget.value.id,
            passwordForm.password,
            passwordForm.password_confirmation,
        );
        toast.success(res.message || 'Password reset.');
        showPasswordModal.value = false;
    } catch (e) {
        const errors = e.response?.data?.errors;
        formError.value = errors
            ? Object.values(errors).flat().join(' ')
            : e.response?.data?.message || 'Could not reset the password.';
    } finally {
        saving.value = false;
    }
}

function askDeactivate(user) {
    deactivateTarget.value = user;
}

async function doDeactivate() {
    const target = deactivateTarget.value;
    if (! target) return;

    try {
        const res = await usersService.deactivate(target.id);
        toast.success(res.message || 'User deactivated.');
        deactivateTarget.value = null;
        load(pagination.value?.current_page || 1);
    } catch (e) {
        toast.error(e.response?.data?.message || 'Could not deactivate the user.');
    }
}

async function doActivate(user) {
    try {
        const res = await usersService.activate(user.id);
        toast.success(res.message || 'User reactivated.');
        load(pagination.value?.current_page || 1);
    } catch (e) {
        toast.error(e.response?.data?.message || 'Could not reactivate the user.');
    }
}

onMounted(() => load(1));
</script>
