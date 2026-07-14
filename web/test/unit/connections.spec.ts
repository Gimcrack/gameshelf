import { describe, expect, it } from 'vitest'
import {
  buildGogAuthUrl,
  connectionStatusLabel,
  GOG_REDIRECT_URI
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

describe('connectionStatusLabel', () => {
  it('maps every status to user-facing copy', () => {
    expect(connectionStatusLabel('ok')).toBe('Connected')
    expect(connectionStatusLabel('error_private')).toBe('Profile is private')
    expect(connectionStatusLabel('disconnected')).toBe('Disconnected')
    expect(connectionStatusLabel('syncing')).toBe('Syncing…')
  })
})
