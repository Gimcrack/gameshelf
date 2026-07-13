import { beforeEach, describe, expect, it, vi } from 'vitest'
import { __resetNuxtAppMocks } from '#app'

const ofetchMock = vi.fn()
vi.mock('ofetch', () => ({
  ofetch: (...args: unknown[]) => ofetchMock(...args),
  FetchError: class FetchError extends Error {}
}))

import { useAuth } from '../../app/composables/useAuth'

class LocalStorageMock {
  private store = new Map<string, string>()

  getItem(key: string): string | null {
    return this.store.has(key) ? (this.store.get(key) as string) : null
  }

  setItem(key: string, value: string): void {
    this.store.set(key, value)
  }

  removeItem(key: string): void {
    this.store.delete(key)
  }

  clear(): void {
    this.store.clear()
  }
}

beforeEach(() => {
  __resetNuxtAppMocks()
  ofetchMock.mockReset()
  vi.stubGlobal('localStorage', new LocalStorageMock())
})

describe('useAuth', () => {
  it('login stores the token + user and persists the token to localStorage', async () => {
    ofetchMock.mockResolvedValueOnce({
      token: 'abc123',
      user: { id: 1, email: 'player@example.com', created_at: '2026-01-01' }
    })

    const { login, token, user } = useAuth()
    await login('player@example.com', 'password123')

    expect(token.value).toBe('abc123')
    expect(user.value).toEqual({ id: 1, email: 'player@example.com', created_at: '2026-01-01' })
    expect(localStorage.getItem('gameshelf_token')).toBe('abc123')
  })

  it('logout clears the token, user, and localStorage', async () => {
    ofetchMock.mockResolvedValueOnce({
      token: 'abc123',
      user: { id: 1, email: 'player@example.com', created_at: '2026-01-01' }
    })

    const { login, logout, token, user } = useAuth()
    await login('player@example.com', 'password123')

    ofetchMock.mockResolvedValueOnce(undefined)
    await logout()

    expect(token.value).toBeNull()
    expect(user.value).toBeNull()
    expect(localStorage.getItem('gameshelf_token')).toBeNull()
  })

  it('logout clears local session even when the API call fails', async () => {
    const { token, user, logout } = useAuth()
    token.value = 'existing-token'
    user.value = { id: 1, email: 'player@example.com', created_at: '2026-01-01' }

    ofetchMock.mockRejectedValueOnce(new Error('network down'))

    await logout()

    expect(token.value).toBeNull()
    expect(user.value).toBeNull()
  })

  it('fetchUser hydrates the user from the API response', async () => {
    const { fetchUser, token, user } = useAuth()
    token.value = 'existing-token'

    ofetchMock.mockResolvedValueOnce({
      id: 2,
      email: 'other@example.com',
      created_at: '2026-02-02'
    })

    const result = await fetchUser()

    expect(result).toEqual({ id: 2, email: 'other@example.com', created_at: '2026-02-02' })
    expect(user.value).toEqual({ id: 2, email: 'other@example.com', created_at: '2026-02-02' })
  })

  it('fetchUser returns null and skips the API call when there is no token', async () => {
    const { fetchUser } = useAuth()

    const result = await fetchUser()

    expect(result).toBeNull()
    expect(ofetchMock).not.toHaveBeenCalled()
  })
})
