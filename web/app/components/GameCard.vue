<script setup lang="ts">
import {
  deckStatusLabel,
  formatPlaytime,
  hasDisconnectedPlatform,
  hasManualEntry,
  nextRating,
  ratingStars,
  showsPlaytime,
  showsRating,
  type LibraryEntry
} from '../utils/library'
import type { Collection } from '../composables/useCollections'

const props = defineProps<{ entry: LibraryEntry; manualCollections: Collection[] }>()
const emit = defineEmits<{
  (e: 'remove-manual', gameId: number): void
  (e: 'add-to-collection', collectionId: number, gameId: number): void
  (e: 'toggle-hidden', gameId: number, hidden: boolean): void
  (e: 'set-rating', gameId: number, rating: number | null): void
  (e: 'promote-to-owned', igdbId: number): void
  (e: 'remove-from-wishlist', gameId: number): void
}>()

const disconnected = computed(() => hasDisconnectedPlatform(props.entry))
const manual = computed(() => hasManualEntry(props.entry))
const showPlaytime = computed(() => showsPlaytime(props.entry))
const showRating = computed(() => showsRating(props.entry))
// T60/V58: attribution for shared entries — the family member's persona_name.
const sharedBy = computed(() => props.entry.platforms.find((p) => p.shared_by)?.shared_by ?? null)
const playtimeLabel = computed(() => formatPlaytime(props.entry.total_playtime_minutes))
const stars = computed(() => ratingStars(props.entry.rating))
const selectedCollectionId = ref<number | null>(null)

function onAddToCollection(): void {
  if (selectedCollectionId.value === null) return
  emit('add-to-collection', selectedCollectionId.value, props.entry.id)
}
</script>

<template>
  <article
    class="group flex flex-col overflow-hidden rounded-lg border border-slate-800 bg-slate-900 transition duration-200 hover:-translate-y-1 hover:border-teal-400/60 hover:shadow-lg hover:shadow-teal-500/10 motion-reduce:transition-none motion-reduce:hover:translate-y-0"
    :class="{ 'opacity-60 saturate-50': disconnected || entry.hidden }"
  >
    <NuxtLink :to="`/games/${entry.id}`" class="relative block">
      <img
        v-if="entry.cover_url"
        :src="entry.cover_url"
        :alt="entry.title"
        loading="lazy"
        class="block aspect-[3/4] w-full object-cover"
      />
      <div
        v-else
        class="flex aspect-[3/4] items-center justify-center bg-gradient-to-b from-slate-800 to-slate-900 p-2 text-center text-sm text-slate-400"
      >
        {{ entry.title }}
      </div>
    </NuxtLink>
    <div class="px-3 pb-3 pt-2.5">
      <h3 class="mb-1 text-sm font-semibold leading-snug text-slate-100">
        <NuxtLink :to="`/games/${entry.id}`" class="hover:text-teal-300">{{ entry.title }}</NuxtLink>
      </h3>
      <p v-if="showPlaytime" class="mb-1.5 text-xs text-teal-300/90">{{ playtimeLabel }}</p>
      <div v-if="showRating" class="mb-1.5 flex gap-0.5" role="group" aria-label="Rating">
        <button
          v-for="star in stars"
          :key="star.value"
          type="button"
          class="text-sm leading-none transition hover:text-amber-300 focus-visible:outline focus-visible:outline-1 focus-visible:outline-offset-1 focus-visible:outline-teal-400"
          :class="star.filled ? 'text-amber-400' : 'text-slate-600'"
          :aria-label="`Rate ${star.value} star${star.value === 1 ? '' : 's'}`"
          :aria-pressed="star.filled"
          @click="emit('set-rating', entry.id, nextRating(entry.rating, star.value))"
        >
          {{ star.filled ? '★' : '☆' }}
        </button>
      </div>
      <ul class="mb-1.5 flex flex-wrap gap-1.5 p-0">
        <li
          v-if="entry.library_status === 'wishlist'"
          class="rounded bg-violet-950/60 px-1.5 py-0.5 text-[0.65rem] uppercase tracking-wide text-violet-300"
        >
          Wishlist
        </li>
        <li
          v-if="entry.library_status === 'shared'"
          class="rounded bg-indigo-950/60 px-1.5 py-0.5 text-[0.65rem] uppercase tracking-wide text-indigo-300"
        >
          Shared<span v-if="sharedBy"> · via {{ sharedBy }}</span>
        </li>
        <li
          v-for="p in entry.platforms"
          :key="p.platform"
          class="rounded bg-teal-950/60 px-1.5 py-0.5 text-[0.65rem] uppercase tracking-wide text-teal-300"
          :class="{
            'bg-amber-950/60 text-amber-300': p.connection_status === 'disconnected'
          }"
        >
          {{ p.platform }}<span v-if="p.connection_status === 'disconnected'"> · disconnected</span>
        </li>
        <li
          v-for="p in entry.platforms.filter((p) => p.deck_status)"
          :key="`${p.platform}-deck`"
          class="rounded bg-slate-800 px-1.5 py-0.5 text-[0.65rem] uppercase tracking-wide text-slate-400"
          :class="{ 'bg-teal-950/60 text-teal-300': p.deck_status === 'verified' }"
        >
          {{ deckStatusLabel(p.deck_status!) }}
        </li>
        <li
          v-if="entry.achievements_summary"
          class="rounded bg-slate-800 px-1.5 py-0.5 text-[0.65rem] uppercase tracking-wide text-slate-400"
        >
          🏆 {{ entry.achievements_summary.unlocked }}/{{ entry.achievements_summary.total }}
        </li>
        <li
          v-if="entry.vr_supported"
          class="rounded bg-slate-800 px-1.5 py-0.5 text-[0.65rem] uppercase tracking-wide text-teal-300"
        >
          VR
        </li>
      </ul>
      <p v-if="entry.genres.length" class="text-xs text-slate-500">{{ entry.genres.join(', ') }}</p>
      <div class="mt-1.5 flex flex-wrap gap-1.5">
        <button
          v-if="entry.library_status === 'wishlist' && entry.igdb_id !== null"
          class="rounded border border-teal-500/60 px-1.5 py-0.5 text-[0.65rem] text-teal-300 transition hover:border-teal-400 hover:text-teal-200"
          @click="emit('promote-to-owned', entry.igdb_id!)"
        >
          I own this
        </button>
        <button
          v-if="entry.library_status === 'wishlist'"
          class="rounded border border-slate-700 px-1.5 py-0.5 text-[0.65rem] text-slate-400 transition hover:border-rose-400/60 hover:text-rose-300"
          @click="emit('remove-from-wishlist', entry.id)"
        >
          Remove from wishlist
        </button>
        <button
          v-if="manual"
          class="rounded border border-slate-700 px-1.5 py-0.5 text-[0.65rem] text-slate-400 transition hover:border-rose-400/60 hover:text-rose-300"
          @click="emit('remove-manual', entry.id)"
        >
          Remove from library
        </button>
        <button
          class="rounded border border-slate-700 px-1.5 py-0.5 text-[0.65rem] text-slate-400 transition hover:border-teal-400/60 hover:text-teal-300"
          @click="emit('toggle-hidden', entry.id, !entry.hidden)"
        >
          {{ entry.hidden ? 'Unhide' : 'Hide' }}
        </button>
      </div>
      <div v-if="manualCollections.length" class="mt-1.5 flex gap-1">
        <select
          v-model="selectedCollectionId"
          class="min-w-0 flex-1 rounded border border-slate-700 bg-slate-950 px-1 py-0.5 text-[0.65rem] text-slate-300 focus:border-teal-400 focus:outline-none"
        >
          <option :value="null" disabled>Add to collection…</option>
          <option v-for="c in manualCollections" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
        <button
          :disabled="selectedCollectionId === null"
          class="rounded border border-slate-700 px-1.5 py-0.5 text-[0.65rem] text-slate-400 transition hover:border-teal-400/60 hover:text-teal-300 disabled:cursor-not-allowed disabled:opacity-50"
          @click="onAddToCollection"
        >
          Add
        </button>
      </div>
    </div>
  </article>
</template>
