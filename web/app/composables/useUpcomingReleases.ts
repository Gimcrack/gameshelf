import { ref, type Ref } from 'vue'
import { useState } from '#app'
import { apiFetch, type ApiError } from '../utils/api'
import type { DiscoverHit } from './useDiscover'

/** I.api T19: upcoming-releases rail, filtered to the caller's top owned genres. */
export function useUpcomingReleases() {
  const hits: Ref<DiscoverHit[]> = useState<DiscoverHit[]>('discover-upcoming-hits', () => [])
  const pending = ref(false)
  const error = ref<string | null>(null)

  async function fetchUpcoming(): Promise<void> {
    pending.value = true
    error.value = null

    try {
      hits.value = await apiFetch<DiscoverHit[]>('/api/discover/upcoming')
    } catch (err) {
      error.value = (err as ApiError).message
    } finally {
      pending.value = false
    }
  }

  function flagHit(igdbId: number, flags: Partial<Pick<DiscoverHit, 'in_library' | 'in_wishlist'>>): void {
    hits.value = hits.value.map((hit) => (hit.igdb_id === igdbId ? { ...hit, ...flags } : hit))
  }

  return { hits, pending, error, fetchUpcoming, flagHit }
}
