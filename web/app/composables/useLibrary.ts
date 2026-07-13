import { ref, type Ref } from 'vue'
import { useState } from '#app'
import { apiFetch, type ApiError } from '../utils/api'
import { buildLibraryQuery, type LibraryEntry, type LibraryFilters } from '../utils/library'

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

  return { entries, pending, error, fetchLibrary }
}
