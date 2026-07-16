import { describe, expect, it } from 'vitest'
import { readFile } from 'node:fs/promises'
import { fileURLToPath, URL } from 'node:url'

/**
 * Guards SPEC §V63/V67/T70: the achievements_summary completion badge on
 * GameCard + the full achievement list on the game detail page. Text-level
 * assertions keep the suite off the Nuxt runtime, same approach as
 * theme.spec.ts / brand.spec.ts.
 */

function read(relative: string): Promise<string> {
  return readFile(fileURLToPath(new URL(relative, import.meta.url)), 'utf8')
}

describe('GameCard achievement badge (SPEC §V67)', () => {
  it('renders the completion count only when achievements_summary is present', async () => {
    const src = await read('../../app/components/GameCard.vue')
    expect(src).toMatch(/v-if="entry\.achievements_summary"/)
    expect(src).toContain('entry.achievements_summary.unlocked')
    expect(src).toContain('entry.achievements_summary.total')
  })
})

describe('game detail full achievement list (SPEC §T70)', () => {
  it('fetches achievements alongside the entry on load', async () => {
    const src = await read('../../app/pages/games/[id].vue')
    expect(src).toContain('fetchAchievements(gameId.value)')
  })

  it('renders name/description/icon, dims locked items, and shows unlock date when present', async () => {
    const src = await read('../../app/pages/games/[id].vue')
    expect(src).toMatch(/v-if="achievements && achievements\.length"/)
    expect(src).toContain("'opacity-50': !a.unlocked")
    expect(src).toContain('a.icon_url')
    expect(src).toMatch(/v-if="a\.unlocked && a\.unlocked_at"/)
  })
})
