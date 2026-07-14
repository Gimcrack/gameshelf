export interface BacklogBurndown {
  avg_hours_per_week: number
  est_years_to_clear: number | null
}

export interface BacklogStats {
  unplayed_count: number
  est_hours: number
  burndown: BacklogBurndown
}

export function formatEstHours(hours: number): string {
  return `${hours.toLocaleString('en-US')} h`
}

export function formatPace(hoursPerWeek: number): string {
  return `${hoursPerWeek} h/wk`
}

export function formatEstYears(years: number | null): string {
  return years === null ? '—' : `${years} yrs`
}
