import { beforeEach, describe, expect, it, vi } from 'vitest'

const apiFetchMock = vi.fn()

vi.mock('../../app/utils/api', () => ({
  apiFetch: (...args: unknown[]) => apiFetchMock(...args)
}))

import { useSimilarGames } from '../../app/composables/useSimilarGames'

describe('useSimilarGames', () => {
  beforeEach(() => {
    apiFetchMock.mockReset()
  })

  it('fetches rails from the similar endpoint', async () => {
    const payload = [
      {
        seed: { id: 1, igdb_id: 292030, title: 'The Witcher 3', cover_url: null },
        similar: [
          {
            igdb_id: 119388,
            title: 'Hades II',
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
    const { rails, fetchSimilar } = useSimilarGames()

    await fetchSimilar()

    expect(apiFetchMock).toHaveBeenCalledWith('/api/discover/similar')
    expect(rails.value).toEqual(payload)
  })

  it('captures error message on failure', async () => {
    apiFetchMock.mockRejectedValue(new Error('Request failed. Please try again.'))
    const { error, fetchSimilar } = useSimilarGames()

    await fetchSimilar()

    expect(error.value).toBe('Request failed. Please try again.')
  })

  it('flags a hit across every rail it appears in', async () => {
    apiFetchMock.mockResolvedValue([
      {
        seed: { id: 1, igdb_id: 292030, title: 'The Witcher 3', cover_url: null },
        similar: [
          {
            igdb_id: 119388,
            title: 'Hades II',
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
    const { rails, fetchSimilar, flagHit } = useSimilarGames()
    await fetchSimilar()

    flagHit(119388, { in_library: true, in_wishlist: false })

    expect(rails.value[0]?.similar[0]?.in_library).toBe(true)
  })
})
