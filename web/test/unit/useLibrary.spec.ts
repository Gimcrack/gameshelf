import { beforeEach, describe, expect, it, vi } from 'vitest'

const apiFetchMock = vi.fn()

vi.mock('../../app/utils/api', () => ({
  apiFetch: (...args: unknown[]) => apiFetchMock(...args)
}))

import { useLibrary } from '../../app/composables/useLibrary'

describe('useLibrary', () => {
  beforeEach(() => {
    apiFetchMock.mockReset()
  })

  it('fetches entries without params when no filters set', async () => {
    apiFetchMock.mockResolvedValue([])
    const { fetchLibrary } = useLibrary()

    await fetchLibrary()

    expect(apiFetchMock).toHaveBeenCalledWith('/api/library')
  })

  it('appends query string for filters', async () => {
    apiFetchMock.mockResolvedValue([])
    const { fetchLibrary } = useLibrary()

    await fetchLibrary({ sort: 'playtime', order: 'desc', unplayed: true })

    expect(apiFetchMock).toHaveBeenCalledWith(
      '/api/library?sort=playtime&order=desc&unplayed=1'
    )
  })

  it('stores fetched entries and clears pending', async () => {
    const payload = [{ id: 1, title: 'Portal 2' }]
    apiFetchMock.mockResolvedValue(payload)
    const { entries, pending, fetchLibrary } = useLibrary()

    await fetchLibrary()

    expect(entries.value).toEqual(payload)
    expect(pending.value).toBe(false)
  })

  it('captures error message on failure', async () => {
    apiFetchMock.mockRejectedValue(new Error('Request failed. Please try again.'))
    const { error, fetchLibrary } = useLibrary()

    await fetchLibrary()

    expect(error.value).toBe('Request failed. Please try again.')
  })
})
