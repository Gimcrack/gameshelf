import { describe, expect, it } from 'vitest'
import { formatEstHours, formatEstYears, formatPace } from '../../app/utils/stats'

describe('stats formatters', () => {
  it('formats estimated hours', () => {
    expect(formatEstHours(0)).toBe('0 h')
    expect(formatEstHours(104)).toBe('104 h')
    expect(formatEstHours(1250)).toBe('1,250 h')
  })

  it('formats pace in hours per week', () => {
    expect(formatPace(0)).toBe('0 h/wk')
    expect(formatPace(3.5)).toBe('3.5 h/wk')
  })

  it('formats years to clear, with em dash for unknown', () => {
    expect(formatEstYears(2.9)).toBe('2.9 yrs')
    expect(formatEstYears(0.4)).toBe('0.4 yrs')
    // Null means no recent play — pace unknown, never zero (mirrors V12 spirit).
    expect(formatEstYears(null)).toBe('—')
  })
})
