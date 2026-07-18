import { ref, type Ref } from 'vue'
import { useState } from '#app'
import { apiFetch, type ApiError } from '../utils/api'
import {
  buildLibraryQuery,
  defaultLibraryFilterState,
  type Achievement,
  type LibraryEntry,
  type LibraryFacets,
  type LibraryFilters,
  type LibraryFilterState,
  type LibraryMetaUpdate
} from '../utils/library'

const EMPTY_FACETS: LibraryFacets = {
  genres: [],
  themes: [],
  keywords: [],
  game_modes: [],
  platforms: [],
  esrb_ratings: []
}

export function useLibrary() {
  const entries: Ref<LibraryEntry[]> = useState<LibraryEntry[]>('library-entries', () => [])
  const facets: Ref<LibraryFacets> = useState<LibraryFacets>('library-facets', () => EMPTY_FACETS)
  // T81/V77: filter/sort/collection selection state, persisted across in-app nav.
  const filterState: Ref<LibraryFilterState> = useState<LibraryFilterState>(
    'library-filter-state',
    defaultLibraryFilterState
  )
  // T81/V78: scrollY captured on leaving `/`, restored after fetchLibrary resolves.
  const scrollY: Ref<number | null> = useState<number | null>('library-scroll-y', () => null)
  const pending = ref(false)
  const error = ref<string | null>(null)

  async function fetchLibrary(filters: LibraryFilters = {}): Promise<void> {
    pending.value = true
    error.value = null

    try {
      const query = buildLibraryQuery(filters)
      const path = query ? `/api/library?${query}` : '/api/library'
      entries.value = await apiFetch<LibraryEntry[]>(path)
    } catch (err) {
      error.value = (err as ApiError).message
    } finally {
      pending.value = false
    }
  }

  async function removeManual(gameId: number): Promise<void> {
    await apiFetch(`/api/library/${gameId}/manual`, { method: 'DELETE' })
  }

  /** T52/V21: promote clears the wishlist row server-side. */
  async function promoteToOwned(igdbId: number): Promise<void> {
    await apiFetch('/api/library', { method: 'POST', body: { igdb_id: igdbId } })
  }

  /** T52/I.api T17: DELETE /api/wishlist/:game_id. */
  async function removeFromWishlist(gameId: number): Promise<void> {
    await apiFetch(`/api/wishlist/${gameId}`, { method: 'DELETE' })
  }

  /** T28: distinct genre/theme/keyword/game_mode/platform values, for the left-sidebar checkboxes. */
  async function fetchFacets(): Promise<void> {
    try {
      facets.value = await apiFetch<LibraryFacets>('/api/library/facets')
    } catch (err) {
      error.value = (err as ApiError).message
    }
  }

  /** I.api T24: GET /api/library/:game_id — same entry shape as the list. */
  async function fetchGame(gameId: number): Promise<LibraryEntry> {
    return apiFetch<LibraryEntry>(`/api/library/${gameId}`)
  }

  /**
   * I.api T70: GET /api/library/:game_id/achievements — null (not an error)
   * when the game has no achievement-capable owning row (V67 gating, 404).
   */
  async function fetchAchievements(gameId: number): Promise<Achievement[] | null> {
    try {
      const response = await apiFetch<{ achievements: Achievement[] }>(
        `/api/library/${gameId}/achievements`
      )
      return response.achievements
    } catch (err) {
      if ((err as ApiError).status === 404) return null
      throw err
    }
  }

  /** I.api: PUT /api/library/:game_id/meta — partial upsert (V6). */
  async function updateMeta(gameId: number, payload: LibraryMetaUpdate): Promise<void> {
    await apiFetch(`/api/library/${gameId}/meta`, { method: 'PUT', body: payload })
  }

  /** I.api T29/V34: POST /api/library/:game_id/rematch — returns the updated entry. */
  async function rematch(gameId: number, igdbId: number): Promise<LibraryEntry> {
    return apiFetch<LibraryEntry>(`/api/library/${gameId}/rematch`, {
      method: 'POST',
      body: { igdb_id: igdbId }
    })
  }

  /** I.api T30/V35: POST /api/library/:game_id/refresh-igdb — returns the updated entry. */
  async function refreshIgdb(gameId: number): Promise<LibraryEntry> {
    return apiFetch<LibraryEntry>(`/api/library/${gameId}/refresh-igdb`, { method: 'POST' })
  }

  /** I.api T31/V38: POST /api/library/sync-igdb — 202, queued, no live status. */
  async function syncIgdb(): Promise<void> {
    await apiFetch('/api/library/sync-igdb', { method: 'POST' })
  }

  return {
    entries,
    facets,
    filterState,
    scrollY,
    pending,
    error,
    fetchLibrary,
    fetchFacets,
    removeManual,
    promoteToOwned,
    removeFromWishlist,
    fetchGame,
    fetchAchievements,
    updateMeta,
    rematch,
    refreshIgdb,
    syncIgdb
  }
}
