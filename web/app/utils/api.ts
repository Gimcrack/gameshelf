import { ofetch, FetchError } from 'ofetch'
import { useRuntimeConfig, navigateTo } from '#app'
import { useAuthToken, clearAuthState } from './authState'

export interface ApiFetchOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body?: Record<string, unknown>
  headers?: Record<string, string>
}

export interface ApiError extends Error {
  status?: number
  errors?: Record<string, string[]>
}

function isFetchError(error: unknown): error is FetchError {
  return Boolean(error && typeof error === 'object' && 'response' in error)
}

function normalizeError(error: unknown): ApiError {
  if (isFetchError(error)) {
    const data = error.data as { message?: string; errors?: Record<string, string[]> } | undefined
    const normalized = new Error(data?.message ?? 'Request failed. Please try again.') as ApiError
    normalized.status = error.response?.status
    normalized.errors = data?.errors
    return normalized
  }

  return new Error('Network error. Please check your connection and try again.') as ApiError
}

function isUnauthorized(error: unknown): boolean {
  return isFetchError(error) && error.response?.status === 401
}

/**
 * $fetch wrapper: prefixes the configured API base, attaches the bearer
 * token when present, and clears the session + redirects to /login on 401.
 */
export async function apiFetch<T>(path: string, options: ApiFetchOptions = {}): Promise<T> {
  const config = useRuntimeConfig()
  const token = useAuthToken()

  const headers: Record<string, string> = {
    Accept: 'application/json',
    ...(options.headers ?? {})
  }

  if (token.value) {
    headers.Authorization = `Bearer ${token.value}`
  }

  try {
    return await ofetch<T>(path, {
      baseURL: config.public.apiBase,
      method: options.method ?? 'GET',
      body: options.body,
      headers
    })
  } catch (error) {
    if (isUnauthorized(error)) {
      clearAuthState()
      await navigateTo('/login')
    }

    throw normalizeError(error)
  }
}
