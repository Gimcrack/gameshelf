<script setup lang="ts">
// V62: bottom nav shows only once logged in (mirrors the guest/user split
// already used by index.vue's top nav) - guests on login/register/welcome
// never see it.
const { user } = useAuth()
</script>

<template>
  <div class="min-h-screen bg-slate-950 text-slate-100" :class="{ 'pb-16 md:pb-0': user }">
    <NuxtRouteAnnouncer />
    <NuxtPage />
    <AppBottomNav v-if="user" />
  </div>
</template>
