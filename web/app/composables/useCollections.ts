import { ref, type Ref } from 'vue'
import { useState } from '#app'
import { apiFetch, type ApiError } from '../utils/api'

export interface Collection {
  id: number
  name: string
  type: 'filter' | 'manual'
  filters: Record<string, unknown> | null
}

export function useCollections() {
  const collections: Ref<Collection[]> = useState<Collection[]>('collections', () => [])
  const pending = ref(false)
  const error = ref<string | null>(null)

  async function fetchCollections(): Promise<void> {
    pending.value = true
    error.value = null

    try {
      const response = await apiFetch<{ custom: Collection[] }>('/api/collections')
      collections.value = response.custom
    } catch (err) {
      error.value = (err as ApiError).message
    } finally {
      pending.value = false
    }
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

  return { collections, pending, error, fetchCollections, addGame, removeGame }
}
