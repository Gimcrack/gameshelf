import { describe, expect, it, vi } from 'vitest'

const apiFetchMock = vi.fn()

vi.mock('../../app/utils/api', () => ({
  apiFetch: (...args: unknown[]) => apiFetchMock(...args)
}))

import { useWishlist } from '../../app/composables/useWishlist'

describe('useWishlist', () => {
  // T52/V22: the /profile "Sync platform wishlists" control (relocated from
  // the removed /wishlist page) calls this — queued job, GOG two-way,
  // Steam import-only.
  it('posts to the wishlist sync endpoint', async () => {
    apiFetchMock.mockResolvedValue({})
    const { syncWishlist } = useWishlist()

    await syncWishlist()

    expect(apiFetchMock).toHaveBeenCalledWith('/api/wishlist/sync', { method: 'POST' })
  })
})
