import { apiFetch } from '../utils/api'

export function useWishlist() {
  /** V22: queued platform sync — GOG two-way, Steam import-only. */
  async function syncWishlist(): Promise<void> {
    await apiFetch('/api/wishlist/sync', { method: 'POST' })
  }

  return { syncWishlist }
}
