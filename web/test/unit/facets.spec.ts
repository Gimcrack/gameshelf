import { describe, expect, it } from 'vitest'
import { excludeDedicatedGameModes, filterFacetOptions } from '../../app/utils/facets'

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

describe('excludeDedicatedGameModes', () => {
  it('drops game modes covered by the dedicated boolean filters', () => {
    expect(
      excludeDedicatedGameModes([
        'Battle Royale',
        'Co-operative',
        'Massively Multiplayer Online (MMO)',
        'Multiplayer',
        'Single player',
        'Split screen',
      ]),
    ).toEqual(['Battle Royale', 'Massively Multiplayer Online (MMO)', 'Single player'])
  })

  it('returns all options when none are duplicated', () => {
    const options = ['Battle Royale', 'Single player']
    expect(excludeDedicatedGameModes(options)).toEqual(options)
  })

  it('does not mutate the input array', () => {
    const input = ['Multiplayer', 'Single player']
    excludeDedicatedGameModes(input)
    expect(input).toEqual(['Multiplayer', 'Single player'])
  })
})
