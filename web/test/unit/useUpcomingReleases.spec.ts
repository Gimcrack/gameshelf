import { beforeEach, describe, expect, it, vi } from 'vitest'

const apiFetchMock = vi.fn()

vi.mock('../../app/utils/api', () => ({
  apiFetch: (...args: unknown[]) => apiFetchMock(...args)
}))

import { useUpcomingReleases } from '../../app/composables/useUpcomingReleases'

describe('useUpcomingReleases', () => {
  beforeEach(() => {
    apiFetchMock.mockReset()
  })

  it('fetches hits from the upcoming endpoint', async () => {
    const payload = [
      {
        igdb_id: 555,
        title: 'Upcoming RPG',
        cover_url: null,
        genres: ['RPG'],
        release_date: '2026-09-01',
        rating: null,
        in_library: false,
        in_wishlist: false
      }
    ]
    apiFetchMock.mockResolvedValue(payload)
    const { hits, fetchUpcoming } = useUpcomingReleases()

    await fetchUpcoming()

    expect(apiFetchMock).toHaveBeenCalledWith('/api/discover/upcoming')
    expect(hits.value).toEqual(payload)
  })

  it('captures error message on failure', async () => {
    apiFetchMock.mockRejectedValue(new Error('Request failed. Please try again.'))
    const { error, fetchUpcoming } = useUpcomingReleases()

    await fetchUpcoming()

    expect(error.value).toBe('Request failed. Please try again.')
  })

  it('flags a hit by igdb_id', async () => {
    apiFetchMock.mockResolvedValue([
      {
        igdb_id: 555,
        title: 'Upcoming RPG',
        cover_url: null,
        genres: [],
        release_date: null,
        rating: null,
        in_library: false,
        in_wishlist: false
      }
    ])
    const { hits, fetchUpcoming, flagHit } = useUpcomingReleases()
    await fetchUpcoming()

    flagHit(555, { in_library: true, in_wishlist: false })

    expect(hits.value[0]?.in_library).toBe(true)
  })
})
