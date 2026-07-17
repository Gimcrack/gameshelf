import { describe, expect, it } from 'vitest'
import { existsSync } from 'node:fs'
import { readFile } from 'node:fs/promises'
import { fileURLToPath, URL } from 'node:url'

/**
 * Guards SPEC §V70/T72: the landing hero references a real, checked-in
 * product screenshot (demo-seeded data only, per V70) at a stable path.
 */

function path(relative: string): string {
  return fileURLToPath(new URL(relative, import.meta.url))
}

describe('landing hero product screenshot (SPEC §V70/T72)', () => {
  it('welcome.vue references the screenshot asset', async () => {
    const src = await readFile(path('../../app/pages/welcome.vue'), 'utf8')
    expect(src).toContain('/screenshots/library-hero.webp')
  })

  it('the screenshot file exists in public/screenshots', () => {
    expect(existsSync(path('../../public/screenshots/library-hero.webp'))).toBe(true)
  })
})
