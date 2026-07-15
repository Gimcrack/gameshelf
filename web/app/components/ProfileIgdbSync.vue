<script setup lang="ts">
import type { ApiError } from '../utils/api'

const { syncIgdb } = useLibrary()

const busy = ref(false)
const message = ref('')
const error = ref('')

async function onSync(): Promise<void> {
  busy.value = true
  message.value = ''
  error.value = ''

  try {
    await syncIgdb()
    message.value = 'Sync queued — reload your library in a bit to see updates.'
  } catch (err) {
    error.value = (err as ApiError).message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="rounded-xl border border-slate-800 bg-slate-900 p-6">
    <h2 class="mb-4 font-semibold text-teal-300">IGDB sync</h2>
    <p class="mb-4 text-sm text-slate-400">
      Re-match unmatched games and refresh IGDB data (genres, ESRB rating, multiplayer flags, time to
      beat) for your whole library.
    </p>

    <button
      :disabled="busy"
      class="rounded-md border border-teal-500/60 px-3 py-1.5 text-sm text-teal-300 transition hover:border-teal-400 hover:text-teal-200 disabled:cursor-not-allowed disabled:opacity-50"
      @click="onSync"
    >
      {{ busy ? 'Queuing…' : 'Sync all games from IGDB' }}
    </button>

    <p v-if="message" class="mt-3 text-sm text-teal-300">{{ message }}</p>
    <p v-if="error" class="mt-3 text-sm text-rose-400">{{ error }}</p>
  </section>
</template>
