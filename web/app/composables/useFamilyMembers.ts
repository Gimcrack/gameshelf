import { ref, type Ref } from 'vue'
import { useState } from '#app'
import { apiFetch, type ApiError } from '../utils/api'
import type { PlatformConnection } from '../utils/connections'

export interface FamilyMember {
  id: number
  steam_id: string
  persona_name: string
  avatar_url: string
  last_synced_at: string | null
  status: PlatformConnection['status']
}

/** T60/V58: manually-added Steam family members, synced via steam_family connections. */
export function useFamilyMembers() {
  const familyMembers: Ref<FamilyMember[]> = useState<FamilyMember[]>('family-members', () => [])
  const pending = ref(false)
  const error = ref<string | null>(null)

  async function fetchFamilyMembers(): Promise<void> {
    pending.value = true
    error.value = null

    try {
      familyMembers.value = await apiFetch<FamilyMember[]>('/api/family-members')
    } catch (err) {
      error.value = (err as ApiError).message
    } finally {
      pending.value = false
    }
  }

  /** V25: identity is already resolved+confirmed (useConnections.resolveSteamIdentity). */
  async function addFamilyMember(steamId: string): Promise<void> {
    const created = await apiFetch<FamilyMember>('/api/family-members', {
      method: 'POST',
      body: { steam_id: steamId }
    })
    familyMembers.value = [...familyMembers.value, created]
  }

  async function removeFamilyMember(id: number): Promise<void> {
    await apiFetch(`/api/family-members/${id}`, { method: 'DELETE' })
    familyMembers.value = familyMembers.value.filter((m) => m.id !== id)
  }

  return {
    familyMembers,
    pending,
    error,
    fetchFamilyMembers,
    addFamilyMember,
    removeFamilyMember
  }
}
