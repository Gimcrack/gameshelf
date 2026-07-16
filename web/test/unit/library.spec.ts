import { describe, expect, it } from 'vitest'
import {
  buildLibraryQuery,
  formatPlaytime,
  hasDisconnectedPlatform,
  hasManualEntry,
  libraryFiltersToPreset,
  libraryStatusLabel,
  nextRating,
  ratingStars,
  showsPlaytime,
  showsRating,
  type LibraryEntry
} from '../../app/utils/library'

function entry(overrides: Partial<LibraryEntry> = {}): LibraryEntry {
  return {
    id: 1,
    igdb_id: null,
    title: 'Portal 2',
    cover_url: null,
    genres: [],
    themes: [],
    keywords: [],
    game_modes: [],
    release_date: null,
    time_to_beat_minutes: null,
    esrb_rating: null,
    multiplayer: null,
    coop: null,
    local_multiplayer: null,
    local_coop: null,
    status: 'unplayed',
    status_declared: false,
    tags: [],
    notes: null,
    rating: null,
    hidden: false,
    library_status: 'owned',
    platforms: [
      {
        platform: 'steam',
        connection_status: 'ok',
        playtime_minutes: 100,
        last_played_at: null,
        deck_status: null
      }
    ],
    total_playtime_minutes: 100,
    last_played_at: null,
    added_at: null,
    ...overrides
  }
}

describe('buildLibraryQuery', () => {
  it('maps filters to snake_case params', () => {
    const query = buildLibraryQuery({
      sort: 'playtime',
      order: 'desc',
      platform: 'gog',
      genre: 'Puzzle',
      playtimeMin: 60,
      playtimeMax: 300,
      unplayed: true
    })

    const params = new URLSearchParams(query)
    expect(params.get('sort')).toBe('playtime')
    expect(params.get('order')).toBe('desc')
    expect(params.get('platform')).toBe('gog')
    expect(params.get('genre')).toBe('Puzzle')
    expect(params.get('playtime_min')).toBe('60')
    expect(params.get('playtime_max')).toBe('300')
    expect(params.get('unplayed')).toBe('1')
  })

  it('omits unset filters', () => {
    expect(buildLibraryQuery({})).toBe('')
    expect(buildLibraryQuery({ unplayed: false })).toBe('')
  })

  // V28: include_hidden=1 reveals hidden games.
  it('maps includeHidden to include_hidden=1', () => {
    expect(buildLibraryQuery({ includeHidden: true })).toBe('include_hidden=1')
    expect(buildLibraryQuery({ includeHidden: false })).toBe('')
  })

  // T28: title search + multi-select platform (comma-string, same as tags).
  it('maps q and comma-joined multi-select platform', () => {
    const params = new URLSearchParams(buildLibraryQuery({ q: 'portal', platform: 'steam,gog' }))
    expect(params.get('q')).toBe('portal')
    expect(params.get('platform')).toBe('steam,gog')
  })

  // T36: esrb multi-select — repeated esrb[] params, same as deck_status[].
  it('maps esrb to repeated esrb[] params', () => {
    const params = new URLSearchParams(buildLibraryQuery({ esrb: ['M', 'none'] }))
    expect(params.getAll('esrb[]')).toEqual(['M', 'none'])
    expect(buildLibraryQuery({ esrb: [] })).toBe('')
  })

  // T38: library_status multi-select — repeated library_status[] params.
  it('maps libraryStatus to repeated library_status[] params', () => {
    const params = new URLSearchParams(
      buildLibraryQuery({ libraryStatus: ['wishlist', 'none'] })
    )
    expect(params.getAll('library_status[]')).toEqual(['wishlist', 'none'])
    expect(buildLibraryQuery({ libraryStatus: [] })).toBe('')
  })

  // T40: rating multi-select — repeated rating[] params.
  it('maps rating to repeated rating[] params', () => {
    const params = new URLSearchParams(buildLibraryQuery({ rating: ['5', 'none'] }))
    expect(params.getAll('rating[]')).toEqual(['5', 'none'])
    expect(buildLibraryQuery({ rating: [] })).toBe('')
  })

  // T44: collection param drives server-side preset expansion.
  it('maps collection to a collection param', () => {
    expect(buildLibraryQuery({ collection: 'unplayed' })).toBe('collection=unplayed')
    expect(buildLibraryQuery({ collection: '42' })).toBe('collection=42')
  })
})

// T44/V44: preset serialisation for "Save as collection".
describe('libraryFiltersToPreset', () => {
  it('maps active filters to snake_case preset keys', () => {
    const preset = libraryFiltersToPreset({
      sort: 'alpha',
      order: 'asc',
      platform: 'steam,gog',
      genre: 'RPG',
      gameMode: 'Single player',
      unplayed: true,
      deckStatus: ['verified'],
      esrb: ['M', 'none'],
      libraryStatus: ['owned'],
      rating: ['5'],
      multiplayer: true,
      localCoop: true,
      q: 'witch'
    })

    expect(preset).toEqual({
      sort: 'alpha',
      order: 'asc',
      platform: 'steam,gog',
      genre: 'RPG',
      game_mode: 'Single player',
      unplayed: true,
      deck_status: ['verified'],
      esrb: ['M', 'none'],
      library_status: ['owned'],
      rating: ['5'],
      multiplayer: true,
      local_coop: true,
      q: 'witch'
    })
  })

  // The view-only toggle and the collection param are never part of a preset.
  it('excludes includeHidden and collection', () => {
    expect(libraryFiltersToPreset({ includeHidden: true, collection: '3' })).toEqual({})
  })

  it('omits empty multi-selects and unset scalars', () => {
    expect(libraryFiltersToPreset({ deckStatus: [], esrb: [], unplayed: false })).toEqual({})
  })
})

describe('formatPlaytime', () => {
  // V12: null (unknown) and 0 (unplayed) render differently.
  it('distinguishes unknown from unplayed', () => {
    expect(formatPlaytime(null)).toBe('Playtime unknown')
    expect(formatPlaytime(0)).toBe('Unplayed')
  })

  it('formats minutes and hours', () => {
    expect(formatPlaytime(45)).toBe('45m')
    expect(formatPlaytime(60)).toBe('1h')
    expect(formatPlaytime(90)).toBe('1h 30m')
  })
})

describe('libraryStatusLabel', () => {
  // T54/V42: precedence owned > free > wishlist > none.
  it('maps every library_status to user-facing copy', () => {
    expect(libraryStatusLabel('owned')).toBe('Owned')
    expect(libraryStatusLabel('free')).toBe('Free-to-play')
    expect(libraryStatusLabel('wishlist')).toBe('Wishlist')
    expect(libraryStatusLabel('none')).toBe('Not owned')
  })
})

describe('hasDisconnectedPlatform', () => {
  // V13: disconnected connection surfaces as a badge on the entry.
  it('detects disconnected platforms', () => {
    expect(hasDisconnectedPlatform(entry())).toBe(false)

    const disconnected = entry({
      platforms: [
        {
          platform: 'steam',
          connection_status: 'disconnected',
          playtime_minutes: 100,
          last_played_at: null,
          deck_status: null
        }
      ]
    })
    expect(hasDisconnectedPlatform(disconnected)).toBe(true)
  })
})

describe('hasManualEntry', () => {
  // V19: manual entries expose the remove affordance.
  it('detects manually added games', () => {
    expect(hasManualEntry(entry())).toBe(false)

    const manual = entry({
      platforms: [
        {
          platform: 'manual',
          connection_status: 'ok',
          playtime_minutes: null,
          last_played_at: null,
          deck_status: null
        }
      ]
    })
    expect(hasManualEntry(manual)).toBe(true)
  })
})

describe('showsPlaytime', () => {
  // T55/V53: playtime doesn't apply to unowned (wishlist/none) entries.
  it('shows for owned and free, hides for wishlist and none', () => {
    expect(showsPlaytime(entry({ library_status: 'owned' }))).toBe(true)
    expect(showsPlaytime(entry({ library_status: 'free' }))).toBe(true)
    expect(showsPlaytime(entry({ library_status: 'wishlist' }))).toBe(false)
    expect(showsPlaytime(entry({ library_status: 'none' }))).toBe(false)
  })
})

describe('showsRating', () => {
  // T59/V57: personal rating doesn't apply to unowned (wishlist/none) entries.
  it('shows for owned and free, hides for wishlist and none', () => {
    expect(showsRating(entry({ library_status: 'owned' }))).toBe(true)
    expect(showsRating(entry({ library_status: 'free' }))).toBe(true)
    expect(showsRating(entry({ library_status: 'wishlist' }))).toBe(false)
    expect(showsRating(entry({ library_status: 'none' }))).toBe(false)
  })
})

describe('nextRating', () => {
  it('sets the clicked rating when different from current', () => {
    expect(nextRating(null, 3)).toBe(3)
    expect(nextRating(2, 4)).toBe(4)
  })

  it('clears to null when the current rating is clicked again', () => {
    expect(nextRating(3, 3)).toBeNull()
  })
})

describe('ratingStars', () => {
  it('marks all five hollow when unrated', () => {
    expect(ratingStars(null).map((s) => s.filled)).toEqual([false, false, false, false, false])
  })

  it('fills the first n stars for rating n', () => {
    expect(ratingStars(3).map((s) => s.filled)).toEqual([true, true, true, false, false])
    expect(ratingStars(3).map((s) => s.value)).toEqual([1, 2, 3, 4, 5])
  })
})
