export interface GameModeFlags {
  multiplayer?: boolean
  coop?: boolean
  localMultiplayer?: boolean
  localCoop?: boolean
}

export interface GameModeSelection {
  flags: GameModeFlags
  gameModes: string[]
}

// V40: labels whose filtering routes through the V32 bool params instead of the
// game_mode taxonomy param — the flags stay the source of truth for these concepts.
const GAME_MODE_FLAG_LABELS: Record<string, keyof GameModeFlags> = {
  Multiplayer: 'multiplayer',
  'Co-operative': 'coop',
  'Local multiplayer': 'localMultiplayer',
  'Local co-op': 'localCoop',
}

// V40: no IGDB game_modes taxonomy value exists for these — added to the combobox.
const SYNTHETIC_GAME_MODES = ['Local multiplayer', 'Local co-op']

export function unifiedGameModeOptions(facetValues: string[]): string[] {
  return [...new Set([...facetValues, ...SYNTHETIC_GAME_MODES])].sort((a, b) =>
    a.localeCompare(b),
  )
}

export function splitGameModeSelection(selected: string[]): GameModeSelection {
  return selected.reduce<GameModeSelection>(
    (acc, label) => {
      const flag = GAME_MODE_FLAG_LABELS[label]
      return flag
        ? { ...acc, flags: { ...acc.flags, [flag]: true } }
        : { ...acc, gameModes: [...acc.gameModes, label] }
    },
    { flags: {}, gameModes: [] },
  )
}

export function filterFacetOptions(options: string[], query: string): string[] {
  const needle = query.trim().toLowerCase()
  if (!needle) return options

  return options.filter((option) => option.toLowerCase().includes(needle))
}
