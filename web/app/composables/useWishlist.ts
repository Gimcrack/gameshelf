import { ref, type Ref } from 'vue'
import { useState } from '#app'
import { apiFetch, type ApiError } from '../utils/api'

export interface WishlistItem {
  game_id: number
  igdb_id: number
  title: string
  cover_url: string | null
  genres: string[]
  release_date: string | null
  time_to_beat_minutes: number | null
  added_at: string
}

export function useWishlist() {
  const items: Ref<WishlistItem[]> = useState<WishlistItem[]>('wishlist-items', () => [])
  const pending = ref(false)
  const error = ref<string | null>(null)

  async function fetchWishlist(): Promise<void> {
    pending.value = true
    error.value = null

    try {
      items.value = await apiFetch<WishlistItem[]>('/api/wishlist')
    } catch (err) {
      error.value = (err as ApiError).message
    } finally {
      pending.value = false
    }
  }

  async function addToWishlist(igdbId: number): Promise<void> {
    await apiFetch('/api/wishlist', { method: 'POST', body: { igdb_id: igdbId } })
    await fetchWishlist()
  }

  async function removeFromWishlist(gameId: number): Promise<void> {
    await apiFetch(`/api/wishlist/${gameId}`, { method: 'DELETE' })
    items.value = items.value.filter((i) => i.game_id !== gameId)
  }

  /**
   * V21: owning it fulfils the wish — the API clears the wishlist row when
   * the game enters the library.
   */
  async function promoteToLibrary(item: WishlistItem): Promise<void> {
    await apiFetch('/api/library', { method: 'POST', body: { igdb_id: item.igdb_id } })
    items.value = items.value.filter((i) => i.game_id !== item.game_id)
  }

  return { items, pending, error, fetchWishlist, addToWishlist, removeFromWishlist, promoteToLibrary }
}
