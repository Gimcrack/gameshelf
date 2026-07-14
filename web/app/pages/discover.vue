<script setup lang="ts">
import type { ApiError } from '../utils/api'
import type { DiscoverHit, DiscoverSort } from '../composables/useDiscover'

const { hits, pending, error, search, browse, addToLibrary, addToWishlist } = useDiscover()

const q = ref('')
const genre = ref('')
const sort = ref<DiscoverSort>('popularity')
const page = ref(1)
const actionError = ref('')
const busyId = ref<number | null>(null)

const searching = computed(() => q.value.trim().length >= 2)

function loadBrowse(): Promise<void> {
  return browse({ genre: genre.value, sort: sort.value, page: page.value })
}

onMounted(() => loadBrowse())

let debounce: ReturnType<typeof setTimeout> | undefined
watch(q, () => {
  clearTimeout(debounce)
  debounce = setTimeout(() => {
    if (searching.value) {
      search(q.value.trim())
    } else {
      page.value = 1
      loadBrowse()
    }
  }, 300)
})

watch([genre, sort], () => {
  page.value = 1
  if (!searching.value) loadBrowse()
})

watch(page, () => {
  if (!searching.value) loadBrowse()
})

async function run(igdbId: number, action: (id: number) => Promise<void>): Promise<void> {
  actionError.value = ''
  busyId.value = igdbId
  try {
    await action(igdbId)
  } catch (err) {
    actionError.value = (err as ApiError).message
  } finally {
    busyId.value = null
  }
}

function onAddToLibrary(hit: DiscoverHit): Promise<void> {
  return run(hit.igdb_id, addToLibrary)
}

function onAddToWishlist(hit: DiscoverHit): Promise<void> {
  return run(hit.igdb_id, addToWishlist)
}
</script>

<template>
  <main class="mx-auto max-w-6xl px-6 py-8">
    <header class="mb-6 flex items-center justify-between border-b border-slate-800 pb-4">
      <h1 class="text-2xl font-bold tracking-tight">
        <span class="text-teal-400">Discover</span> games
      </h1>
      <NuxtLink
        to="/"
        class="rounded-md border border-slate-700 px-3 py-1.5 text-sm text-slate-300 transition hover:border-teal-400/60 hover:text-teal-300"
      >
        Back to library
      </NuxtLink>
    </header>

    <div class="mb-6 flex flex-wrap items-center gap-3">
      <input
        v-model="q"
        type="search"
        placeholder="Search the IGDB catalogue…"
        class="w-full max-w-sm rounded-md border border-slate-700 bg-slate-900 px-3 py-1.5 text-sm text-slate-100 placeholder:text-slate-500 focus:border-teal-400/60 focus:outline-none"
      />
      <template v-if="!searching">
        <input
          v-model.lazy="genre"
          type="text"
          placeholder="Genre (e.g. Indie)"
          class="rounded-md border border-slate-700 bg-slate-900 px-3 py-1.5 text-sm text-slate-100 placeholder:text-slate-500 focus:border-teal-400/60 focus:outline-none"
        />
        <select
          v-model="sort"
          class="rounded-md border border-slate-700 bg-slate-900 px-3 py-1.5 text-sm text-slate-100 focus:border-teal-400/60 focus:outline-none"
        >
          <option value="popularity">Popular</option>
          <option value="rating">Top rated</option>
          <option value="release">Newest</option>
        </select>
        <div class="ml-auto flex items-center gap-2 text-sm text-slate-400">
          <button
            :disabled="page === 1 || pending"
            class="rounded border border-slate-700 px-2 py-1 transition hover:border-teal-400/60 hover:text-teal-300 disabled:cursor-not-allowed disabled:opacity-40"
            @click="page -= 1"
          >
            ‹ Prev
          </button>
          <span>Page {{ page }}</span>
          <button
            :disabled="pending || hits.length === 0"
            class="rounded border border-slate-700 px-2 py-1 transition hover:border-teal-400/60 hover:text-teal-300 disabled:cursor-not-allowed disabled:opacity-40"
            @click="page += 1"
          >
            Next ›
          </button>
        </div>
      </template>
    </div>

    <p v-if="actionError" class="mb-4 text-sm text-rose-400">{{ actionError }}</p>
    <p v-if="error" class="text-rose-400">{{ error }}</p>
    <p v-else-if="pending && hits.length === 0" class="text-slate-400">Loading games…</p>
    <p v-else-if="hits.length === 0" class="text-slate-400">
      No games found. Try another search or genre.
    </p>

    <div v-else class="grid grid-cols-[repeat(auto-fill,minmax(160px,1fr))] gap-4">
      <article
        v-for="hit in hits"
        :key="hit.igdb_id"
        class="flex flex-col overflow-hidden rounded-lg border border-slate-800 bg-slate-900"
      >
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
                :disabled="busyId === hit.igdb_id"
                class="rounded bg-teal-500 px-2 py-1 text-xs font-semibold text-slate-950 transition hover:bg-teal-400 disabled:opacity-50"
                @click="onAddToLibrary(hit)"
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
                :disabled="busyId === hit.igdb_id"
                class="rounded border border-slate-700 px-2 py-1 text-xs text-slate-400 transition hover:border-teal-400/60 hover:text-teal-300 disabled:opacity-50"
                @click="onAddToWishlist(hit)"
              >
                Wishlist
              </button>
            </template>
          </div>
        </div>
      </article>
    </div>
  </main>
</template>
