<script setup lang="ts">
import type { LibraryFilters, LibrarySort } from '../utils/library'

const { user, logout, fetchUser } = useAuth()
const { entries, pending, error, fetchLibrary } = useLibrary()

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
  <main class="library">
    <header class="library-header">
      <h1>GameShelf</h1>
      <div v-if="user" class="user-bar">
        <span class="user-email">{{ user.email }}</span>
        <button :disabled="isLoggingOut" @click="onLogout">
          {{ isLoggingOut ? 'Logging out…' : 'Log out' }}
        </button>
      </div>
    </header>

    <section class="controls">
      <label>
        Sort
        <select v-model="sort">
          <option value="alpha">Title</option>
          <option value="playtime">Playtime</option>
          <option value="last_played">Last played</option>
          <option value="added">Date added</option>
        </select>
      </label>
      <label>
        Order
        <select v-model="order">
          <option value="asc">Ascending</option>
          <option value="desc">Descending</option>
        </select>
      </label>
      <label>
        Platform
        <select v-model="platform">
          <option value="">All</option>
          <option value="steam">Steam</option>
          <option value="gog">GOG</option>
        </select>
      </label>
      <label>
        Genre
        <input v-model="genre" type="text" placeholder="e.g. Puzzle" />
      </label>
      <label class="checkbox">
        <input v-model="unplayed" type="checkbox" />
        Unplayed only
      </label>
    </section>

    <section class="library-body">
      <p v-if="error" class="error">{{ error }}</p>
      <p v-else-if="pending && entries.length === 0">Loading library…</p>
      <p v-else-if="entries.length === 0">
        Your library is empty. Connect a platform to import your games.
      </p>
      <div v-else class="grid">
        <GameCard v-for="entry in entries" :key="entry.id" :entry="entry" />
      </div>
    </section>
  </main>
</template>

<style scoped>
.library {
  max-width: 1080px;
  margin: 2rem auto;
  padding: 1.5rem;
  font-family: system-ui, sans-serif;
}

.library-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #ddd;
  padding-bottom: 1rem;
  margin-bottom: 1rem;
}

.user-bar {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.user-email {
  font-size: 0.9rem;
  color: #555;
}

button {
  padding: 0.4rem 0.8rem;
  cursor: pointer;
}

.controls {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  align-items: end;
  margin-bottom: 1.25rem;
  font-size: 0.85rem;
  color: #444;
}

.controls label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.controls label.checkbox {
  flex-direction: row;
  align-items: center;
}

.controls select,
.controls input[type='text'] {
  padding: 0.3rem 0.4rem;
}

.library-body {
  color: #555;
}

.error {
  color: #a33;
}

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 1rem;
}
</style>
