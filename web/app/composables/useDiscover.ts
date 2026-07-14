import { ref, type Ref } from 'vue'
import { useState } from '#app'
import { apiFetch, type ApiError } from '../utils/api'

export interface DiscoverHit {
  igdb_id: number
  title: string
  cover_url: string | null
  genres: string[]
  release_date: string | null
  rating: number | null
  in_library: boolean
  in_wishlist: boolean
}

export type DiscoverSort = 'popularity' | 'rating' | 'release'

export interface BrowseParams {
  genre?: string
  sort: DiscoverSort
  page: number
}

export function useDiscover() {
  const hits: Ref<DiscoverHit[]> = useState<DiscoverHit[]>('discover-hits', () => [])
  const pending = ref(false)
  const error = ref<string | null>(null)

  async function load(path: string): Promise<void> {
    pending.value = true
    error.value = null

    try {
      hits.value = await apiFetch<DiscoverHit[]>(path)
    } catch (err) {
      error.value = (err as ApiError).message
    } finally {
      pending.value = false
    }
  }

  function search(q: string): Promise<void> {
    return load(`/api/discover/search?q=${encodeURIComponent(q)}`)
  }

  function browse({ genre, sort, page }: BrowseParams): Promise<void> {
    const params = new URLSearchParams({ sort, page: String(page) })
    if (genre?.trim()) {
      params.set('genre', genre.trim())
    }

    return load(`/api/discover/browse?${params}`)
  }

  function flagHit(igdbId: number, flags: Partial<Pick<DiscoverHit, 'in_library' | 'in_wishlist'>>): void {
    hits.value = hits.value.map((hit) => (hit.igdb_id === igdbId ? { ...hit, ...flags } : hit))
  }

  /** V19: manual-add path — same endpoint the wishlist promote flow uses. */
  async function addToLibrary(igdbId: number): Promise<void> {
    await apiFetch('/api/library', { method: 'POST', body: { igdb_id: igdbId } })
    flagHit(igdbId, { in_library: true, in_wishlist: false })
  }

  async function addToWishlist(igdbId: number): Promise<void> {
    await apiFetch('/api/wishlist', { method: 'POST', body: { igdb_id: igdbId } })
    flagHit(igdbId, { in_wishlist: true })
  }

  return {
    hits,
    pending,
    error,
    search,
    browse,
    addToLibrary,
    addToWishlist
  }
}
