import { ref, type Ref } from 'vue'
import { useState } from '#app'
import { apiFetch, type ApiError } from '../utils/api'

export interface ApiToken {
  id: number
  name: string
  last_used_at: string | null
  created_at: string
  current: boolean
}

export interface CreatedToken {
  id: number
  name: string
  /** V18: only ever present here, immediately after creation. */
  token: string
}

export function useTokens() {
  const tokens: Ref<ApiToken[]> = useState<ApiToken[]>('api-tokens', () => [])
  const pending = ref(false)
  const error = ref<string | null>(null)

  async function fetchTokens(): Promise<void> {
    pending.value = true
    error.value = null

    try {
      tokens.value = await apiFetch<ApiToken[]>('/api/tokens')
    } catch (err) {
      error.value = (err as ApiError).message
    } finally {
      pending.value = false
    }
  }

  async function createToken(name: string): Promise<CreatedToken> {
    const created = await apiFetch<CreatedToken>('/api/tokens', {
      method: 'POST',
      body: { name }
    })
    await fetchTokens()

    return created
  }

  async function revokeToken(id: number): Promise<void> {
    await apiFetch(`/api/tokens/${id}`, { method: 'DELETE' })
    tokens.value = tokens.value.filter((t) => t.id !== id)
  }

  return { tokens, pending, error, fetchTokens, createToken, revokeToken }
}
