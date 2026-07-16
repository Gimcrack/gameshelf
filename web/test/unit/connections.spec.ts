import { describe, expect, it } from 'vitest'
import {
  buildGogAuthUrl,
  connectionStatusLabel,
  extractGogCode,
  GOG_REDIRECT_URI,
  hasGogClientId
} from '../../app/utils/connections'

describe('buildGogAuthUrl', () => {
  it('builds the GOG login URL with the public client id', () => {
    const url = new URL(buildGogAuthUrl('46899977096215655'))

    expect(url.origin).toBe('https://auth.gog.com')
    expect(url.pathname).toBe('/auth')
    expect(url.searchParams.get('client_id')).toBe('46899977096215655')
    expect(url.searchParams.get('redirect_uri')).toBe(GOG_REDIRECT_URI)
    expect(url.searchParams.get('response_type')).toBe('code')
  })
})

describe('extractGogCode', () => {
  it('extracts code from the full pasted redirect URL', () => {
    const url = 'https://embed.gog.com/on_login_success?origin=client&code=abc123&session_id=xyz'

    expect(extractGogCode(url)).toBe('abc123')
  })

  it('extracts code from a bare pasted query string', () => {
    expect(extractGogCode('origin=client&code=abc123')).toBe('abc123')
  })

  it('URL-decodes the code value', () => {
    const url = 'https://embed.gog.com/on_login_success?code=abc%2B123%3D&origin=client'

    expect(extractGogCode(url)).toBe('abc+123=')
  })

  it('falls back to the trimmed raw value when pasted as a bare code', () => {
    expect(extractGogCode('  abc123  ')).toBe('abc123')
  })

  it('returns empty string for empty input', () => {
    expect(extractGogCode('   ')).toBe('')
  })
})

describe('hasGogClientId', () => {
  // T56/B17/V54: empty/whitespace client id → fail loud locally, not a dead link.
  it('is false for empty or whitespace-only, true otherwise', () => {
    expect(hasGogClientId('')).toBe(false)
    expect(hasGogClientId('   ')).toBe(false)
    expect(hasGogClientId('46899977096215655')).toBe(true)
  })
})

describe('connectionStatusLabel', () => {
  it('maps every status to user-facing copy', () => {
    expect(connectionStatusLabel('ok')).toBe('Connected')
    expect(connectionStatusLabel('error_private')).toBe('Profile is private')
    expect(connectionStatusLabel('disconnected')).toBe('Disconnected')
    expect(connectionStatusLabel('syncing')).toBe('Syncing…')
  })
})
