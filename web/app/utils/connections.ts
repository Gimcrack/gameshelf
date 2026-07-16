export interface PlatformConnection {
  id: number
  platform: 'steam' | 'gog' | 'xbox'
  external_account_id: string
  last_synced_at: string | null
  status: 'pending' | 'syncing' | 'ok' | 'error' | 'error_private' | 'disconnected'
  created_at: string
}

export const GOG_REDIRECT_URI = 'https://embed.gog.com/on_login_success?origin=client'

/**
 * GOG login URL the user visits to obtain the one-time code they paste
 * back. The client id is a public identifier, not a secret.
 */
export function buildGogAuthUrl(clientId: string): string {
  const params = new URLSearchParams({
    client_id: clientId,
    redirect_uri: GOG_REDIRECT_URI,
    response_type: 'code',
    layout: 'client2'
  })

  return `https://auth.gog.com/auth?${params.toString()}`
}

/**
 * B18/V55: the login code is buried in a `code=` query param on GOG's
 * redirect landing page — hand-extracting just that substring from the
 * address bar is error-prone (stray `&session_id=...`, whole-URL pastes,
 * trailing whitespace) and GOG's token endpoint rejects anything but the
 * exact code. Accept either the bare code or the full pasted URL/query
 * string and pull `code` out of it; falls back to a trimmed raw value if
 * no `code` param is found (still handles a clean bare-code paste).
 */
export function extractGogCode(input: string): string {
  const trimmed = input.trim()
  if (trimmed === '') return ''

  const queryString = trimmed.includes('?') ? trimmed.slice(trimmed.indexOf('?') + 1) : trimmed
  const code = new URLSearchParams(queryString).get('code')

  return code !== null && code !== '' ? code : trimmed
}

/**
 * T56/B17/V54: NUXT_PUBLIC_GOG_CLIENT_ID defaults to '' when unset — a
 * missing client id must fail loud locally (distinct message) rather than
 * silently building a broken authorize URL that dead-ends at GOG's server.
 */
export function hasGogClientId(clientId: string): boolean {
  return clientId.trim() !== ''
}

/**
 * T63/I.xbox: unlike GOG (community-fixed redirect target, T6), we control
 * our own Azure AD app registration — the FE picks its own redirect_uri,
 * which the backend echoes back verbatim in the token exchange. That means
 * a real redirect flow works here, no manual code paste needed (V60: real
 * OAuth, same trust class as Steam/GOG).
 */
export const XBOX_CALLBACK_PATH = '/connections/xbox/callback'

export function buildXboxAuthUrl(clientId: string, redirectUri: string): string {
  const params = new URLSearchParams({
    client_id: clientId,
    redirect_uri: redirectUri,
    response_type: 'code',
    scope: 'XboxLive.signin offline_access'
  })

  return `https://login.microsoftonline.com/consumers/oauth2/v2.0/authorize?${params.toString()}`
}

/**
 * T63: NUXT_PUBLIC_XBOX_CLIENT_ID defaults to '' when unset — mirrors
 * hasGogClientId (B17/V54): fail loud locally rather than a dead link.
 */
export function hasXboxClientId(clientId: string): boolean {
  return clientId.trim() !== ''
}

export function connectionStatusLabel(status: PlatformConnection['status']): string {
  switch (status) {
    case 'pending':
      return 'Waiting to sync'
    case 'syncing':
      return 'Syncing…'
    case 'ok':
      return 'Connected'
    case 'error':
      return 'Sync failed'
    case 'error_private':
      return 'Profile is private'
    case 'disconnected':
      return 'Disconnected'
  }
}
