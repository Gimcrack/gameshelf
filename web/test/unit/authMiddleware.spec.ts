import { beforeEach, describe, expect, it } from 'vitest'
import { __resetNuxtAppMocks, navigateTo } from '#app'
import { useAuthToken } from '../../app/utils/authState'
import authMiddleware from '../../app/middleware/auth.global'

interface FakeRoute {
  path: string
}

function routeTo(path: string): FakeRoute {
  return { path }
}

beforeEach(() => {
  __resetNuxtAppMocks()
})

describe('auth.global middleware', () => {
  it('redirects unauthenticated users to /login for protected routes', () => {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    authMiddleware(routeTo('/') as any, routeTo('/') as any)

    expect(navigateTo).toHaveBeenCalledWith('/login')
  })

  it('allows unauthenticated users to reach /login and /register', () => {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    authMiddleware(routeTo('/login') as any, routeTo('/') as any)
    expect(navigateTo).not.toHaveBeenCalled()

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    authMiddleware(routeTo('/register') as any, routeTo('/') as any)
    expect(navigateTo).not.toHaveBeenCalled()
  })

  it('allows authenticated users to reach protected routes', () => {
    useAuthToken().value = 'token-value'

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    authMiddleware(routeTo('/') as any, routeTo('/') as any)

    expect(navigateTo).not.toHaveBeenCalled()
  })

  it('redirects authenticated users away from /login to /', () => {
    useAuthToken().value = 'token-value'

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    authMiddleware(routeTo('/login') as any, routeTo('/') as any)

    expect(navigateTo).toHaveBeenCalledWith('/')
  })
})
