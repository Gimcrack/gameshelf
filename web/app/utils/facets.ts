// game_modes values already covered by the dedicated multiplayer/co-op boolean filters (V40)
const DEDICATED_GAME_MODE_FILTERS = ['Multiplayer', 'Co-operative', 'Split screen']

export function excludeDedicatedGameModes(options: string[]): string[] {
  return options.filter((option) => !DEDICATED_GAME_MODE_FILTERS.includes(option))
}

export function filterFacetOptions(options: string[], query: string): string[] {
  const needle = query.trim().toLowerCase()
  if (!needle) return options

  return options.filter((option) => option.toLowerCase().includes(needle))
}
