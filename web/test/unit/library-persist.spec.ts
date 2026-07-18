import { describe, expect, it } from 'vitest'
import { readFile } from 'node:fs/promises'
import { fileURLToPath, URL } from 'node:url'

/**
 * Guards SPEC §V77/§V78/T81: library filter/sort/collection state and scroll
 * position survive in-app navigation away from and back to `/`. Text-level
 * assertions keep the suite off the Nuxt runtime — same approach as
 * responsive.spec.ts, since index.vue isn't full-mounted here (no
 * vue-router test harness in this suite).
 */

function read(relative: string): Promise<string> {
  return readFile(fileURLToPath(new URL(relative, import.meta.url)), 'utf8')
}

describe('index.vue filter state persistence (SPEC §V77)', () => {
  it('sources filter refs from useLibrary filterState, not page-local refs', async () => {
    const src = await read('../../app/pages/index.vue')
    expect(src).toMatch(/toRefs\(filterState\.value\)/)
    expect(src).not.toMatch(/const q = ref\(/)
    expect(src).not.toMatch(/const selectedCollection = ref\(/)
    // T82: vr (T80) folded into the same persisted state — no page-local ref.
    expect(src).not.toMatch(/const vr = ref\(/)
    expect(src).toMatch(/\bvr\b[\s\S]*?\} = toRefs\(filterState\.value\)/)
  })

  it('binds v-model:vr on both the static sidebar and the mobile drawer (SPEC §V62/T82)', async () => {
    const src = await read('../../app/pages/index.vue')
    const bindings = src.match(/v-model:vr="vr"/g) ?? []
    expect(bindings.length).toBe(2)
  })
})

describe('index.vue scroll position persistence (SPEC §V78)', () => {
  it('captures scrollY on route leave', async () => {
    const src = await read('../../app/pages/index.vue')
    expect(src).toMatch(/onBeforeRouteLeave\(\(\) => \{\s*scrollY\.value = window\.scrollY/)
  })

  it('restores scrollY only after fetchLibrary resolves in onMounted', async () => {
    const src = await read('../../app/pages/index.vue')
    const mountedBlock = src.match(/onMounted\(async \(\) => \{[\s\S]*?\}\)/)?.[0] ?? ''
    const fetchIndex = mountedBlock.indexOf('await fetchLibrary(filters.value)')
    const restoreIndex = mountedBlock.indexOf('window.scrollTo(0, scrollY.value)')

    expect(fetchIndex).toBeGreaterThan(-1)
    expect(restoreIndex).toBeGreaterThan(fetchIndex)
  })
})
