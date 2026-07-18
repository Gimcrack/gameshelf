<script setup lang="ts">
import type { DeckStatus, LibraryFilters, LibrarySort, LibraryStatus } from '../utils/library'
import { libraryFiltersToPreset } from '../utils/library'
import { splitGameModeSelection } from '../utils/facets'

const { user, logout, fetchUser } = useAuth()
const {
  entries,
  facets,
  pending,
  error,
  fetchLibrary,
  fetchFacets,
  removeManual,
  updateMeta,
  promoteToOwned,
  removeFromWishlist
} = useLibrary()
const { collections, system, fetchCollections, addGame, createFilterCollection } = useCollections()

const manualCollections = computed(() => collections.value.filter((c) => c.type === 'manual'))
// T44: filter collections are the ones the library picker can re-apply.
const filterCollections = computed(() => collections.value.filter((c) => c.type === 'filter'))
const selectedCollection = ref('')

const isLoggingOut = ref(false)
// V62: filter sidebar collapses into this drawer below `md`.
const filterDrawerOpen = ref(false)

function closeFilterDrawer(): void {
  filterDrawerOpen.value = false
}

function onDrawerKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') closeFilterDrawer()
}

onMounted(() => window.addEventListener('keydown', onDrawerKeydown))
onUnmounted(() => window.removeEventListener('keydown', onDrawerKeydown))

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
const esrb = ref<string[]>([])
const libraryStatuses = ref<LibraryStatus[]>([])
const ratings = ref<string[]>([])
const vr = ref(false)

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
    ...(esrb.value.length ? { esrb: esrb.value } : {}),
    ...(libraryStatuses.value.length ? { libraryStatus: libraryStatuses.value } : {}),
    ...(ratings.value.length ? { rating: ratings.value } : {}),
    ...(vr.value ? { vr: true } : {}),
    // T44: a selected collection expands server-side; explicit filters above
    // still win (LibraryController.resolveCollection).
    ...(selectedCollection.value ? { collection: selectedCollection.value } : {})
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

// T44: name-prompt → save the active sidebar filters as a smart collection,
// then select it so the grid reflects the saved preset.
async function onSaveCollection(): Promise<void> {
  const name = window.prompt('Name this collection')?.trim()
  if (!name) return

  try {
    const created = await createFilterCollection(name, libraryFiltersToPreset(filters.value))
    selectedCollection.value = String(created.id)
  } catch (err) {
    error.value = (err as { message?: string }).message ?? 'Could not save collection.'
  }
}

async function onToggleHidden(gameId: number, hidden: boolean): Promise<void> {
  await updateMeta(gameId, { hidden })
  await fetchLibrary(filters.value)
  await fetchFacets()
}

async function onSetRating(gameId: number, rating: number | null): Promise<void> {
  await updateMeta(gameId, { rating })
  await fetchLibrary(filters.value)
}

// T52: wishlist promote/remove relocated from the removed /wishlist page.
async function onPromoteToOwned(igdbId: number): Promise<void> {
  await promoteToOwned(igdbId)
  await fetchLibrary(filters.value)
  await fetchFacets()
}

async function onRemoveFromWishlist(gameId: number): Promise<void> {
  await removeFromWishlist(gameId)
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
      <h1 class="flex items-center gap-2.5 text-2xl font-bold tracking-tight">
        <BrandMark :size="30" />
        <BrandWordmark />
      </h1>
      <div v-if="user" class="flex items-center gap-3">
        <!-- V62: relocated to AppBottomNav below `md` - hidden here to avoid duplicate nav. -->
        <div class="hidden items-center gap-3 md:flex">
          <NuxtLink to="/discover" class="text-sm text-teal-400 hover:text-teal-300">Discover</NuxtLink>
          <NuxtLink to="/stats" class="text-sm text-teal-400 hover:text-teal-300">Stats</NuxtLink>
          <NuxtLink to="/profile" class="text-sm text-teal-400 hover:text-teal-300">Profile</NuxtLink>
        </div>
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
      <!-- V62: static sidebar only at `md`+; below that it collapses into the drawer. -->
      <LibraryFilterSidebar
        class="hidden md:flex"
        :facets="facets"
        v-model:platforms="platforms"
        v-model:genres="genres"
        v-model:themes="themes"
        v-model:keywords="keywords"
        v-model:game-modes="gameModes"
        v-model:deck-statuses="deckStatuses"
        v-model:esrb="esrb"
        v-model:library-statuses="libraryStatuses"
        v-model:ratings="ratings"
        v-model:unplayed="unplayed"
        v-model:show-hidden="showHidden"
        v-model:vr="vr"
        @save="onSaveCollection"
      />

      <div class="min-w-0 flex-1">
        <button
          type="button"
          class="mb-4 rounded-md border border-slate-700 px-3 py-1.5 text-sm text-slate-300 transition hover:border-teal-400/60 hover:text-teal-300 md:hidden"
          @click="filterDrawerOpen = true"
        >
          Filters
        </button>

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
          <label class="flex flex-col gap-1">
            Collection
            <select
              v-model="selectedCollection"
              class="rounded-md border border-slate-700 bg-slate-900 px-2 py-1.5 text-slate-100 focus:border-teal-400 focus:outline-none"
            >
              <option value="">All games</option>
              <optgroup v-if="system.length" label="Smart">
                <option v-for="c in system" :key="c.slug" :value="c.slug">{{ c.name }}</option>
              </optgroup>
              <optgroup v-if="filterCollections.length" label="Saved">
                <option v-for="c in filterCollections" :key="c.id" :value="String(c.id)">
                  {{ c.name }}
                </option>
              </optgroup>
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
              @set-rating="onSetRating"
              @promote-to-owned="onPromoteToOwned"
              @remove-from-wishlist="onRemoveFromWishlist"
            />
          </div>
        </section>
      </div>
    </div>

    <!-- V62: mobile filter drawer - closes on backdrop tap, Escape, or the close button. -->
    <Teleport to="body">
      <div v-if="filterDrawerOpen" class="fixed inset-0 z-50 md:hidden">
        <div class="absolute inset-0 bg-black/60" @click="closeFilterDrawer" />
        <div
          class="absolute inset-y-0 left-0 w-72 max-w-[85vw] overflow-y-auto border-r border-slate-800 bg-slate-950 p-4 shadow-xl"
        >
          <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-semibold text-slate-100">Filters</h2>
            <button
              type="button"
              class="rounded-md px-2 py-1 text-slate-400 hover:text-teal-300"
              aria-label="Close filters"
              @click="closeFilterDrawer"
            >
              ✕
            </button>
          </div>

          <LibraryFilterSidebar
            class="w-full"
            :facets="facets"
            v-model:platforms="platforms"
            v-model:genres="genres"
            v-model:themes="themes"
            v-model:keywords="keywords"
            v-model:game-modes="gameModes"
            v-model:deck-statuses="deckStatuses"
            v-model:esrb="esrb"
            v-model:library-statuses="libraryStatuses"
            v-model:ratings="ratings"
            v-model:unplayed="unplayed"
            v-model:show-hidden="showHidden"
            @save="onSaveCollection"
          />
        </div>
      </div>
    </Teleport>
  </main>
</template>
