<script setup lang="ts">
import { connectionStatusLabel } from '../utils/connections'
import type { ApiError } from '../utils/api'
import type { SteamIdentity } from '../composables/useConnections'

const { familyMembers, pending, error, fetchFamilyMembers, addFamilyMember, removeFamilyMember } =
  useFamilyMembers()
const { resolveSteamIdentity } = useConnections()

const steamInput = ref('')
const formError = ref('')
const busy = ref(false)
const resolvedIdentity = ref<SteamIdentity | null>(null)

onMounted(() => fetchFamilyMembers())

async function run(action: () => Promise<void>): Promise<void> {
  formError.value = ''
  busy.value = true
  try {
    await action()
  } catch (err) {
    formError.value = (err as ApiError).message
  } finally {
    busy.value = false
  }
}

/** V25: look up identity first — nothing is added yet. */
function lookUpSteam(): Promise<void> {
  const input = steamInput.value.trim()
  const payload = /^\d{17}$/.test(input)
    ? { steam_id: input }
    : { vanity_url: input }

  return run(async () => {
    resolvedIdentity.value = await resolveSteamIdentity(payload)
  })
}

function confirmAdd(): Promise<void> {
  const identity = resolvedIdentity.value
  if (!identity) return Promise.resolve()

  return run(async () => {
    await addFamilyMember(identity.steam_id)
    resolvedIdentity.value = null
    steamInput.value = ''
  })
}

function cancelResolve(): void {
  resolvedIdentity.value = null
}
</script>

<template>
  <section class="rounded-xl border border-slate-800 bg-slate-900 p-6">
    <h2 class="mb-4 font-semibold text-teal-300">Family sharing</h2>
    <p class="mb-4 text-xs text-slate-500">
      Add a Steam family member to surface games they've shared with you as
      "Shared" in your library.
    </p>

    <p v-if="error" class="mb-3 text-sm text-rose-400">{{ error }}</p>
    <p v-else-if="pending && familyMembers.length === 0" class="mb-3 text-sm text-slate-400">
      Loading family members…
    </p>

    <ul v-if="familyMembers.length" class="mb-5 space-y-2">
      <li
        v-for="member in familyMembers"
        :key="member.id"
        class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-slate-800 bg-slate-950 px-3 py-2"
      >
        <div class="flex items-center gap-2">
          <img :src="member.avatar_url" :alt="member.persona_name" class="h-8 w-8 rounded" />
          <span class="text-sm font-semibold text-slate-100">{{ member.persona_name }}</span>
          <span
            class="rounded px-1.5 py-0.5 text-xs"
            :class="{
              'bg-teal-950/60 text-teal-300': member.status === 'ok',
              'bg-slate-800 text-slate-300': member.status === 'pending' || member.status === 'syncing',
              'bg-rose-950/60 text-rose-300': member.status === 'error' || member.status === 'error_private'
            }"
          >
            {{ connectionStatusLabel(member.status) }}
          </span>
        </div>
        <button
          :disabled="busy"
          class="rounded-md border border-slate-700 px-2 py-1 text-xs text-slate-300 transition hover:border-rose-400/60 hover:text-rose-300 disabled:opacity-50"
          @click="run(() => removeFamilyMember(member.id))"
        >
          Remove
        </button>
      </li>
    </ul>

    <div class="max-w-sm rounded-md border border-slate-800 p-4">
      <h3 class="mb-2 text-sm font-semibold text-slate-100">Add family member</h3>

      <template v-if="!resolvedIdentity">
        <input
          v-model="steamInput"
          type="text"
          placeholder="SteamID64 or vanity name"
          class="mb-2 block w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-600 focus:border-teal-400 focus:outline-none"
        />
        <button
          :disabled="busy || !steamInput.trim()"
          class="rounded-md bg-teal-500 px-3 py-1.5 text-sm font-semibold text-slate-950 transition hover:bg-teal-400 disabled:cursor-not-allowed disabled:opacity-50"
          @click="lookUpSteam"
        >
          Look up
        </button>
      </template>

      <template v-else>
        <div class="mb-3 flex items-center gap-3 rounded-md border border-slate-800 bg-slate-950 p-2">
          <img
            :src="resolvedIdentity.avatar_url"
            :alt="resolvedIdentity.persona_name"
            class="h-10 w-10 rounded"
          />
          <div>
            <p class="text-sm font-semibold text-slate-100">{{ resolvedIdentity.persona_name }}</p>
            <p class="text-xs text-slate-500">Is this them?</p>
          </div>
        </div>
        <div class="flex gap-2">
          <button
            :disabled="busy"
            class="rounded-md bg-teal-500 px-3 py-1.5 text-sm font-semibold text-slate-950 transition hover:bg-teal-400 disabled:cursor-not-allowed disabled:opacity-50"
            @click="confirmAdd"
          >
            Add this account
          </button>
          <button
            :disabled="busy"
            class="rounded-md border border-slate-700 px-3 py-1.5 text-sm text-slate-300 transition hover:border-rose-400/60 hover:text-rose-300 disabled:opacity-50"
            @click="cancelResolve"
          >
            Cancel
          </button>
        </div>
      </template>
    </div>

    <p v-if="formError" class="mt-3 text-sm text-rose-400">{{ formError }}</p>
  </section>
</template>
