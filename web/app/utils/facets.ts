export function filterFacetOptions(options: string[], query: string): string[] {
  const needle = query.trim().toLowerCase()
  if (!needle) return options

  return options.filter((option) => option.toLowerCase().includes(needle))
}
