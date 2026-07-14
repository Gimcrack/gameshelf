<script setup lang="ts">
import type { ApiError } from '../utils/api'
import type { CreatedToken } from '../composables/useTokens'

const { tokens, pending, error, fetchTokens, createToken, revokeToken } = useTokens()

const name = ref('')
const created = ref<CreatedToken | null>(null)
const formError = ref('')
const busy = ref(false)

onMounted(() => fetchTokens())

async function onCreate(): Promise<void> {
  formError.value = ''
  created.value = null
  busy.value = true
  try {
    created.value = await createToken(name.value.trim())
    name.value = ''
  } catch (err) {
    formError.value = (err as ApiError).message
  } finally {
    busy.value = false
  }
}

async function onRevoke(id: number): Promise<void> {
  formError.value = ''
  busy.value = true
  try {
    await revokeToken(id)
    if (created.value?.id === id) created.value = null
  } catch (err) {
    formError.value = (err as ApiError).message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="rounded-xl border border-slate-800 bg-slate-900 p-6">
    <h2 class="mb-4 font-semibold text-teal-300">API keys</h2>

    <div
      v-if="created"
      class="mb-4 rounded-md border border-teal-500/40 bg-teal-950/40 p-3"
    >
      <p class="mb-1 text-sm font-semibold text-teal-200">
        Copy your new key now — it won't be shown again.
      </p>
      <code class="block break-all rounded bg-slate-950 px-2 py-1.5 text-xs text-teal-300">
        {{ created.token }}
      </code>
    </div>

    <p v-if="error" class="mb-3 text-sm text-rose-400">{{ error }}</p>
    <p v-else-if="pending && tokens.length === 0" class="mb-3 text-sm text-slate-400">
      Loading keys…
    </p>

    <ul v-if="tokens.length" class="mb-5 space-y-2">
      <li
        v-for="token in tokens"
        :key="token.id"
        class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-slate-800 bg-slate-950 px-3 py-2"
      >
        <div>
          <span class="text-sm text-slate-100">{{ token.name }}</span>
          <span
            v-if="token.current"
            class="ml-2 rounded bg-teal-950/60 px-1.5 py-0.5 text-xs text-teal-300"
          >
            this session
          </span>
          <p class="text-xs text-slate-500">
            {{
              token.last_used_at
                ? `last used ${new Date(token.last_used_at).toLocaleString()}`
                : 'never used'
            }}
          </p>
        </div>
        <button
          :disabled="busy"
          class="rounded-md border border-slate-700 px-2 py-1 text-xs text-slate-300 transition hover:border-rose-400/60 hover:text-rose-300 disabled:opacity-50"
          @click="onRevoke(token.id)"
        >
          {{ token.current ? 'Revoke (logs you out)' : 'Revoke' }}
        </button>
      </li>
    </ul>

    <form class="flex gap-2" novalidate @submit.prevent="onCreate">
      <input
        v-model="name"
        type="text"
        placeholder="Key name, e.g. iOS app"
        class="block w-full max-w-xs rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-600 focus:border-teal-400 focus:outline-none"
      />
      <button
        type="submit"
        :disabled="busy || !name.trim()"
        class="rounded-md bg-teal-500 px-3 py-1.5 text-sm font-semibold text-slate-950 transition hover:bg-teal-400 disabled:cursor-not-allowed disabled:opacity-50"
      >
        Create key
      </button>
    </form>
    <p v-if="formError" class="mt-3 text-sm text-rose-400">{{ formError }}</p>
  </section>
</template>
