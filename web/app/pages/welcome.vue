<script setup lang="ts">
const steps = [
  {
    title: 'Connect',
    body: 'Link your Steam and GOG accounts. GameBower pulls every game you own, automatically.'
  },
  {
    title: 'One shelf',
    body: 'The same game on two stores becomes one entry. Playtime, genres, and covers included.'
  },
  {
    title: 'Clear the backlog',
    body: 'Tag, filter, and sort what you actually want to play. See how deep the pile really is.'
  }
] as const

const spines = [
  'h-24 bg-teal-500/80',
  'h-28 bg-slate-700',
  'h-20 bg-slate-600',
  'h-28 bg-teal-400/70',
  'h-24 bg-slate-700',
  'h-32 bg-slate-600',
  'h-24 bg-teal-600/70',
  'h-28 bg-slate-700',
  'h-20 bg-teal-300/60',
  'h-28 bg-slate-600',
  'h-24 bg-slate-700',
  'h-32 bg-teal-500/70'
] as const
</script>

<template>
  <main class="mx-auto max-w-4xl px-6 pb-20 pt-14">
    <header class="mb-16 flex items-center justify-between">
      <p class="flex items-center gap-2 text-lg font-bold tracking-tight">
        <BrandMark :size="26" />
        <BrandWordmark />
      </p>
      <NuxtLink
        to="/login"
        class="rounded-md border border-slate-700 px-3 py-1.5 text-sm text-slate-300 transition hover:border-teal-400/60 hover:text-teal-300"
      >
        Log in
      </NuxtLink>
    </header>

    <section class="mb-16 text-center">
      <div
        class="mx-auto mb-8 flex size-20 items-center justify-center rounded-3xl bg-gradient-to-br from-brand-indigo to-slate-950 ring-1 ring-slate-800 sm:size-24"
        aria-hidden="true"
      >
        <BrandMark :size="56" />
      </div>
      <h1 class="mx-auto max-w-2xl text-4xl font-bold leading-tight tracking-tight sm:text-5xl">
        Every game you own.
        <span class="text-teal-400">One shelf.</span>
      </h1>
      <p class="mx-auto mt-5 max-w-xl text-lg text-slate-400">
        Your library is scattered across Steam and GOG. GameBower gathers it in one
        place, deduplicates it, and shows you what's still waiting to be played.
      </p>
      <div class="mt-8 flex items-center justify-center gap-4">
        <NuxtLink
          to="/register"
          class="rounded-md bg-teal-500 px-6 py-3 font-semibold text-slate-950 transition hover:bg-teal-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-400"
        >
          Start your shelf
        </NuxtLink>
        <NuxtLink to="/login" class="text-sm text-slate-400 hover:text-teal-300">
          I have an account
        </NuxtLink>
      </div>

      <div
        class="mt-14 flex items-end justify-center gap-1.5 border-b-4 border-slate-800 pb-0"
        aria-hidden="true"
      >
        <div
          v-for="(spine, i) in spines"
          :key="i"
          class="w-4 rounded-t-sm sm:w-5"
          :class="spine"
        />
      </div>
    </section>

    <section class="grid gap-8 sm:grid-cols-3">
      <div
        v-for="step in steps"
        :key="step.title"
        class="rounded-lg border border-slate-800 bg-slate-900 p-6"
      >
        <h2 class="mb-2 font-semibold text-teal-300">{{ step.title }}</h2>
        <p class="text-sm leading-relaxed text-slate-400">{{ step.body }}</p>
      </div>
    </section>

    <footer class="mt-16 text-center text-sm text-slate-500">
      Free while in beta. Your accounts stay yours — GameBower only reads your library.
    </footer>
  </main>
</template>
