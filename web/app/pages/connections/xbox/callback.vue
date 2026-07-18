<script setup lang="ts">
import type { ApiError } from '../../../utils/api'
import { XBOX_CALLBACK_PATH } from '../../../utils/connections'

const route = useRoute()
const router = useRouter()
const { connect } = useConnections()

const error = ref('')

onMounted(async () => {
  const msError = route.query.error
  if (typeof msError === 'string' && msError) {
    await router.replace(`/profile?xbox_error=${encodeURIComponent(msError)}`)
    return
  }

  const code = route.query.code
  if (typeof code !== 'string' || code === '') {
    await router.replace('/profile?xbox_error=missing_code')
    return
  }

  try {
    // AADSTS70000 (B26): must byte-for-byte match what buildXboxAuthUrl used
    // to build the authorize URL — derive from the same shared constant, NOT
    // live route.path. A static host can 301 this route to add a trailing
    // slash (it's a real prerendered directory since T76), which would
    // silently mutate route.path and break the match MS enforces at /token.
    const redirectUri = `${window.location.origin}${XBOX_CALLBACK_PATH}`
    await connect({ platform: 'xbox', code, redirect_uri: redirectUri })
    await router.replace('/profile?xbox_connected=1')
  } catch (err) {
    error.value = (err as ApiError).message
  }
})
</script>

<template>
  <main class="mx-auto max-w-md px-6 py-16 text-center">
    <p v-if="error" class="text-rose-400">{{ error }}</p>
    <p v-else class="text-slate-400">Connecting your Xbox account…</p>
  </main>
</template>
