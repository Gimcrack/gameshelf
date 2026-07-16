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

  // T52/V21: promote posts to the manual-add endpoint — the API clears the
  // wishlist row server-side once the game lands in owned_games.
  it('promotes a wishlist game by posting igdb_id to /api/library', async () => {
    apiFetchMock.mockResolvedValue({})
    const { promoteToOwned } = useLibrary()

    await promoteToOwned(1231)

    expect(apiFetchMock).toHaveBeenCalledWith('/api/library', {
      method: 'POST',
      body: { igdb_id: 1231 }
    })
  })

  // T52/I.api T17: DELETE /api/wishlist/:game_id.
  it('removes a wishlist entry by game id', async () => {
    apiFetchMock.mockResolvedValue({})
    const { removeFromWishlist } = useLibrary()

    await removeFromWishlist(1231)

    expect(apiFetchMock).toHaveBeenCalledWith('/api/wishlist/1231', { method: 'DELETE' })
  })
})
