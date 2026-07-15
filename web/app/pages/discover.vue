<script setup lang="ts">
import type { ApiError } from '../utils/api'
import type { DiscoverHit, DiscoverSort } from '../composables/useDiscover'

const { hits, pending, error, search, browse, addToLibrary, addToWishlist } = useDiscover()
const { rails, fetchSimilar, flagHit: flagSimilarHit } = useSimilarGames()
const { franchises, fetchFranchises, flagHit: flagFranchiseHit } = useFranchiseGaps()

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

onMounted(() => {
  loadBrowse()
  fetchSimilar()
  fetchFranchises()
})

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

async function run(igdbId: number, action: (id: number) => Promise<void>): Promise<boolean> {
  actionError.value = ''
  busyId.value = igdbId
  try {
    await action(igdbId)
    return true
  } catch (err) {
    actionError.value = (err as ApiError).message
    return false
  } finally {
    busyId.value = null
  }
}

async function onAddToLibrary(hit: DiscoverHit): Promise<void> {
  if (await run(hit.igdb_id, addToLibrary)) {
    flagSimilarHit(hit.igdb_id, { in_library: true, in_wishlist: false })
    flagFranchiseHit(hit.igdb_id, { in_library: true, in_wishlist: false })
  }
}

async function onAddToWishlist(hit: DiscoverHit): Promise<void> {
  if (await run(hit.igdb_id, addToWishlist)) {
    flagSimilarHit(hit.igdb_id, { in_wishlist: true })
    flagFranchiseHit(hit.igdb_id, { in_wishlist: true })
  }
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
      <DiscoverHitCard
        v-for="hit in hits"
        :key="hit.igdb_id"
        :hit="hit"
        :busy="busyId === hit.igdb_id"
        @add-to-library="onAddToLibrary"
        @add-to-wishlist="onAddToWishlist"
      />
    </div>

    <section v-if="!searching && rails.length" class="mt-10 space-y-8">
      <div v-for="rail in rails" :key="rail.seed.id">
        <h2 class="mb-3 text-sm font-semibold text-slate-300">
          Because you played <span class="text-teal-400">{{ rail.seed.title }}</span>
        </h2>
        <div class="grid grid-cols-[repeat(auto-fill,minmax(160px,1fr))] gap-4">
          <DiscoverHitCard
            v-for="hit in rail.similar"
            :key="hit.igdb_id"
            :hit="hit"
            :busy="busyId === hit.igdb_id"
            @add-to-library="onAddToLibrary"
            @add-to-wishlist="onAddToWishlist"
          />
        </div>
      </div>
    </section>

    <section v-if="!searching && franchises.length" class="mt-10 space-y-8">
      <div v-for="gap in franchises" :key="gap.franchise">
        <h2 class="mb-1 text-sm font-semibold text-slate-300">
          Complete the series: <span class="text-teal-400">{{ gap.franchise }}</span>
        </h2>
        <p class="mb-3 text-xs text-slate-500">
          You own: {{ gap.owned.map((g) => g.title).join(', ') }}
        </p>
        <div class="grid grid-cols-[repeat(auto-fill,minmax(160px,1fr))] gap-4">
          <DiscoverHitCard
            v-for="hit in gap.missing"
            :key="hit.igdb_id"
            :hit="hit"
            :busy="busyId === hit.igdb_id"
            @add-to-library="onAddToLibrary"
            @add-to-wishlist="onAddToWishlist"
          />
        </div>
      </div>
    </section>
  </main>
</template>
