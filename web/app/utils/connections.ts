export interface PlatformConnection {
  id: number
  platform: 'steam' | 'gog'
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
