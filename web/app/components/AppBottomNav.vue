<script setup lang="ts">
const route = useRoute()

// V62: bottom nav is the mobile relocation of the top-of-page nav links
// (Discover/Stats/Profile in index.vue) plus a Library entry, so small
// viewports keep 1-tap access to every primary section.
const links = [
  { to: '/', label: 'Library' },
  { to: '/discover', label: 'Discover' },
  { to: '/stats', label: 'Stats' },
  { to: '/profile', label: 'Profile' }
]

function isActive(to: string): boolean {
  return to === '/' ? route.path === '/' : route.path.startsWith(to)
}
</script>

<template>
  <nav
    class="fixed inset-x-0 bottom-0 z-40 flex justify-around border-t border-slate-800 bg-slate-900/95 py-2 backdrop-blur md:hidden"
    aria-label="Primary"
  >
    <NuxtLink
      v-for="link in links"
      :key="link.to"
      :to="link.to"
      class="flex-1 rounded-md px-2 py-1.5 text-center text-xs font-medium transition"
      :class="isActive(link.to) ? 'text-teal-300' : 'text-slate-400 hover:text-teal-300'"
    >
      {{ link.label }}
    </NuxtLink>
  </nav>
</template>
