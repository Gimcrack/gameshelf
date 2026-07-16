<script setup lang="ts">
import type { ApiError } from '../../../utils/api'

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
    // Must exactly match the redirect_uri the authorize URL was built with.
    const redirectUri = `${window.location.origin}${route.path}`
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
