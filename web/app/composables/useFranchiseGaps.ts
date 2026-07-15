import { ref, type Ref } from 'vue'
import { useState } from '#app'
import { apiFetch, type ApiError } from '../utils/api'
import type { DiscoverHit } from './useDiscover'

export interface FranchiseOwnedGame {
  igdb_id: number
  title: string
  cover_url: string | null
}

export interface FranchiseGap {
  franchise: string
  owned: FranchiseOwnedGame[]
  missing: DiscoverHit[]
}

/** I.api T18: "complete the series" rails from the caller's owned games. */
export function useFranchiseGaps() {
  const franchises: Ref<FranchiseGap[]> = useState<FranchiseGap[]>('discover-franchise-gaps', () => [])
  const pending = ref(false)
  const error = ref<string | null>(null)

  async function fetchFranchises(): Promise<void> {
    pending.value = true
    error.value = null

    try {
      franchises.value = await apiFetch<FranchiseGap[]>('/api/discover/franchises')
    } catch (err) {
      error.value = (err as ApiError).message
    } finally {
      pending.value = false
    }
  }

  function flagHit(igdbId: number, flags: Partial<Pick<DiscoverHit, 'in_library' | 'in_wishlist'>>): void {
    franchises.value = franchises.value.map((gap) => ({
      ...gap,
      missing: gap.missing.map((hit) => (hit.igdb_id === igdbId ? { ...hit, ...flags } : hit))
    }))
  }

  return { franchises, pending, error, fetchFranchises, flagHit }
}
