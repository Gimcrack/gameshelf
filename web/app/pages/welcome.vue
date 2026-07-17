<script setup lang="ts">
// T73: adopted from landing-mockup.png's 3 feature cards — replaces the
// old "steps" copy, same card treatment.
const features = [
  {
    title: 'Aggregate',
    body: 'Connect all your accounts and bring your games together.',
    color: 'bg-teal-500/70',
    glow: 'shadow-[0_0_24px_rgba(45,197,158,0.35)] ring-teal-500/30'
  },
  {
    title: 'Organize',
    body: 'Powerful filters, collections, rating, and playtime tracking.',
    color: 'bg-violet-400/70',
    glow: 'shadow-[0_0_24px_rgba(167,139,250,0.35)] ring-violet-400/30'
  },
  {
    title: 'Discover',
    body: 'Find hidden gems and decide what to play next.',
    color: 'bg-teal-400/70',
    glow: 'shadow-[0_0_24px_rgba(45,197,158,0.35)] ring-teal-500/30'
  }
] as const

// T73/V71: only platforms this app actually integrates — mockup's
// PlayStation/Epic Games/Nintendo Switch logos dropped, not supported.
const platforms = ['Steam', 'GOG', 'Xbox', '+ Manual add'] as const

// T62/§C.brand: Nest Hex honeycomb strip — replaces the old book-spine bar
// (leftover pre-rebrand visual). Fixed hex aspect ratio (unlike the old
// bar's tall/thin bars — a stretched hexagon just reads as a lozenge);
// alternating rows echo real honeycomb tessellation. Colors cycle the
// brand palette.
const hexTiles = [
  'bg-teal-500/80',
  'bg-slate-700',
  'bg-violet-400/70',
  'bg-teal-400/70',
  'bg-slate-600',
  'bg-teal-600/70',
  'bg-violet-500/70',
  'bg-slate-700',
  'bg-teal-300/70',
  'bg-slate-600',
  'bg-teal-500/70',
  'bg-violet-400/60'
] as const
</script>

<template>
  <main class="mx-auto max-w-4xl px-6 pb-20 pt-14">
    <header class="mb-16 flex items-center justify-between">
      <p class="flex items-center gap-2 text-lg font-bold tracking-tight">
        <BrandMark :size="26" />
        <BrandWordmark />
      </p>
      <!-- T73: anchor-nav scoped to sections that exist on this page —
           ⊥ Pricing/Blog/Discover from the mockup (no target for any). -->
      <nav class="hidden items-center gap-6 text-sm text-slate-400 sm:flex">
        <a href="#features" class="hover:text-teal-300">Features</a>
        <a href="#platforms" class="hover:text-teal-300">Platforms</a>
      </nav>
      <div class="flex items-center gap-3">
        <NuxtLink to="/login" class="text-sm text-slate-400 hover:text-teal-300">
          Log in
        </NuxtLink>
        <NuxtLink
          to="/register"
          class="rounded-md bg-teal-500 px-3 py-1.5 text-sm font-semibold text-slate-950 transition hover:bg-teal-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-400"
        >
          Get started
        </NuxtLink>
      </div>
    </header>

    <section class="mb-16 text-center">
      <p
        class="mx-auto mb-6 inline-block rounded-full border border-teal-500/40 px-4 py-1.5 text-xs font-semibold uppercase tracking-widest text-teal-300"
      >
        Your games. Organized.
      </p>
      <h1 class="mx-auto max-w-2xl text-4xl font-bold leading-tight tracking-tight sm:text-5xl">
        Every game you own,
        <br>
        organized in <span class="text-teal-400">one</span> library.
      </h1>
      <p class="mx-auto mt-5 max-w-xl text-lg text-slate-400">
        Bring together games from every platform and storefront. Search, filter, organize, and
        discover what to play next.
      </p>
      <div class="mt-8 flex items-center justify-center gap-4">
        <NuxtLink
          to="/register"
          class="rounded-md bg-teal-500 px-6 py-3 font-semibold text-slate-950 shadow-[0_0_30px_rgba(45,197,158,0.35)] transition hover:bg-teal-400 hover:shadow-[0_0_40px_rgba(45,197,158,0.5)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-400"
        >
          Build your library
        </NuxtLink>
        <a
          href="#screenshot"
          class="inline-flex items-center gap-2 rounded-md border border-slate-700 px-6 py-3 font-semibold text-slate-300 transition hover:border-teal-400/60 hover:text-teal-300"
        >
          <span aria-hidden="true">▶</span>
          See how it works
        </a>
      </div>

      <!-- T72/V70: real product screenshot, demo-seeded data only.
           T73/V70: generic browser-chrome dots — same neutral color, ⊥
           macOS red/amber/green — reads as "a screenshot" without
           impersonating one specific OS/browser. -->
      <div
        id="screenshot"
        class="mx-auto mt-14 max-w-3xl overflow-hidden rounded-lg border border-slate-800 shadow-[0_25px_50px_-12px_rgba(0,0,0,0.5),0_0_120px_20px_rgba(45,197,158,0.12),0_0_180px_60px_rgba(124,58,237,0.08)]"
      >
        <div class="flex items-center gap-1.5 border-b border-slate-800 bg-slate-900 px-4 py-3" aria-hidden="true">
          <span class="size-2.5 rounded-full bg-slate-700" />
          <span class="size-2.5 rounded-full bg-slate-700" />
          <span class="size-2.5 rounded-full bg-slate-700" />
        </div>
        <img
          src="/screenshots/library-hero.webp"
          alt="GameBower library view showing a deduplicated game collection with covers, ratings, and playtime"
          loading="lazy"
          class="w-full"
        />
      </div>

      <!--
        overflow-hidden guards narrow viewports where shrink-0 tiles don't
        fit (clips left/right, decorative + aria-hidden so that's fine).
        pb-4 matters: CSS forces overflow-y to compute as auto (which still
        clips) whenever overflow-x isn't visible — no way to hide one axis
        and leave the other genuinely visible. The padding keeps the
        honeycomb-offset (translate-y) tiles fully inside this box instead.
      -->
      <div class="mt-14 flex items-center justify-center gap-1 overflow-hidden pb-4" aria-hidden="true">
        <div
          v-for="(tile, i) in hexTiles"
          :key="i"
          class="hex-tile size-9 shrink-0 sm:size-11"
          :class="[tile, i % 2 === 1 ? 'translate-y-3 sm:translate-y-4' : '']"
        />
      </div>
    </section>

    <!-- T73/V71: only actually-integrated platforms — Steam, GOG, Xbox,
         manual-add. Mockup's PlayStation/Epic Games/Nintendo Switch logos
         dropped (⊥ supported; PlayStation explicitly rejected V60). -->
    <section id="platforms" class="mb-16 text-center">
      <p class="mb-6 text-sm font-semibold uppercase tracking-widest text-slate-500">
        One library across every platform
      </p>
      <div class="flex flex-wrap items-center justify-center gap-3">
        <span
          v-for="platform in platforms"
          :key="platform"
          class="rounded-md border border-slate-800 bg-slate-900 px-4 py-2 text-sm font-semibold uppercase tracking-wide text-slate-400"
        >
          {{ platform }}
        </span>
      </div>
    </section>

    <section id="features" class="grid gap-8 sm:grid-cols-3">
      <div
        v-for="feature in features"
        :key="feature.title"
        class="rounded-lg border border-slate-800 bg-slate-900 p-6"
      >
        <div
          class="mb-4 flex size-14 items-center justify-center rounded-xl ring-1"
          :class="feature.glow"
          aria-hidden="true"
        >
          <div class="hex-tile size-7" :class="feature.color" />
        </div>
        <h2 class="mb-2 font-semibold text-teal-300">{{ feature.title }}</h2>
        <p class="text-sm leading-relaxed text-slate-400">{{ feature.body }}</p>
      </div>
    </section>

    <footer class="mt-16 text-center text-sm text-slate-500">
      Free while in beta. Your accounts stay yours — GameBower only reads your library.
    </footer>
  </main>
</template>

<style scoped>
/* T62: flat-top/bottom hexagon, echoes BrandMark's nested-hex shape. */
.hex-tile {
  clip-path: polygon(25% 0%, 75% 0%, 100% 50%, 75% 100%, 25% 100%, 0% 50%);
}
</style>
