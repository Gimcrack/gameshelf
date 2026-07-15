import { describe, expect, it } from 'vitest'
import {
  buildLibraryQuery,
  formatPlaytime,
  hasDisconnectedPlatform,
  hasManualEntry,
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
