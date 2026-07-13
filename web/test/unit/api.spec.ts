import { beforeEach, describe, expect, it, vi } from 'vitest'
import { __resetNuxtAppMocks, navigateTo } from '#app'
import { useAuthToken } from '../../app/utils/authState'

const ofetchMock = vi.fn()
vi.mock('ofetch', () => ({
  ofetch: (...args: unknown[]) => ofetchMock(...args),
  FetchError: class FetchError extends Error {}
}))

import { apiFetch } from '../../app/utils/api'

beforeEach(() => {
  __resetNuxtAppMocks()
  ofetchMock.mockReset()
})

describe('apiFetch', () => {
  it('attaches the bearer token header when a token is present', async () => {
    useAuthToken().value = 'test-token'
    ofetchMock.mockResolvedValueOnce({ ok: true })

    await apiFetch('/api/user')

    expect(ofetchMock).toHaveBeenCalledWith(
      '/api/user',
      expect.objectContaining({
        baseURL: 'http://localhost:8000',
        headers: expect.objectContaining({ Authorization: 'Bearer test-token' })
      })
    )
  })

  it('omits the Authorization header when no token is present', async () => {
    ofetchMock.mockResolvedValueOnce({ ok: true })

    await apiFetch('/api/user')

    const callArgs = ofetchMock.mock.calls[0][1] as { headers: Record<string, string> }
    expect(callArgs.headers.Authorization).toBeUndefined()
  })

  it('clears the token and redirects to /login on a 401 response', async () => {
    useAuthToken().value = 'stale-token'
    const error = Object.assign(new Error('Unauthorized'), {
      response: { status: 401 },
      data: { message: 'Unauthenticated.' }
    })
    ofetchMock.mockRejectedValueOnce(error)

    await expect(apiFetch('/api/user')).rejects.toThrow('Unauthenticated.')

    expect(useAuthToken().value).toBeNull()
    expect(navigateTo).toHaveBeenCalledWith('/login')
  })

  it('normalizes validation errors into message + field errors', async () => {
    const error = Object.assign(new Error('Validation failed'), {
      response: { status: 422 },
      data: {
        message: 'The given data was invalid.',
        errors: { email: ['Email already taken'] }
      }
    })
    ofetchMock.mockRejectedValueOnce(error)

    await expect(apiFetch('/api/register')).rejects.toMatchObject({
      message: 'The given data was invalid.',
      errors: { email: ['Email already taken'] }
    })
  })
})
