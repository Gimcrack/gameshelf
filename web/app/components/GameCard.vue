<script setup lang="ts">
import {
  formatPlaytime,
  hasDisconnectedPlatform,
  hasManualEntry,
  type LibraryEntry
} from '../utils/library'

const props = defineProps<{ entry: LibraryEntry }>()
const emit = defineEmits<{ (e: 'remove-manual', gameId: number): void }>()

const disconnected = computed(() => hasDisconnectedPlatform(props.entry))
const manual = computed(() => hasManualEntry(props.entry))
const playtimeLabel = computed(() => formatPlaytime(props.entry.total_playtime_minutes))
</script>

<template>
  <article
    class="group flex flex-col overflow-hidden rounded-lg border border-slate-800 bg-slate-900 transition duration-200 hover:-translate-y-1 hover:border-teal-400/60 hover:shadow-lg hover:shadow-teal-500/10 motion-reduce:transition-none motion-reduce:hover:translate-y-0"
    :class="{ 'opacity-60 saturate-50': disconnected }"
  >
    <div class="relative">
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
    </div>
    <div class="px-3 pb-3 pt-2.5">
      <h3 class="mb-1 text-sm font-semibold leading-snug text-slate-100">{{ entry.title }}</h3>
      <p class="mb-1.5 text-xs text-teal-300/90">{{ playtimeLabel }}</p>
      <ul class="mb-1.5 flex flex-wrap gap-1.5 p-0">
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
      </ul>
      <p v-if="entry.genres.length" class="text-xs text-slate-500">{{ entry.genres.join(', ') }}</p>
      <button
        v-if="manual"
        class="mt-1.5 rounded border border-slate-700 px-1.5 py-0.5 text-[0.65rem] text-slate-400 transition hover:border-rose-400/60 hover:text-rose-300"
        @click="emit('remove-manual', entry.id)"
      >
        Remove from library
      </button>
    </div>
  </article>
</template>
