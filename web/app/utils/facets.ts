export type FacetControlKind = 'checkbox' | 'combobox'

/** Facets with more options than this render as a searchable combobox (§C.facet-combobox). */
export const FACET_COMBOBOX_THRESHOLD = 5

export function facetControlKind(optionCount: number): FacetControlKind {
  return optionCount > FACET_COMBOBOX_THRESHOLD ? 'combobox' : 'checkbox'
}

export function filterFacetOptions(options: string[], query: string): string[] {
  const needle = query.trim().toLowerCase()
  if (!needle) return options

  return options.filter((option) => option.toLowerCase().includes(needle))
}
