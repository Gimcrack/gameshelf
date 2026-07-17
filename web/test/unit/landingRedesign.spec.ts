import { describe, expect, it } from 'vitest'
import { readFile } from 'node:fs/promises'
import { fileURLToPath, URL } from 'node:url'

/**
 * Guards SPEC §V70/V71/T73: landing-page redesign adopted from
 * landing-mockup.png, minus the elements that would overclaim or
 * fabricate (dead nav links, unsupported platforms, a fake testimonial).
 */

function read(relative: string): Promise<string> {
  return readFile(fileURLToPath(new URL(relative, import.meta.url)), 'utf8')
}

/** Strips HTML + JS line comments so assertions check rendered content, not commentary. */
function stripComments(src: string): string {
  return src.replace(/<!--[\s\S]*?-->/g, '').replace(/^\s*\/\/.*$/gm, '')
}

describe('header nav + CTA (SPEC §T73)', () => {
  it('links only to sections that exist on this page', async () => {
    const src = await read('../../app/pages/welcome.vue')
    expect(src).toContain('href="#features"')
    expect(src).toContain('href="#platforms"')
    expect(src).not.toMatch(/href="#?(pricing|blog|discover)"/i)
  })

  it('has a "Get started" CTA beside the "Log in" link', async () => {
    const src = await read('../../app/pages/welcome.vue')
    expect(src).toContain('Get started')
    expect(src).toContain('Log in')
  })
})

describe('screenshot chrome frame (SPEC §V70 amended)', () => {
  it('uses generic same-color dots, not macOS traffic-light colors', async () => {
    const src = await read('../../app/pages/welcome.vue')
    expect(src).toContain('id="screenshot"')
    // 3 dots, same neutral class each — not red/amber/green.
    const dotMatches = src.match(/size-2\.5 rounded-full bg-slate-700/g) ?? []
    expect(dotMatches.length).toBe(3)
    expect(src).not.toMatch(/bg-red-|bg-amber-|bg-green-|bg-yellow-/)
  })
})

describe('platform list (SPEC §V71)', () => {
  it('lists only actually-integrated platforms', async () => {
    const src = await read('../../app/pages/welcome.vue')
    expect(src).toMatch(/platforms\s*=\s*\[.*Steam.*GOG.*Xbox.*Manual/s)
  })

  it('never renders unsupported platforms (comments explaining the omission are fine)', async () => {
    const src = stripComments(await read('../../app/pages/welcome.vue'))
    expect(src).not.toMatch(/PlayStation|Epic Games|Nintendo Switch/i)
  })
})

describe('no fabricated testimonial (SPEC §T73)', () => {
  it('does not render a customer quote/name', async () => {
    const src = await read('../../app/pages/welcome.vue')
    expect(src).not.toMatch(/Alex R\.|testimonial|blockquote/i)
  })
})
