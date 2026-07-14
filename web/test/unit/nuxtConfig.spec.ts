import { describe, expect, it } from 'vitest'
import { readFile } from 'node:fs/promises'
import { fileURLToPath, URL } from 'node:url'

/**
 * Guards SPEC §C.nuxt-mode: the app ships as an SPA (`ssr: false`).
 * Parses nuxt.config.ts as text rather than importing it, so the test
 * runs without the Nuxt runtime (defineNuxtConfig is an auto-import).
 */

const configPath = fileURLToPath(new URL('../../nuxt.config.ts', import.meta.url))

describe('nuxt.config.ts (SPEC §C.nuxt-mode)', () => {
  it('keeps ssr: false — SPA mode is a spec constraint', async () => {
    const source = await readFile(configPath, 'utf8')
    expect(source).toMatch(/\bssr:\s*false\b/)
  })

  it('does not enable ssr anywhere', async () => {
    const source = await readFile(configPath, 'utf8')
    expect(source).not.toMatch(/\bssr:\s*true\b/)
  })
})
