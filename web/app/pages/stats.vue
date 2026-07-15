<script setup lang="ts">
import { formatEstHours, formatEstYears, formatPace } from '../utils/stats'

const { stats, pending, error, fetchStats } = useBacklogStats()

onMounted(() => fetchStats())
</script>

<template>
  <main class="mx-auto max-w-2xl px-6 py-8">
    <header class="mb-8 flex items-center justify-between border-b border-slate-800 pb-4">
      <h1 class="text-2xl font-bold tracking-tight">
        Backlog <span class="text-teal-400">stats</span>
      </h1>
      <NuxtLink
        to="/"
        class="rounded-md border border-slate-700 px-3 py-1.5 text-sm text-slate-300 transition hover:border-teal-400/60 hover:text-teal-300"
      >
        Back to library
      </NuxtLink>
    </header>

    <p v-if="error" class="text-rose-400">{{ error }}</p>
    <p v-else-if="pending && !stats" class="text-slate-400">Crunching your backlog…</p>

    <!-- Shareable card: self-contained, screenshot-friendly. -->
    <section
      v-else-if="stats"
      class="overflow-hidden rounded-xl border border-slate-800 bg-slate-900"
    >
      <div class="h-1.5 bg-gradient-to-r from-teal-500 via-teal-400 to-teal-600" />
      <div class="p-8">
        <p class="mb-6 text-sm uppercase tracking-widest text-slate-500">Backlog forecast</p>

        <p class="mb-1 text-6xl font-bold tracking-tight text-teal-400">
          {{ formatEstYears(stats.burndown.est_years_to_clear) }}
        </p>
        <p class="mb-8 text-slate-400">
          {{
            stats.burndown.est_years_to_clear === null
              ? 'No recent play recorded — the clock hasn\'t started.'
              : 'to clear your backlog at your current pace.'
          }}
        </p>

        <dl class="grid grid-cols-3 gap-4 border-t border-slate-800 pt-6">
          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500">Unplayed</dt>
            <dd class="mt-1 text-2xl font-semibold text-slate-100">{{ stats.unplayed_count }}</dd>
          </div>
          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500">Est. to beat</dt>
            <dd class="mt-1 text-2xl font-semibold text-slate-100">
              {{ formatEstHours(stats.est_hours) }}
            </dd>
          </div>
          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500">Recent pace</dt>
            <dd class="mt-1 text-2xl font-semibold text-slate-100">
              {{ formatPace(stats.burndown.avg_hours_per_week) }}
            </dd>
          </div>
        </dl>

        <p class="mt-8 text-right text-sm font-bold tracking-tight text-slate-500">
          Game<span class="text-teal-500">Bower</span>
        </p>
      </div>
    </section>

    <p class="mt-4 text-sm text-slate-500">
      Estimates cover unplayed games with completion data; pace comes from your last four weeks
      of play.
    </p>
  </main>
</template>
