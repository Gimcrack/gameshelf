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
  platform?: 'steam' | 'gog'
  genre?: string
  theme?: string
  keyword?: string
  gameMode?: string
  playtimeMin?: number
  playtimeMax?: number
  unplayed?: boolean
  includeHidden?: boolean
  deckStatus?: DeckStatus[]
  esrb?: string
  multiplayer?: boolean
  coop?: boolean
  localMultiplayer?: boolean
  localCoop?: boolean
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
  if (filters.esrb) params.set('esrb', filters.esrb)
  if (filters.multiplayer) params.set('multiplayer', '1')
  if (filters.coop) params.set('coop', '1')
  if (filters.localMultiplayer) params.set('local_multiplayer', '1')
  if (filters.localCoop) params.set('local_coop', '1')

  return params.toString()
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
