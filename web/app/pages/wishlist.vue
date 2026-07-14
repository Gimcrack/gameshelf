<script setup lang="ts">
import type { ApiError } from '../utils/api'
import type { WishlistItem } from '../composables/useWishlist'

const { items, pending, error, fetchWishlist, removeFromWishlist, promoteToLibrary, syncWishlist } =
  useWishlist()

const actionError = ref('')
const syncMessage = ref('')
const busy = ref(false)

onMounted(() => fetchWishlist())

async function run(action: () => Promise<void>): Promise<void> {
  actionError.value = ''
  busy.value = true
  try {
    await action()
  } catch (err) {
    actionError.value = (err as ApiError).message
  } finally {
    busy.value = false
  }
}

function onPromote(item: WishlistItem): Promise<void> {
  return run(() => promoteToLibrary(item))
}

function onRemove(item: WishlistItem): Promise<void> {
  return run(() => removeFromWishlist(item.game_id))
}

function onSync(): Promise<void> {
  syncMessage.value = ''
  return run(async () => {
    await syncWishlist()
    syncMessage.value = 'Sync queued — platform wishlists update in the background.'
  })
}
</script>

<template>
  <main class="mx-auto max-w-6xl px-6 py-8">
    <header class="mb-6 flex items-center justify-between border-b border-slate-800 pb-4">
      <h1 class="text-2xl font-bold tracking-tight">
        Your <span class="text-teal-400">wishlist</span>
      </h1>
      <div class="flex items-center gap-2">
        <button
          :disabled="busy"
          class="rounded-md bg-teal-500 px-3 py-1.5 text-sm font-semibold text-slate-950 transition hover:bg-teal-400 disabled:cursor-not-allowed disabled:opacity-50"
          @click="onSync"
        >
          Sync platform wishlists
        </button>
        <NuxtLink
          to="/"
          class="rounded-md border border-slate-700 px-3 py-1.5 text-sm text-slate-300 transition hover:border-teal-400/60 hover:text-teal-300"
        >
          Back to library
        </NuxtLink>
      </div>
    </header>

    <p class="mb-4 text-xs text-slate-500">
      GOG syncs both ways. Steam wishlists import only — Steam offers no way to add items
      remotely.
    </p>

    <p v-if="syncMessage" class="mb-4 text-sm text-teal-300">{{ syncMessage }}</p>
    <p v-if="actionError" class="mb-4 text-sm text-rose-400">{{ actionError }}</p>
    <p v-if="error" class="text-rose-400">{{ error }}</p>
    <p v-else-if="pending && items.length === 0" class="text-slate-400">Loading wishlist…</p>
    <p v-else-if="items.length === 0" class="text-slate-400">
      Nothing saved yet. Wishlist games you don't own, and promote them once you do.
    </p>

    <div v-else class="grid grid-cols-[repeat(auto-fill,minmax(160px,1fr))] gap-4">
      <article
        v-for="item in items"
        :key="item.game_id"
        class="flex flex-col overflow-hidden rounded-lg border border-slate-800 bg-slate-900"
      >
        <img
          v-if="item.cover_url"
          :src="item.cover_url"
          :alt="item.title"
          loading="lazy"
          class="block aspect-[3/4] w-full object-cover"
        />
        <div
          v-else
          class="flex aspect-[3/4] items-center justify-center bg-gradient-to-b from-slate-800 to-slate-900 p-2 text-center text-sm text-slate-400"
        >
          {{ item.title }}
        </div>
        <div class="flex flex-1 flex-col px-3 pb-3 pt-2.5">
          <h3 class="mb-1 text-sm font-semibold leading-snug text-slate-100">{{ item.title }}</h3>
          <ul class="mb-1.5 flex flex-wrap gap-1.5 p-0">
            <li
              v-if="item.steam_present"
              class="rounded bg-teal-950/60 px-1.5 py-0.5 text-[0.65rem] uppercase tracking-wide text-teal-300"
            >
              steam
            </li>
            <li
              v-if="item.gog_present"
              class="rounded bg-teal-950/60 px-1.5 py-0.5 text-[0.65rem] uppercase tracking-wide text-teal-300"
            >
              gog
            </li>
          </ul>
          <p v-if="item.genres.length" class="mb-2 text-xs text-slate-500">
            {{ item.genres.join(', ') }}
          </p>
          <div class="mt-auto flex gap-1.5">
            <button
              :disabled="busy"
              class="rounded bg-teal-500 px-2 py-1 text-xs font-semibold text-slate-950 transition hover:bg-teal-400 disabled:opacity-50"
              @click="onPromote(item)"
            >
              I own this
            </button>
            <button
              :disabled="busy"
              class="rounded border border-slate-700 px-2 py-1 text-xs text-slate-400 transition hover:border-rose-400/60 hover:text-rose-300 disabled:opacity-50"
              @click="onRemove(item)"
            >
              Remove
            </button>
          </div>
        </div>
      </article>
    </div>
  </main>
</template>
