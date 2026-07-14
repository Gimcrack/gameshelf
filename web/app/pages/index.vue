<script setup lang="ts">
import type { LibraryFilters, LibrarySort } from '../utils/library'

const { user, logout, fetchUser } = useAuth()
const { entries, pending, error, fetchLibrary, removeManual } = useLibrary()

const isLoggingOut = ref(false)

const sort = ref<LibrarySort>('alpha')
const order = ref<'asc' | 'desc'>('asc')
const platform = ref<'' | 'steam' | 'gog'>('')
const genre = ref('')
const unplayed = ref(false)

const filters = computed<LibraryFilters>(() => ({
  sort: sort.value,
  order: order.value,
  ...(platform.value ? { platform: platform.value } : {}),
  ...(genre.value.trim() ? { genre: genre.value.trim() } : {}),
  ...(unplayed.value ? { unplayed: true } : {})
}))

onMounted(async () => {
  if (!user.value) {
    await fetchUser()
  }
  await fetchLibrary(filters.value)
})

watch(filters, () => fetchLibrary(filters.value))

async function onRemoveManual(gameId: number): Promise<void> {
  await removeManual(gameId)
  await fetchLibrary(filters.value)
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

    <section class="mb-6 flex flex-wrap items-end gap-4 text-sm text-slate-400">
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
        Platform
        <select
          v-model="platform"
          class="rounded-md border border-slate-700 bg-slate-900 px-2 py-1.5 text-slate-100 focus:border-teal-400 focus:outline-none"
        >
          <option value="">All</option>
          <option value="steam">Steam</option>
          <option value="gog">GOG</option>
        </select>
      </label>
      <label class="flex flex-col gap-1">
        Genre
        <input
          v-model="genre"
          type="text"
          placeholder="e.g. Puzzle"
          class="rounded-md border border-slate-700 bg-slate-900 px-2 py-1.5 text-slate-100 placeholder:text-slate-600 focus:border-teal-400 focus:outline-none"
        />
      </label>
      <label class="flex items-center gap-2 pb-1.5">
        <input v-model="unplayed" type="checkbox" class="accent-teal-500" />
        Unplayed only
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
          @remove-manual="onRemoveManual"
        />
      </div>
    </section>
  </main>
</template>
