import {
  useAuthUser,
  useAuthToken,
  setAuthSession,
  clearAuthState,
  restoreTokenFromStorage,
  type AuthUser
} from '../utils/authState'
import { apiFetch } from '../utils/api'

interface AuthResponse {
  token: string
  user: AuthUser
}

export function useAuth() {
  const user = useAuthUser()
  const token = useAuthToken()

  restoreTokenFromStorage()

  async function register(email: string, password: string): Promise<AuthUser> {
    const response = await apiFetch<AuthResponse>('/api/register', {
      method: 'POST',
      body: { email, password }
    })

    setAuthSession(response.token, response.user)
    return response.user
  }

  async function login(email: string, password: string): Promise<AuthUser> {
    const response = await apiFetch<AuthResponse>('/api/login', {
      method: 'POST',
      body: { email, password }
    })

    setAuthSession(response.token, response.user)
    return response.user
  }

  async function logout(): Promise<void> {
    try {
      await apiFetch<void>('/api/logout', { method: 'POST' })
    } catch {
      // Local session is cleared regardless of whether the API call
      // succeeds - an unreachable/expired session should not trap the user.
    } finally {
      clearAuthState()
    }
  }

  async function fetchUser(): Promise<AuthUser | null> {
    if (!token.value) return null

    try {
      const fetchedUser = await apiFetch<AuthUser>('/api/user')
      user.value = { ...fetchedUser }
      return user.value
    } catch {
      return null
    }
  }

  return {
    user,
    token,
    register,
    login,
    logout,
    fetchUser
  }
}
