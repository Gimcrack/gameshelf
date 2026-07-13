import { vi } from 'vitest'
import { ref, type Ref } from 'vue'

/**
 * Minimal stand-in for Nuxt's '#app' auto-import surface, used only under
 * vitest (see vitest.config.ts alias). Keeps composables/utils/middleware
 * testable in isolation without booting the full Nuxt runtime.
 */

const stateRegistry = new Map<string, Ref<unknown>>()

export function useState<T>(key: string, init: () => T): Ref<T> {
  if (!stateRegistry.has(key)) {
    stateRegistry.set(key, ref(init()))
  }
  return stateRegistry.get(key) as Ref<T>
}

export function useRuntimeConfig() {
  return {
    public: {
      apiBase: 'http://localhost:8000'
    }
  }
}

export const navigateTo = vi.fn(async (path: string) => path)

export function defineNuxtRouteMiddleware<T extends (...args: never[]) => unknown>(fn: T): T {
  return fn
}

/** Call from `beforeEach` to isolate state between test cases. */
export function __resetNuxtAppMocks(): void {
  stateRegistry.clear()
  navigateTo.mockClear()
}
