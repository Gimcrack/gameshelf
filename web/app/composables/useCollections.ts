import { ref, type Ref } from 'vue'
import { useState } from '#app'
import { apiFetch, type ApiError } from '../utils/api'

export interface Collection {
  id: number
  name: string
  type: 'filter' | 'manual'
  filters: Record<string, unknown> | null
}

// T44: system smart collections — slug-keyed, for the library picker.
export interface SystemCollection {
  slug: string
  name: string
  description: string
}

export function useCollections() {
  const collections: Ref<Collection[]> = useState<Collection[]>('collections', () => [])
  const system: Ref<SystemCollection[]> = useState<SystemCollection[]>('system-collections', () => [])
  const pending = ref(false)
  const error = ref<string | null>(null)

  async function fetchCollections(): Promise<void> {
    pending.value = true
    error.value = null

    try {
      const response = await apiFetch<{ system: SystemCollection[]; custom: Collection[] }>(
        '/api/collections'
      )
      collections.value = response.custom
      system.value = response.system
    } catch (err) {
      error.value = (err as ApiError).message
    } finally {
      pending.value = false
    }
  }

  /**
   * T44: save the current sidebar filter state as a reusable smart collection
   * (V29 type=filter). Returns the created collection.
   */
  async function createFilterCollection(
    name: string,
    filters: Record<string, unknown>
  ): Promise<Collection> {
    const created = await apiFetch<Collection>('/api/collections', {
      method: 'POST',
      body: { name, type: 'filter', filters }
    })
    await fetchCollections()
    return created
  }

  /** V29: manual collections only — API 422s otherwise. Idempotent. */
  async function addGame(collectionId: number, gameId: number): Promise<void> {
    await apiFetch(`/api/collections/${collectionId}/games`, {
      method: 'POST',
      body: { game_id: gameId }
    })
  }

  async function removeGame(collectionId: number, gameId: number): Promise<void> {
    await apiFetch(`/api/collections/${collectionId}/games/${gameId}`, { method: 'DELETE' })
  }

  return {
    collections,
    system,
    pending,
    error,
    fetchCollections,
    createFilterCollection,
    addGame,
    removeGame
  }
}
