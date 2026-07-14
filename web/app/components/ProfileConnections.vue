<script setup lang="ts">
import { buildGogAuthUrl, connectionStatusLabel } from '../utils/connections'
import type { ApiError } from '../utils/api'

const config = useRuntimeConfig()
const { connections, pending, error, fetchConnections, connect, syncNow, disconnect } =
  useConnections()

const steamInput = ref('')
const gogCode = ref('')
const formError = ref('')
const busy = ref(false)

const gogAuthUrl = computed(() => buildGogAuthUrl(config.public.gogClientId as string))
const hasSteam = computed(() =>
  connections.value.some((c) => c.platform === 'steam' && c.status !== 'disconnected')
)
const hasGog = computed(() =>
  connections.value.some((c) => c.platform === 'gog' && c.status !== 'disconnected')
)

onMounted(() => fetchConnections())

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

function connectSteam(): Promise<void> {
  const input = steamInput.value.trim()
  const payload = /^\d{17}$/.test(input)
    ? { platform: 'steam' as const, steam_id: input }
    : { platform: 'steam' as const, vanity_url: input }

  return run(async () => {
    await connect(payload)
    steamInput.value = ''
  })
}

function connectGog(): Promise<void> {
  return run(async () => {
    await connect({ platform: 'gog', code: gogCode.value.trim() })
    gogCode.value = ''
  })
}
</script>

<template>
  <section class="rounded-xl border border-slate-800 bg-slate-900 p-6">
    <h2 class="mb-4 font-semibold text-teal-300">Connected services</h2>

    <p v-if="error" class="mb-3 text-sm text-rose-400">{{ error }}</p>
    <p v-else-if="pending && connections.length === 0" class="mb-3 text-sm text-slate-400">
      Loading connections…
    </p>

    <ul v-if="connections.length" class="mb-5 space-y-2">
      <li
        v-for="connection in connections"
        :key="connection.id"
        class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-slate-800 bg-slate-950 px-3 py-2"
      >
        <div class="flex items-center gap-2">
          <span class="text-sm font-semibold uppercase tracking-wide text-slate-100">
            {{ connection.platform }}
          </span>
          <span
            class="rounded px-1.5 py-0.5 text-xs"
            :class="{
              'bg-teal-950/60 text-teal-300': connection.status === 'ok',
              'bg-slate-800 text-slate-300':
                connection.status === 'pending' || connection.status === 'syncing',
              'bg-rose-950/60 text-rose-300':
                connection.status === 'error' || connection.status === 'error_private',
              'bg-amber-950/60 text-amber-300': connection.status === 'disconnected'
            }"
          >
            {{ connectionStatusLabel(connection.status) }}
          </span>
          <span v-if="connection.last_synced_at" class="text-xs text-slate-500">
            synced {{ new Date(connection.last_synced_at).toLocaleString() }}
          </span>
        </div>
        <div class="flex gap-2">
          <button
            v-if="connection.status !== 'disconnected'"
            :disabled="busy"
            class="rounded-md border border-slate-700 px-2 py-1 text-xs text-slate-300 transition hover:border-teal-400/60 hover:text-teal-300 disabled:opacity-50"
            @click="run(() => syncNow(connection.id))"
          >
            Sync now
          </button>
          <button
            v-if="connection.status !== 'disconnected'"
            :disabled="busy"
            class="rounded-md border border-slate-700 px-2 py-1 text-xs text-slate-300 transition hover:border-amber-400/60 hover:text-amber-300 disabled:opacity-50"
            @click="run(() => disconnect(connection.id))"
          >
            Disconnect
          </button>
        </div>
      </li>
    </ul>
    <p class="mb-5 text-xs text-slate-500">
      Disconnecting keeps your games — reconnect any time to resume syncing.
    </p>

    <div class="grid gap-4 sm:grid-cols-2">
      <div v-if="!hasSteam" class="rounded-md border border-slate-800 p-4">
        <h3 class="mb-2 text-sm font-semibold text-slate-100">Connect Steam</h3>
        <input
          v-model="steamInput"
          type="text"
          placeholder="SteamID64 or vanity name"
          class="mb-2 block w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-600 focus:border-teal-400 focus:outline-none"
        />
        <button
          :disabled="busy || !steamInput.trim()"
          class="rounded-md bg-teal-500 px-3 py-1.5 text-sm font-semibold text-slate-950 transition hover:bg-teal-400 disabled:cursor-not-allowed disabled:opacity-50"
          @click="connectSteam"
        >
          Connect Steam
        </button>
      </div>

      <div v-if="!hasGog" class="rounded-md border border-slate-800 p-4">
        <h3 class="mb-2 text-sm font-semibold text-slate-100">Connect GOG</h3>
        <p class="mb-2 text-xs text-slate-400">
          <a
            :href="gogAuthUrl"
            target="_blank"
            rel="noopener"
            class="text-teal-400 hover:text-teal-300"
            >Log in to GOG</a
          >, then paste the <code class="text-slate-300">code</code> from the final URL.
        </p>
        <input
          v-model="gogCode"
          type="text"
          placeholder="GOG login code"
          class="mb-2 block w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-600 focus:border-teal-400 focus:outline-none"
        />
        <button
          :disabled="busy || !gogCode.trim()"
          class="rounded-md bg-teal-500 px-3 py-1.5 text-sm font-semibold text-slate-950 transition hover:bg-teal-400 disabled:cursor-not-allowed disabled:opacity-50"
          @click="connectGog"
        >
          Connect GOG
        </button>
      </div>
    </div>

    <p v-if="formError" class="mt-3 text-sm text-rose-400">{{ formError }}</p>
  </section>
</template>
