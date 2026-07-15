import { beforeEach, describe, expect, it, vi } from 'vitest'

const apiFetchMock = vi.fn()

vi.mock('../../app/utils/api', () => ({
  apiFetch: (...args: unknown[]) => apiFetchMock(...args)
}))

import { useFranchiseGaps } from '../../app/composables/useFranchiseGaps'

describe('useFranchiseGaps', () => {
  beforeEach(() => {
    apiFetchMock.mockReset()
  })

  it('fetches gaps from the franchises endpoint', async () => {
    const payload = [
      {
        franchise: 'The Witcher',
        owned: [{ igdb_id: 1942, title: 'The Witcher 3: Wild Hunt', cover_url: null }],
        missing: [
          {
            igdb_id: 1943,
            title: 'The Witcher 2: Assassins of Kings',
            cover_url: null,
            genres: [],
            release_date: null,
            rating: null,
            in_library: false,
            in_wishlist: false
          }
        ]
      }
    ]
    apiFetchMock.mockResolvedValue(payload)
    const { franchises, fetchFranchises } = useFranchiseGaps()

    await fetchFranchises()

    expect(apiFetchMock).toHaveBeenCalledWith('/api/discover/franchises')
    expect(franchises.value).toEqual(payload)
  })

  it('captures error message on failure', async () => {
    apiFetchMock.mockRejectedValue(new Error('Request failed. Please try again.'))
    const { error, fetchFranchises } = useFranchiseGaps()

    await fetchFranchises()

    expect(error.value).toBe('Request failed. Please try again.')
  })

  it('flags a missing hit across every gap it appears in', async () => {
    apiFetchMock.mockResolvedValue([
      {
        franchise: 'The Witcher',
        owned: [],
        missing: [
          {
            igdb_id: 1943,
            title: 'The Witcher 2: Assassins of Kings',
            cover_url: null,
            genres: [],
            release_date: null,
            rating: null,
            in_library: false,
            in_wishlist: false
          }
        ]
      }
    ])
    const { franchises, fetchFranchises, flagHit } = useFranchiseGaps()
    await fetchFranchises()

    flagHit(1943, { in_library: true, in_wishlist: false })

    expect(franchises.value[0]?.missing[0]?.in_library).toBe(true)
  })
})
