<script setup lang="ts">
import type { DeckStatus, LibraryFilters, LibrarySort } from '../utils/library'
import { splitGameModeSelection } from '../utils/facets'

const { user, logout, fetchUser } = useAuth()
const { entries, facets, pending, error, fetchLibrary, fetchFacets, removeManual, updateMeta } = useLibrary()
const { collections, fetchCollections, addGame } = useCollections()

const manualCollections = computed(() => collections.value.filter((c) => c.type === 'manual'))

const isLoggingOut = ref(false)

const q = ref('')
const sort = ref<LibrarySort>('alpha')
const order = ref<'asc' | 'desc'>('asc')
const platforms = ref<string[]>([])
const genres = ref<string[]>([])
const themes = ref<string[]>([])
const keywords = ref<string[]>([])
const gameModes = ref<string[]>([])
const unplayed = ref(false)
const showHidden = ref(false)
const deckStatuses = ref<DeckStatus[]>([])
const esrb = ref('')

const filters = computed<LibraryFilters>(() => {
  // V40: bool-backed game-mode labels route through the V32 flag params.
  const { flags, gameModes: gameModeValues } = splitGameModeSelection(gameModes.value)

  return {
    sort: sort.value,
    order: order.value,
    ...(q.value.trim() ? { q: q.value.trim() } : {}),
    ...(platforms.value.length ? { platform: platforms.value.join(',') } : {}),
    ...(genres.value.length ? { genre: genres.value.join(',') } : {}),
    ...(themes.value.length ? { theme: themes.value.join(',') } : {}),
    ...(keywords.value.length ? { keyword: keywords.value.join(',') } : {}),
    ...(gameModeValues.length ? { gameMode: gameModeValues.join(',') } : {}),
    ...flags,
    ...(unplayed.value ? { unplayed: true } : {}),
    ...(showHidden.value ? { includeHidden: true } : {}),
    ...(deckStatuses.value.length ? { deckStatus: deckStatuses.value } : {}),
    ...(esrb.value ? { esrb: esrb.value } : {})
  }
})

onMounted(async () => {
  if (!user.value) {
    await fetchUser()
  }
  await fetchLibrary(filters.value)
  await fetchCollections()
  await fetchFacets()
})

watch(filters, () => fetchLibrary(filters.value))

async function onRemoveManual(gameId: number): Promise<void> {
  await removeManual(gameId)
  await fetchLibrary(filters.value)
  await fetchFacets()
}

async function onAddToCollection(collectionId: number, gameId: number): Promise<void> {
  await addGame(collectionId, gameId)
}

async function onToggleHidden(gameId: number, hidden: boolean): Promise<void> {
  await updateMeta(gameId, { hidden })
  await fetchLibrary(filters.value)
  await fetchFacets()
}

async function onLogout(): Promise<void> {
  isLoggingOut.value = true
  try {
    await logout()
  } finally {
    isLoggingOut.value = false
    await navigateTo('/login')
  }
}
</script>

<template>
  <main class="mx-auto max-w-6xl px-6 py-8">
    <header
      class="mb-6 flex items-center justify-between border-b border-slate-800 pb-4"
    >
      <h1 class="text-2xl font-bold tracking-tight">
        Game<span class="text-teal-400">Shelf</span>
      </h1>
      <div v-if="user" class="flex items-center gap-3">
        <NuxtLink to="/discover" class="text-sm text-teal-400 hover:text-teal-300">Discover</NuxtLink>
        <NuxtLink to="/wishlist" class="text-sm text-teal-400 hover:text-teal-300">Wishlist</NuxtLink>
        <NuxtLink to="/stats" class="text-sm text-teal-400 hover:text-teal-300">Stats</NuxtLink>
        <NuxtLink to="/profile" class="text-sm text-teal-400 hover:text-teal-300">Profile</NuxtLink>
        <span class="text-sm text-slate-400">{{ user.email }}</span>
        <button
          :disabled="isLoggingOut"
          class="rounded-md border border-slate-700 px-3 py-1.5 text-sm text-slate-300 transition hover:border-teal-400/60 hover:text-teal-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-400 disabled:cursor-not-allowed disabled:opacity-50"
          @click="onLogout"
        >
          {{ isLoggingOut ? 'Logging out…' : 'Log out' }}
        </button>
      </div>
    </header>

    <div class="flex gap-6">
      <LibraryFilterSidebar
        :facets="facets"
        v-model:platforms="platforms"
        v-model:genres="genres"
        v-model:themes="themes"
        v-model:keywords="keywords"
        v-model:game-modes="gameModes"
        v-model:deck-statuses="deckStatuses"
        v-model:esrb="esrb"
        v-model:unplayed="unplayed"
        v-model:show-hidden="showHidden"
      />

      <div class="min-w-0 flex-1">
        <section class="mb-6 flex flex-wrap items-end gap-4 text-sm text-slate-400">
          <label class="flex min-w-64 flex-1 flex-col gap-1">
            Search
            <input
              v-model="q"
              type="text"
              placeholder="Search your library…"
              class="rounded-md border border-slate-700 bg-slate-900 px-2 py-1.5 text-slate-100 placeholder:text-slate-600 focus:border-teal-400 focus:outline-none"
            />
          </label>
          <label class="flex flex-col gap-1">
            Sort
            <select
              v-model="sort"
              class="rounded-md border border-slate-700 bg-slate-900 px-2 py-1.5 text-slate-100 focus:border-teal-400 focus:outline-none"
            >
              <option value="alpha">Title</option>
              <option value="playtime">Playtime</option>
              <option value="last_played">Last played</option>
              <option value="added">Date added</option>
            </select>
          </label>
          <label class="flex flex-col gap-1">
            Order
            <select
              v-model="order"
              class="rounded-md border border-slate-700 bg-slate-900 px-2 py-1.5 text-slate-100 focus:border-teal-400 focus:outline-none"
            >
              <option value="asc">Ascending</option>
              <option value="desc">Descending</option>
            </select>
          </label>
        </section>

        <section>
          <p v-if="error" class="text-rose-400">{{ error }}</p>
          <p v-else-if="pending && entries.length === 0" class="text-slate-400">
            Loading library…
          </p>
          <p v-else-if="entries.length === 0" class="text-slate-400">
            Your library is empty. Connect a platform to import your games.
          </p>
          <div
            v-else
            class="grid grid-cols-[repeat(auto-fill,minmax(160px,1fr))] gap-4"
          >
            <GameCard
              v-for="entry in entries"
              :key="entry.id"
              :entry="entry"
              :manual-collections="manualCollections"
              @remove-manual="onRemoveManual"
              @add-to-collection="onAddToCollection"
              @toggle-hidden="onToggleHidden"
            />
          </div>
        </section>
      </div>
    </div>
  </main>
</template>
