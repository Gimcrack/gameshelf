import { describe, expect, it } from 'vitest'
import { readFile } from 'node:fs/promises'
import { fileURLToPath, URL } from 'node:url'

/**
 * Guards SPEC §C.brand T43 "Nest Hex" identity: the BrandWordmark + BrandMark
 * components exist with the specified treatment, and the five inline logo spans
 * are replaced by BrandWordmark. Text-level assertions keep the suite off the
 * Nuxt runtime, matching theme.spec.ts / nuxtConfig.spec.ts.
 */

function read(relative: string): Promise<string> {
  return readFile(fileURLToPath(new URL(relative, import.meta.url)), 'utf8')
}

describe('BrandWordmark (SPEC §C.brand)', () => {
  it('renders Game + Bower with a teal "w"', async () => {
    const src = await read('../../app/components/BrandWordmark.vue')
    expect(src).toContain('>Game<')
    // "Bower" split so the w carries the teal accent.
    expect(src).toMatch(/Bo<span[^>]*text-teal-\d+[^>]*>w<\/span>er/)
    // Heavy "Game" half, lighter "Bower" half.
    expect(src).toMatch(/font-bold[^>]*>Game/)
    expect(src).toContain('text-slate-100')
    expect(src).toContain('text-slate-400')
    expect(src).not.toMatch(/dark:/)
  })
})

describe('BrandMark (SPEC §C.brand)', () => {
  it('is an inline nest-hex SVG recoloured for on-dark (white/#AAB4C3/teal)', async () => {
    const src = await read('../../app/components/BrandMark.vue')
    expect(src).toContain('viewBox="0 0 512 512"')
    // Alcove teal + app-icon on-dark stroke treatment.
    expect(src).toContain('#2FA7A0')
    expect(src).toContain('#AAB4C3')
    expect(src).toContain('#FFFFFF')
    // Deep indigo would be invisible on the charcoal shell — must not be used.
    expect(src).not.toContain('#1A1E4A')
    // Verbatim alcove path from the supplied brandmark.
    expect(src).toContain('M220 332 V252 L256 230 L292 252 V332 Z')
  })
})

describe('logo spans replaced by BrandWordmark (T42→T43)', () => {
  const pages = [
    '../../app/pages/index.vue',
    '../../app/pages/login.vue',
    '../../app/pages/register.vue',
    '../../app/pages/welcome.vue',
    '../../app/pages/stats.vue'
  ]

  it('no page still hardcodes the Game<span>Bower</span> lockup', async () => {
    for (const page of pages) {
      const src = await read(page)
      expect(src, `${page} uses <BrandWordmark>`).toContain('<BrandWordmark')
      expect(src, `${page} dropped the inline span lockup`).not.toMatch(
        /Game<span[^>]*>Bower<\/span>/
      )
    }
  })

  it('the app header and welcome hero carry the BrandMark', async () => {
    const index = await read('../../app/pages/index.vue')
    const welcome = await read('../../app/pages/welcome.vue')
    expect(index).toContain('<BrandMark')
    expect(welcome).toContain('<BrandMark')
  })
})

describe('welcome.vue copy (SPEC B20/T61)', () => {
  it('has no leftover "shelf" metaphor copy from the old GameShelf name', async () => {
    const welcome = await read('../../app/pages/welcome.vue')
    expect(welcome.toLowerCase()).not.toContain('shelf')
  })
})
