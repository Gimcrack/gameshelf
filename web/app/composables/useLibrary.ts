import { ref, type Ref } from 'vue'
import { useState } from '#app'
import { apiFetch, type ApiError } from '../utils/api'
import {
  buildLibraryQuery,
  type LibraryEntry,
  type LibraryFilters,
  type LibraryMetaUpdate
} from '../utils/library'

export function useLibrary() {
  const entries: Ref<LibraryEntry[]> = useState<LibraryEntry[]>('library-entries', () => [])
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

  /** I.api T24: GET /api/library/:game_id — same entry shape as the list. */
  async function fetchGame(gameId: number): Promise<LibraryEntry> {
    return apiFetch<LibraryEntry>(`/api/library/${gameId}`)
  }

  /** I.api: PUT /api/library/:game_id/meta — partial upsert (V6). */
  async function updateMeta(gameId: number, payload: LibraryMetaUpdate): Promise<void> {
    await apiFetch(`/api/library/${gameId}/meta`, { method: 'PUT', body: payload })
  }

  return { entries, pending, error, fetchLibrary, removeManual, fetchGame, updateMeta }
}
