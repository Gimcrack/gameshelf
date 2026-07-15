import { ref, type Ref } from 'vue'
import { useState } from '#app'
import { apiFetch, type ApiError } from '../utils/api'
import type { DiscoverHit } from './useDiscover'

export interface SimilarSeed {
  id: number
  igdb_id: number
  title: string
  cover_url: string | null
}

export interface SimilarRail {
  seed: SimilarSeed
  similar: DiscoverHit[]
}

/** I.api T16: "because you played X" rails from the caller's owned games. */
export function useSimilarGames() {
  const rails: Ref<SimilarRail[]> = useState<SimilarRail[]>('discover-similar-rails', () => [])
  const pending = ref(false)
  const error = ref<string | null>(null)

  async function fetchSimilar(): Promise<void> {
    pending.value = true
    error.value = null

    try {
      rails.value = await apiFetch<SimilarRail[]>('/api/discover/similar')
    } catch (err) {
      error.value = (err as ApiError).message
    } finally {
      pending.value = false
    }
  }

  function flagHit(igdbId: number, flags: Partial<Pick<DiscoverHit, 'in_library' | 'in_wishlist'>>): void {
    rails.value = rails.value.map((rail) => ({
      ...rail,
      similar: rail.similar.map((hit) => (hit.igdb_id === igdbId ? { ...hit, ...flags } : hit))
    }))
  }

  return { rails, pending, error, fetchSimilar, flagHit }
}
