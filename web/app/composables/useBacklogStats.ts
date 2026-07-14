import { ref, type Ref } from 'vue'
import { useState } from '#app'
import { apiFetch, type ApiError } from '../utils/api'
import type { BacklogStats } from '../utils/stats'

export function useBacklogStats() {
  const stats: Ref<BacklogStats | null> = useState<BacklogStats | null>('backlog-stats', () => null)
  const pending = ref(false)
  const error = ref<string | null>(null)

  async function fetchStats(): Promise<void> {
    pending.value = true
    error.value = null

    try {
      stats.value = await apiFetch<BacklogStats>('/api/stats/backlog')
    } catch (err) {
      error.value = (err as ApiError).message
    } finally {
      pending.value = false
    }
  }

  return { stats, pending, error, fetchStats }
}
