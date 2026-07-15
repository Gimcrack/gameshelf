<script setup lang="ts">
import type { DiscoverHit } from '../composables/useDiscover'

defineProps<{ hit: DiscoverHit; busy: boolean }>()
const emit = defineEmits<{
  (e: 'add-to-library', hit: DiscoverHit): void
  (e: 'add-to-wishlist', hit: DiscoverHit): void
}>()
</script>

<template>
  <article class="flex flex-col overflow-hidden rounded-lg border border-slate-800 bg-slate-900">
    <img
      v-if="hit.cover_url"
      :src="hit.cover_url"
      :alt="hit.title"
      loading="lazy"
      class="block aspect-[3/4] w-full object-cover"
    />
    <div
      v-else
      class="flex aspect-[3/4] items-center justify-center bg-gradient-to-b from-slate-800 to-slate-900 p-2 text-center text-sm text-slate-400"
    >
      {{ hit.title }}
    </div>
    <div class="flex flex-1 flex-col px-3 pb-3 pt-2.5">
      <h3 class="mb-1 text-sm font-semibold leading-snug text-slate-100">{{ hit.title }}</h3>
      <p class="mb-1.5 text-xs text-slate-500">
        <span v-if="hit.rating !== null" class="text-teal-300">★ {{ hit.rating }}</span>
        <span v-if="hit.rating !== null && hit.release_date"> · </span>
        <span v-if="hit.release_date">{{ hit.release_date.slice(0, 4) }}</span>
      </p>
      <p v-if="hit.genres.length" class="mb-2 text-xs text-slate-500">
        {{ hit.genres.join(', ') }}
      </p>
      <div class="mt-auto flex flex-wrap gap-1.5">
        <span
          v-if="hit.in_library"
          class="rounded bg-teal-950/60 px-1.5 py-0.5 text-[0.65rem] uppercase tracking-wide text-teal-300"
        >
          In library
        </span>
        <template v-else>
          <button
            :disabled="busy"
            class="rounded bg-teal-500 px-2 py-1 text-xs font-semibold text-slate-950 transition hover:bg-teal-400 disabled:opacity-50"
            @click="emit('add-to-library', hit)"
          >
            Add to library
          </button>
          <span
            v-if="hit.in_wishlist"
            class="rounded bg-teal-950/60 px-1.5 py-0.5 text-[0.65rem] uppercase tracking-wide text-teal-300"
          >
            Wishlisted
          </span>
          <button
            v-else
            :disabled="busy"
            class="rounded border border-slate-700 px-2 py-1 text-xs text-slate-400 transition hover:border-teal-400/60 hover:text-teal-300 disabled:opacity-50"
            @click="emit('add-to-wishlist', hit)"
          >
            Wishlist
          </button>
        </template>
      </div>
    </div>
  </article>
</template>
