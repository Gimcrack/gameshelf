import { describe, expect, it } from 'vitest'
import { readFile } from 'node:fs/promises'
import { fileURLToPath, URL } from 'node:url'

/**
 * Guards SPEC §V62/T66: below `md`, the filter sidebar collapses into a
 * drawer and primary nav relocates to a fixed bottom bar. Text-level
 * assertions keep the suite off the Nuxt runtime, same approach as
 * theme.spec.ts / brand.spec.ts.
 */

function read(relative: string): Promise<string> {
  return readFile(fileURLToPath(new URL(relative, import.meta.url)), 'utf8')
}

describe('AppBottomNav (SPEC §V62)', () => {
  it('is a fixed, mobile-only bottom bar with links to every primary section', async () => {
    const src = await read('../../app/components/AppBottomNav.vue')
    expect(src).toContain('fixed')
    expect(src).toContain('bottom-0')
    expect(src).toContain('md:hidden')
    expect(src).toContain("to: '/'")
    expect(src).toContain("to: '/discover'")
    expect(src).toContain("to: '/stats'")
    expect(src).toContain("to: '/profile'")
  })
})

describe('app shell mounts the bottom nav (SPEC §V62)', () => {
  it('renders AppBottomNav for logged-in users only', async () => {
    const src = await read('../../app/app.vue')
    expect(src).toMatch(/<AppBottomNav v-if="user"/)
  })
})

describe('index.vue top nav hides at small width (SPEC §V62)', () => {
  it('wraps the Discover/Stats/Profile links so they hide below md', async () => {
    const src = await read('../../app/pages/index.vue')
    expect(src).toMatch(/hidden items-center gap-3 md:flex[\s\S]*Discover[\s\S]*Stats[\s\S]*Profile/)
  })
})

describe('index.vue filter sidebar collapses into a drawer (SPEC §V62)', () => {
  it('hides the static sidebar below md and offers a mobile toggle button', async () => {
    const src = await read('../../app/pages/index.vue')
    expect(src).toMatch(/<LibraryFilterSidebar\s+class="hidden md:flex"/)
    expect(src).toContain('@click="filterDrawerOpen = true"')
  })

  it('renders a teleported drawer that closes on backdrop tap, Escape, and a close button', async () => {
    const src = await read('../../app/pages/index.vue')
    expect(src).toContain('<Teleport to="body">')
    expect(src).toContain('v-if="filterDrawerOpen"')
    expect(src).toContain('@click="closeFilterDrawer"')
    expect(src).toContain("event.key === 'Escape'")
    expect(src).toMatch(/aria-label="Close filters"/)
  })
})
