import { describe, expect, it } from 'vitest'
import { facetControlKind, filterFacetOptions } from '../../app/utils/facets'

describe('facetControlKind', () => {
  it('uses a checkbox list for five or fewer options', () => {
    expect(facetControlKind(0)).toBe('checkbox')
    expect(facetControlKind(1)).toBe('checkbox')
    expect(facetControlKind(5)).toBe('checkbox')
  })

  it('uses a combobox for more than five options', () => {
    expect(facetControlKind(6)).toBe('combobox')
    expect(facetControlKind(100)).toBe('combobox')
  })
})

describe('filterFacetOptions', () => {
  const options = ['Adventure', 'Role-playing (RPG)', 'Shooter', 'Turn-based strategy (TBS)']

  it('returns all options for an empty or whitespace query', () => {
    expect(filterFacetOptions(options, '')).toEqual(options)
    expect(filterFacetOptions(options, '   ')).toEqual(options)
  })

  it('matches case-insensitive substrings', () => {
    expect(filterFacetOptions(options, 'rpg')).toEqual(['Role-playing (RPG)'])
    expect(filterFacetOptions(options, 'SHOOT')).toEqual(['Shooter'])
  })

  it('matches on inner substrings, not just prefixes', () => {
    expect(filterFacetOptions(options, 'strategy')).toEqual(['Turn-based strategy (TBS)'])
  })

  it('returns an empty array when nothing matches', () => {
    expect(filterFacetOptions(options, 'zzz')).toEqual([])
  })

  it('does not mutate the input array', () => {
    const input = ['B', 'A']
    filterFacetOptions(input, 'a')
    expect(input).toEqual(['B', 'A'])
  })
})
