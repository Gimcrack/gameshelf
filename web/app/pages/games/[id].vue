<script setup lang="ts">
import type { Ref } from 'vue'
import {
  deckStatusLabel,
  formatPlaytime,
  hasDisconnectedPlatform,
  type GameStatus,
  type LibraryEntry
} from '../../utils/library'

const route = useRoute()
const gameId = computed(() => Number(route.params.id))

const { fetchGame, updateMeta } = useLibrary()

const entry: Ref<LibraryEntry | null> = ref(null)
const pending = ref(false)
const error = ref<string | null>(null)
const saving = ref(false)
const saveError = ref<string | null>(null)

const status = ref<GameStatus>('unplayed')
const tagsInput = ref('')
const notes = ref('')
const rating = ref<number | null>(null)
const hidden = ref(false)

function syncFormFromEntry(loaded: LibraryEntry): void {
  status.value = loaded.status
  tagsInput.value = loaded.tags.join(', ')
  notes.value = loaded.notes ?? ''
  rating.value = loaded.rating
  hidden.value = loaded.hidden
}

async function load(): Promise<void> {
  pending.value = true
  error.value = null

  try {
    entry.value = await fetchGame(gameId.value)
    syncFormFromEntry(entry.value)
  } catch (err) {
    error.value = (err as { message?: string }).message ?? 'Failed to load game.'
  } finally {
    pending.value = false
  }
}

onMounted(load)
watch(gameId, load)

async function onSave(): Promise<void> {
  saving.value = true
  saveError.value = null

  try {
    await updateMeta(gameId.value, {
      status: status.value,
      tags: tagsInput.value
        .split(',')
        .map((t) => t.trim())
        .filter((t) => t.length > 0),
      notes: notes.value.trim() ? notes.value.trim() : null,
      rating: rating.value,
      hidden: hidden.value
    })
    await load()
  } catch (err) {
    saveError.value = (err as { message?: string }).message ?? 'Failed to save changes.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <main class="mx-auto max-w-3xl px-6 py-8">
    <header class="mb-6 flex items-center justify-between border-b border-slate-800 pb-4">
      <h1 class="text-2xl font-bold tracking-tight">
        Game <span class="text-teal-400">detail</span>
      </h1>
      <NuxtLink
        to="/"
        class="rounded-md border border-slate-700 px-3 py-1.5 text-sm text-slate-300 transition hover:border-teal-400/60 hover:text-teal-300"
      >
        Back to library
      </NuxtLink>
    </header>

    <p v-if="error" class="text-rose-400">{{ error }}</p>
    <p v-else-if="pending && !entry" class="text-slate-400">Loading…</p>

    <div v-else-if="entry" class="grid grid-cols-1 gap-6 sm:grid-cols-[200px_1fr]">
      <img
        v-if="entry.cover_url"
        :src="entry.cover_url"
        :alt="entry.title"
        class="w-full rounded-lg border border-slate-800 object-cover"
      />
      <div
        v-else
        class="flex aspect-[3/4] items-center justify-center rounded-lg border border-slate-800 bg-gradient-to-b from-slate-800 to-slate-900 p-4 text-center text-slate-400"
      >
        {{ entry.title }}
      </div>

      <div>
        <h2 class="mb-2 text-xl font-semibold text-slate-100">{{ entry.title }}</h2>

        <div class="mb-4 flex flex-wrap gap-3 text-xs text-slate-400">
          <span v-if="entry.release_date">Released {{ entry.release_date }}</span>
          <span>{{ formatPlaytime(entry.total_playtime_minutes) }}</span>
          <span v-if="entry.time_to_beat_minutes !== null">
            Time to beat: {{ formatPlaytime(entry.time_to_beat_minutes) }}
          </span>
          <span v-if="hasDisconnectedPlatform(entry)" class="text-amber-300">
            Source disconnected
          </span>
        </div>

        <ul class="mb-4 flex flex-wrap gap-1.5 p-0">
          <li
            v-for="p in entry.platforms"
            :key="p.platform"
            class="rounded bg-teal-950/60 px-1.5 py-0.5 text-[0.65rem] uppercase tracking-wide text-teal-300"
            :class="{ 'bg-amber-950/60 text-amber-300': p.connection_status === 'disconnected' }"
          >
            {{ p.platform }} · {{ formatPlaytime(p.playtime_minutes) }}
            <span v-if="p.deck_status"> · {{ deckStatusLabel(p.deck_status) }}</span>
          </li>
        </ul>

        <div v-if="entry.esrb_rating || entry.multiplayer || entry.coop" class="mb-4 flex flex-wrap gap-1.5">
          <span
            v-if="entry.esrb_rating"
            class="rounded bg-slate-800 px-1.5 py-0.5 text-[0.65rem] uppercase tracking-wide text-slate-300"
          >
            ESRB {{ entry.esrb_rating }}
          </span>
          <span
            v-if="entry.multiplayer"
            class="rounded bg-slate-800 px-1.5 py-0.5 text-[0.65rem] uppercase tracking-wide text-slate-300"
          >
            Multiplayer
          </span>
          <span
            v-if="entry.coop"
            class="rounded bg-slate-800 px-1.5 py-0.5 text-[0.65rem] uppercase tracking-wide text-slate-300"
          >
            Co-op
          </span>
          <span
            v-if="entry.local_multiplayer"
            class="rounded bg-slate-800 px-1.5 py-0.5 text-[0.65rem] uppercase tracking-wide text-slate-300"
          >
            Local multiplayer
          </span>
          <span
            v-if="entry.local_coop"
            class="rounded bg-slate-800 px-1.5 py-0.5 text-[0.65rem] uppercase tracking-wide text-slate-300"
          >
            Local co-op
          </span>
        </div>

        <dl class="mb-6 space-y-2 text-xs text-slate-400">
          <div v-if="entry.genres.length">
            <dt class="text-slate-500">Genres</dt>
            <dd>{{ entry.genres.join(', ') }}</dd>
          </div>
          <div v-if="entry.themes.length">
            <dt class="text-slate-500">Themes</dt>
            <dd>{{ entry.themes.join(', ') }}</dd>
          </div>
          <div v-if="entry.keywords.length">
            <dt class="text-slate-500">Keywords</dt>
            <dd>{{ entry.keywords.join(', ') }}</dd>
          </div>
          <div v-if="entry.game_modes.length">
            <dt class="text-slate-500">Game modes</dt>
            <dd>{{ entry.game_modes.join(', ') }}</dd>
          </div>
        </dl>

        <form class="space-y-3 border-t border-slate-800 pt-4" @submit.prevent="onSave">
          <p v-if="saveError" class="text-rose-400">{{ saveError }}</p>

          <label class="flex flex-col gap-1 text-sm text-slate-400">
            Status
            <select
              v-model="status"
              class="rounded-md border border-slate-700 bg-slate-900 px-2 py-1.5 text-slate-100 focus:border-teal-400 focus:outline-none"
            >
              <option value="unplayed">Unplayed</option>
              <option value="playing">Playing</option>
              <option value="finished">Finished</option>
              <option value="abandoned">Abandoned</option>
            </select>
          </label>

          <label class="flex flex-col gap-1 text-sm text-slate-400">
            Tags (comma-separated)
            <input
              v-model="tagsInput"
              type="text"
              placeholder="e.g. co-op, replay"
              class="rounded-md border border-slate-700 bg-slate-900 px-2 py-1.5 text-slate-100 placeholder:text-slate-600 focus:border-teal-400 focus:outline-none"
            />
          </label>

          <label class="flex flex-col gap-1 text-sm text-slate-400">
            Rating
            <select
              v-model.number="rating"
              class="rounded-md border border-slate-700 bg-slate-900 px-2 py-1.5 text-slate-100 focus:border-teal-400 focus:outline-none"
            >
              <option :value="null">No rating</option>
              <option v-for="n in 5" :key="n" :value="n">{{ n }} / 5</option>
            </select>
          </label>

          <label class="flex flex-col gap-1 text-sm text-slate-400">
            Notes
            <textarea
              v-model="notes"
              rows="3"
              class="rounded-md border border-slate-700 bg-slate-900 px-2 py-1.5 text-slate-100 placeholder:text-slate-600 focus:border-teal-400 focus:outline-none"
            />
          </label>

          <label class="flex items-center gap-2 text-sm text-slate-400">
            <input v-model="hidden" type="checkbox" class="accent-teal-500" />
            Hidden from library
          </label>

          <button
            type="submit"
            :disabled="saving"
            class="rounded-md border border-teal-500/60 px-3 py-1.5 text-sm text-teal-300 transition hover:border-teal-400 hover:text-teal-200 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {{ saving ? 'Saving…' : 'Save changes' }}
          </button>
        </form>
      </div>
    </div>
  </main>
</template>
