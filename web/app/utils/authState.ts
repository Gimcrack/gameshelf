import { useState } from '#app'

export interface AuthUser {
  id: number
  email: string
  created_at: string
}

export const TOKEN_STORAGE_KEY = 'gameshelf_token'

/**
 * Shared, reactive auth state. useState keys the value on the Nuxt app
 * instance so every composable/util that calls these getters shares the
 * same reactive ref.
 */
export const useAuthUser = () => useState<AuthUser | null>('gameshelf_auth_user', () => null)
export const useAuthToken = () => useState<string | null>('gameshelf_auth_token', () => null)

function hasLocalStorage(): boolean {
  return typeof localStorage !== 'undefined'
}

export function persistToken(token: string | null): void {
  if (!hasLocalStorage()) return

  if (token) {
    localStorage.setItem(TOKEN_STORAGE_KEY, token)
  } else {
    localStorage.removeItem(TOKEN_STORAGE_KEY)
  }
}

/**
 * Restores the token from localStorage into reactive state on app init.
 * Safe to call repeatedly - it is a no-op once state is already populated.
 */
export function restoreTokenFromStorage(): void {
  if (!hasLocalStorage()) return

  const token = useAuthToken()
  if (token.value) return

  const stored = localStorage.getItem(TOKEN_STORAGE_KEY)
  if (stored) {
    token.value = stored
  }
}

export function setAuthSession(token: string, user: AuthUser): void {
  const tokenState = useAuthToken()
  const userState = useAuthUser()

  tokenState.value = token
  userState.value = { ...user }
  persistToken(token)
}

export function clearAuthState(): void {
  const token = useAuthToken()
  const user = useAuthUser()

  token.value = null
  user.value = null
  persistToken(null)
}
