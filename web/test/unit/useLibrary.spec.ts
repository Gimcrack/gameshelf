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

  it('fetches a single game by id', async () => {
    const payload = { id: 5, title: 'Portal 2' }
    apiFetchMock.mockResolvedValue(payload)
    const { fetchGame } = useLibrary()

    const result = await fetchGame(5)

    expect(apiFetchMock).toHaveBeenCalledWith('/api/library/5')
    expect(result).toEqual(payload)
  })

  it('puts meta updates to the game meta endpoint', async () => {
    apiFetchMock.mockResolvedValue({})
    const { updateMeta } = useLibrary()

    await updateMeta(5, { status: 'playing', tags: ['co-op'], notes: null, rating: 4 })

    expect(apiFetchMock).toHaveBeenCalledWith('/api/library/5/meta', {
      method: 'PUT',
      body: { status: 'playing', tags: ['co-op'], notes: null, rating: 4 }
    })
  })
})
