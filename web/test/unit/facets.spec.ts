import { describe, expect, it } from 'vitest'
import {
  filterFacetOptions,
  splitGameModeSelection,
  unifiedGameModeOptions,
} from '../../app/utils/facets'

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

  // T36: labels map raw values to display text — search matches the label.
  it('matches against display labels when provided', () => {
    const labels = { none: 'No Rating' }
    expect(filterFacetOptions(['E', 'M', 'none'], 'no rating', labels)).toEqual(['none'])
    expect(filterFacetOptions(['E', 'M', 'none'], 'none', labels)).toEqual([])
  })

  it('does not mutate the input array', () => {
    const input = ['B', 'A']
    filterFacetOptions(input, 'a')
    expect(input).toEqual(['B', 'A'])
  })
})

describe('unifiedGameModeOptions', () => {
  it('merges facet values with the synthetic local options, sorted', () => {
    expect(unifiedGameModeOptions(['Multiplayer', 'Battle Royale', 'Single player'])).toEqual([
      'Battle Royale',
      'Local co-op',
      'Local multiplayer',
      'Multiplayer',
      'Single player',
    ])
  })

  it('includes the synthetic options even for an empty facet list', () => {
    expect(unifiedGameModeOptions([])).toEqual(['Local co-op', 'Local multiplayer'])
  })

  it('does not duplicate a synthetic value already present in the facets', () => {
    expect(unifiedGameModeOptions(['Local co-op'])).toEqual(['Local co-op', 'Local multiplayer'])
  })

  it('does not mutate the input array', () => {
    const input = ['Single player', 'Battle Royale']
    unifiedGameModeOptions(input)
    expect(input).toEqual(['Single player', 'Battle Royale'])
  })
})

describe('splitGameModeSelection', () => {
  it('routes bool-backed labels to V32 flags and the rest to taxonomy values', () => {
    expect(
      splitGameModeSelection([
        'Multiplayer',
        'Co-operative',
        'Local multiplayer',
        'Local co-op',
        'Split screen',
        'Battle Royale',
      ]),
    ).toEqual({
      flags: { multiplayer: true, coop: true, localMultiplayer: true, localCoop: true },
      gameModes: ['Split screen', 'Battle Royale'],
    })
  })

  it('returns empty flags and values for an empty selection', () => {
    expect(splitGameModeSelection([])).toEqual({ flags: {}, gameModes: [] })
  })

  it('omits flags for unselected bool-backed labels', () => {
    expect(splitGameModeSelection(['Co-operative', 'Single player'])).toEqual({
      flags: { coop: true },
      gameModes: ['Single player'],
    })
  })

  it('does not mutate the input array', () => {
    const input = ['Multiplayer', 'Single player']
    splitGameModeSelection(input)
    expect(input).toEqual(['Multiplayer', 'Single player'])
  })
})
