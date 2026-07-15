<script setup lang="ts">
import { apiFetch, type ApiError } from '../utils/api'
import type { DiscoverHit } from '../composables/useDiscover'

const props = defineProps<{ gameId: number; initialQuery: string }>()
// Rematch can repoint to a different canonical game id (or delete the old
// provisional row entirely) — the caller needs the new id to navigate to.
const emit = defineEmits<{ (e: 'matched', newGameId: number): void }>()

const { rematch } = useLibrary()

const open = ref(false)
const q = ref(props.initialQuery)
const hits = ref<DiscoverHit[]>([])
const pending = ref(false)
const applying = ref(false)
const error = ref<string | null>(null)

function toggle(): void {
  open.value = !open.value
  if (open.value && hits.value.length === 0) {
    search()
  }
}

async function search(): Promise<void> {
  if (!q.value.trim()) return

  pending.value = true
  error.value = null

  try {
    hits.value = await apiFetch<DiscoverHit[]>(`/api/discover/search?q=${encodeURIComponent(q.value.trim())}`)
  } catch (err) {
    error.value = (err as ApiError).message
  } finally {
    pending.value = false
  }
}

async function apply(igdbId: number): Promise<void> {
  applying.value = true
  error.value = null

  try {
    const updated = await rematch(props.gameId, igdbId)
    open.value = false
    emit('matched', updated.id)
  } catch (err) {
    error.value = (err as ApiError).message
  } finally {
    applying.value = false
  }
}
</script>

<template>
  <div>
    <button
      class="rounded-md border border-slate-700 px-3 py-1.5 text-sm text-slate-300 transition hover:border-teal-400/60 hover:text-teal-300"
      @click="toggle"
    >
      {{ open ? 'Cancel fix match' : 'Fix match' }}
    </button>

    <div v-if="open" class="mt-3 space-y-3 rounded-md border border-slate-800 bg-slate-900 p-3">
      <form class="flex gap-2" @submit.prevent="search">
        <input
          v-model="q"
          type="text"
          placeholder="Search IGDB title…"
          class="flex-1 rounded-md border border-slate-700 bg-slate-950 px-2 py-1.5 text-sm text-slate-100 placeholder:text-slate-600 focus:border-teal-400 focus:outline-none"
        />
        <button
          type="submit"
          :disabled="pending"
          class="rounded-md border border-teal-500/60 px-3 py-1.5 text-sm text-teal-300 transition hover:border-teal-400 disabled:cursor-not-allowed disabled:opacity-50"
        >
          {{ pending ? 'Searching…' : 'Search' }}
        </button>
      </form>

      <p v-if="error" class="text-sm text-rose-400">{{ error }}</p>
      <p v-else-if="pending" class="text-sm text-slate-400">Searching…</p>
      <p v-else-if="hits.length === 0" class="text-sm text-slate-400">No results.</p>

      <ul v-else class="grid grid-cols-[repeat(auto-fill,minmax(100px,1fr))] gap-2 p-0">
        <li v-for="hit in hits" :key="hit.igdb_id" class="list-none">
          <button
            type="button"
            :disabled="applying"
            class="w-full overflow-hidden rounded-md border border-slate-700 text-left transition hover:border-teal-400/60 disabled:cursor-not-allowed disabled:opacity-50"
            @click="apply(hit.igdb_id)"
          >
            <img
              v-if="hit.cover_url"
              :src="hit.cover_url"
              :alt="hit.title"
              loading="lazy"
              class="block aspect-[3/4] w-full object-cover"
            />
            <span class="block px-1.5 py-1 text-[0.7rem] text-slate-300">{{ hit.title }}</span>
          </button>
        </li>
      </ul>
    </div>
  </div>
</template>
