export type DeckStatus = 'unknown' | 'unsupported' | 'playable' | 'verified'

export interface LibraryPlatform {
  platform: string
  connection_status: string
  playtime_minutes: number | null
  last_played_at: string | null
  // T26/V31: Steam-only; null = never successfully checked.
  deck_status: DeckStatus | null
}

export type GameStatus = 'unplayed' | 'playing' | 'finished' | 'abandoned'

// T38/V42: owned > free > wishlist > none precedence, computed server-side.
export type LibraryStatus = 'owned' | 'free' | 'wishlist' | 'none'

export interface LibraryEntry {
  id: number
  igdb_id: number | null
  title: string
  cover_url: string | null
  genres: string[]
  themes: string[]
  keywords: string[]
  game_modes: string[]
  release_date: string | null
  time_to_beat_minutes: number | null
  // T27/V33: null = unrated | non-ESRB-market.
  esrb_rating: string | null
  // T27/V32: null = not yet fetched, distinct from false.
  multiplayer: boolean | null
  coop: boolean | null
  local_multiplayer: boolean | null
  local_coop: boolean | null
  status: GameStatus
  status_declared: boolean
  tags: string[]
  notes: string | null
  rating: number | null
  hidden: boolean
  // T38/V42: wishlist/none entries carry empty platforms + null playtime.
  library_status: LibraryStatus
  platforms: LibraryPlatform[]
  total_playtime_minutes: number | null
  last_played_at: string | null
  added_at: string | null
}

export interface LibraryMetaUpdate {
  status?: GameStatus
  tags?: string[]
  notes?: string | null
  rating?: number | null
  hidden?: boolean
}

export type LibrarySort = 'alpha' | 'playtime' | 'last_played' | 'added'

export interface LibraryFilters {
  sort?: LibrarySort
  order?: 'asc' | 'desc'
  // T28: multi-select, comma-separated (same convention as `tags`).
  platform?: string
  genre?: string
  theme?: string
  keyword?: string
  gameMode?: string
  playtimeMin?: number
  playtimeMax?: number
  unplayed?: boolean
  includeHidden?: boolean
  deckStatus?: DeckStatus[]
  // T36: multi-select; 'none' = unrated (esrb_rating null).
  esrb?: string[]
  // T38: multi-select on the union's per-entry status.
  libraryStatus?: LibraryStatus[]
  // T40: multi-select; '1'..'5' + 'none' (unrated, rating null).
  rating?: string[]
  multiplayer?: boolean
  coop?: boolean
  localMultiplayer?: boolean
  localCoop?: boolean
  q?: string
  // T44: system slug | custom collection id — the API expands a saved
  // filter preset, with explicit params winning (LibraryController).
  collection?: string
}

export interface LibraryFacets {
  genres: string[]
  themes: string[]
  keywords: string[]
  game_modes: string[]
  platforms: string[]
  // T36: distinct in-library values; 'none' present when unrated games exist.
  esrb_ratings: string[]
}

/** Maps camelCase filter state to the API's snake_case query string. */
export function buildLibraryQuery(filters: LibraryFilters): string {
  const params = new URLSearchParams()

  if (filters.sort) params.set('sort', filters.sort)
  if (filters.order) params.set('order', filters.order)
  if (filters.platform) params.set('platform', filters.platform)
  if (filters.genre) params.set('genre', filters.genre)
  if (filters.theme) params.set('theme', filters.theme)
  if (filters.keyword) params.set('keyword', filters.keyword)
  if (filters.gameMode) params.set('game_mode', filters.gameMode)
  if (filters.playtimeMin !== undefined) params.set('playtime_min', String(filters.playtimeMin))
  if (filters.playtimeMax !== undefined) params.set('playtime_max', String(filters.playtimeMax))
  if (filters.unplayed) params.set('unplayed', '1')
  if (filters.includeHidden) params.set('include_hidden', '1')
  for (const status of filters.deckStatus ?? []) params.append('deck_status[]', status)
  for (const rating of filters.esrb ?? []) params.append('esrb[]', rating)
  for (const status of filters.libraryStatus ?? []) params.append('library_status[]', status)
  for (const rating of filters.rating ?? []) params.append('rating[]', rating)
  if (filters.multiplayer) params.set('multiplayer', '1')
  if (filters.coop) params.set('coop', '1')
  if (filters.localMultiplayer) params.set('local_multiplayer', '1')
  if (filters.localCoop) params.set('local_coop', '1')
  if (filters.q) params.set('q', filters.q)
  if (filters.collection) params.set('collection', filters.collection)

  return params.toString()
}

/**
 * T44/V44: serialise the active sidebar filter state into a snake_case preset
 * object for POST /api/collections {type: 'filter', filters}. Mirrors
 * buildLibraryQuery's key mapping so a saved collection re-checks the same
 * facets. `collection` and the `include_hidden` view toggle are excluded — a
 * preset is filters only, not a nested collection or a view setting.
 */
export function libraryFiltersToPreset(filters: LibraryFilters): Record<string, unknown> {
  const preset: Record<string, unknown> = {}

  if (filters.sort) preset.sort = filters.sort
  if (filters.order) preset.order = filters.order
  if (filters.platform) preset.platform = filters.platform
  if (filters.genre) preset.genre = filters.genre
  if (filters.theme) preset.theme = filters.theme
  if (filters.keyword) preset.keyword = filters.keyword
  if (filters.gameMode) preset.game_mode = filters.gameMode
  if (filters.playtimeMin !== undefined) preset.playtime_min = filters.playtimeMin
  if (filters.playtimeMax !== undefined) preset.playtime_max = filters.playtimeMax
  if (filters.unplayed) preset.unplayed = true
  if (filters.deckStatus?.length) preset.deck_status = filters.deckStatus
  if (filters.esrb?.length) preset.esrb = filters.esrb
  if (filters.libraryStatus?.length) preset.library_status = filters.libraryStatus
  if (filters.rating?.length) preset.rating = filters.rating
  if (filters.multiplayer) preset.multiplayer = true
  if (filters.coop) preset.coop = true
  if (filters.localMultiplayer) preset.local_multiplayer = true
  if (filters.localCoop) preset.local_coop = true
  if (filters.q) preset.q = filters.q

  return preset
}

const DECK_STATUS_LABELS: Record<DeckStatus, string> = {
  unknown: 'Deck: unknown',
  unsupported: 'Deck: unsupported',
  playable: 'Deck: playable',
  verified: 'Deck: verified'
}

export function deckStatusLabel(status: DeckStatus): string {
  return DECK_STATUS_LABELS[status]
}

/** T54/V42: precedence owned > free > wishlist > none. */
const LIBRARY_STATUS_LABELS: Record<LibraryStatus, string> = {
  owned: 'Owned',
  free: 'Free-to-play',
  wishlist: 'Wishlist',
  none: 'Not owned'
}

export function libraryStatusLabel(status: LibraryStatus): string {
  return LIBRARY_STATUS_LABELS[status]
}

/**
 * T39: click a star to set that rating; click the current rating to clear it (null).
 */
export function nextRating(current: number | null, clicked: number): number | null {
  return clicked === current ? null : clicked
}

/** T39: 5 star slots (1..5), filled up to the current rating (null → all hollow). */
export function ratingStars(rating: number | null): { value: number; filled: boolean }[] {
  return [1, 2, 3, 4, 5].map((value) => ({ value, filled: value <= (rating ?? 0) }))
}

/**
 * V12: null playtime is unknown — rendered distinctly from 0 (unplayed).
 */
export function formatPlaytime(minutes: number | null): string {
  if (minutes === null) return 'Playtime unknown'
  if (minutes === 0) return 'Unplayed'
  if (minutes < 60) return `${minutes}m`

  const hours = Math.floor(minutes / 60)
  const rest = minutes % 60
  return rest === 0 ? `${hours}h` : `${hours}h ${rest}m`
}

/** V13: any owning connection disconnected → entry shows a badge. */
export function hasDisconnectedPlatform(entry: LibraryEntry): boolean {
  return entry.platforms.some((p) => p.connection_status === 'disconnected')
}

/** V19: manually added entries are removable from the library UI. */
export function hasManualEntry(entry: LibraryEntry): boolean {
  return entry.platforms.some((p) => p.platform === 'manual')
}

/** T55/V53: playtime doesn't apply to unowned entries — owned|free only. */
export function showsPlaytime(entry: LibraryEntry): boolean {
  return entry.library_status === 'owned' || entry.library_status === 'free'
}
