import { describe, expect, it } from 'vitest'
import { readFile } from 'node:fs/promises'
import { fileURLToPath, URL } from 'node:url'

/**
 * Guards SPEC §C.styling + §C.theme: Tailwind CSS wired into Nuxt, and the
 * app shell is dark-only slate with teal accent. Text-level assertions keep
 * the suite free of the Nuxt runtime, same approach as nuxtConfig.spec.ts.
 */

function read(relative: string): Promise<string> {
  return readFile(fileURLToPath(new URL(relative, import.meta.url)), 'utf8')
}

describe('tailwind wiring (SPEC §C.styling)', () => {
  it('nuxt.config registers the tailwind vite plugin and main.css', async () => {
    const config = await read('../../nuxt.config.ts')
    expect(config).toContain("@tailwindcss/vite")
    expect(config).toContain('~/assets/css/main.css')
  })

  it('main.css imports tailwind', async () => {
    const css = await read('../../app/assets/css/main.css')
    expect(css).toContain('@import "tailwindcss"')
  })
})

describe('dark slate + teal theme (SPEC §C.theme)', () => {
  it('global shell is dark slate', async () => {
    const css = await read('../../app/assets/css/main.css')
    expect(css).toContain('color-scheme: dark')
    expect(css).toMatch(/bg-slate-950/)

    const appShell = await read('../../app/app.vue')
    expect(appShell).toMatch(/bg-slate-950/)
  })

  it('wires the Nest Hex brand hexes as theme tokens (T43, §C.theme)', async () => {
    const css = await read('../../app/assets/css/main.css')
    // The five brand hexes must be present as @theme tokens.
    for (const hex of ['#171A21', '#3A4556', '#1A1E4A', '#2FA7A0', '#7D6BFF']) {
      expect(css, `main.css defines ${hex}`).toContain(hex)
    }
    expect(css).toMatch(/@theme\s*\{/)
    // Muted teal is the primary accent (teal-400), not the default bright teal.
    expect(css).toMatch(/--color-teal-400:\s*#2FA7A0/i)
    expect(css).toMatch(/--color-slate-950:\s*#171A21/i)
  })

  it('views carry the slate/teal motif, no light-mode variants', async () => {
    const views = [
      '../../app/pages/index.vue',
      '../../app/pages/login.vue',
      '../../app/pages/register.vue',
      '../../app/pages/welcome.vue',
      '../../app/pages/stats.vue',
      '../../app/pages/profile.vue',
      '../../app/components/GameCard.vue',
      '../../app/components/ProfileAccount.vue',
      '../../app/components/ProfileConnections.vue',
      '../../app/components/ProfileApiKeys.vue'
    ]
    for (const view of views) {
      const source = await read(view)
      expect(source, `${view} uses slate surfaces`).toMatch(/slate-(8|9)\d\d/)
      expect(source, `${view} uses teal accent`).toMatch(/teal-\d+/)
      expect(source, `${view} has no light-mode variant classes`).not.toMatch(/dark:/)
    }
  })
})
