<script setup lang="ts">
const { user, fetchUser } = useAuth()

onMounted(async () => {
  if (!user.value) {
    await fetchUser()
  }
})
</script>

<template>
  <main class="mx-auto max-w-2xl px-6 py-8">
    <header class="mb-8 flex items-center justify-between border-b border-slate-800 pb-4">
      <h1 class="text-2xl font-bold tracking-tight">
        Your <span class="text-teal-400">profile</span>
      </h1>
      <NuxtLink
        to="/"
        class="rounded-md border border-slate-700 px-3 py-1.5 text-sm text-slate-300 transition hover:border-teal-400/60 hover:text-teal-300"
      >
        Back to library
      </NuxtLink>
    </header>

    <div class="space-y-6">
      <ProfileAccount />
      <ProfileConnections />
      <ProfileIgdbSync />
      <ProfileApiKeys />
    </div>
  </main>
</template>
