import { describe, expect, it } from 'vitest'
import { existsSync } from 'node:fs'
import { fileURLToPath, URL } from 'node:url'

function pagePath(relative: string): string {
  return fileURLToPath(new URL(relative, import.meta.url))
}

describe('T52/V51: wishlist has no standalone route', () => {
  it('app/pages/wishlist.vue does not exist', () => {
    expect(existsSync(pagePath('../../app/pages/wishlist.vue'))).toBe(false)
  })
})
