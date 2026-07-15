import { ref, type Ref } from 'vue'
import { useState } from '#app'
import { apiFetch, type ApiError } from '../utils/api'
import type { PlatformConnection } from '../utils/connections'

export type ConnectPayload =
  | { platform: 'steam'; steam_id?: string; vanity_url?: string }
  | { platform: 'gog'; code: string }

export interface SteamIdentity {
  steam_id: string
  persona_name: string
  avatar_url: string
}

export function useConnections() {
  const connections: Ref<PlatformConnection[]> = useState<PlatformConnection[]>(
    'connections',
    () => []
  )
  const pending = ref(false)
  const error = ref<string | null>(null)

  async function fetchConnections(): Promise<void> {
    pending.value = true
    error.value = null

    try {
      connections.value = await apiFetch<PlatformConnection[]>('/api/connections')
    } catch (err) {
      error.value = (err as ApiError).message
    } finally {
      pending.value = false
    }
  }

  /**
   * V25: pure identity preview — never creates a connection. Caller shows
   * the result and requires explicit confirm before calling connect().
   */
  async function resolveSteamIdentity(payload: {
    steam_id?: string
    vanity_url?: string
  }): Promise<SteamIdentity> {
    const params = new URLSearchParams(
      payload.steam_id ? { steam_id: payload.steam_id } : { vanity_url: payload.vanity_url ?? '' }
    )

    return apiFetch<SteamIdentity>(`/api/connections/steam/resolve?${params}`)
  }

  async function connect(payload: ConnectPayload): Promise<void> {
    const created = await apiFetch<PlatformConnection>('/api/connections', {
      method: 'POST',
      body: payload
    })
    connections.value = [...connections.value, created]
  }

  async function syncNow(id: number): Promise<void> {
    await apiFetch(`/api/connections/${id}/sync`, { method: 'POST' })
  }

  /**
   * V13: soft disconnect — the API keeps owned games and flips status.
   */
  async function disconnect(id: number): Promise<void> {
    const updated = await apiFetch<PlatformConnection>(`/api/connections/${id}`, {
      method: 'DELETE'
    })
    connections.value = connections.value.map((c) => (c.id === id ? updated : c))
  }

  return {
    connections,
    pending,
    error,
    fetchConnections,
    resolveSteamIdentity,
    connect,
    syncNow,
    disconnect
  }
}
