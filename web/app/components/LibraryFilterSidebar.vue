<script setup lang="ts">
import { computed } from 'vue'
import type { DeckStatus, LibraryFacets } from '~/utils/library'
import { unifiedGameModeOptions } from '~/utils/facets'

const props = defineProps<{ facets: LibraryFacets }>()

const gameModeOptions = computed(() => unifiedGameModeOptions(props.facets.game_modes))

const platforms = defineModel<string[]>('platforms', { default: () => [] })
const genres = defineModel<string[]>('genres', { default: () => [] })
const themes = defineModel<string[]>('themes', { default: () => [] })
const keywords = defineModel<string[]>('keywords', { default: () => [] })
const gameModes = defineModel<string[]>('gameModes', { default: () => [] })
const deckStatuses = defineModel<DeckStatus[]>('deckStatuses', { default: () => [] })
// T36: multi-select; 'none' = unrated, displayed "No Rating".
const esrb = defineModel<string[]>('esrb', { default: () => [] })
const unplayed = defineModel<boolean>('unplayed', { default: false })
const showHidden = defineModel<boolean>('showHidden', { default: false })

// T36: static list — no facets source for deck status (I.api).
const DECK_STATUSES: DeckStatus[] = ['unknown', 'unsupported', 'playable', 'verified']

const ESRB_LABELS: Record<string, string> = { none: 'No Rating' }
</script>

<template>
  <aside class="flex w-56 shrink-0 flex-col gap-5 text-sm text-slate-400">
    <FacetFilter v-model="platforms" label="Platform" :options="facets.platforms" />
    <FacetFilter v-model="genres" label="Genre" :options="facets.genres" />
    <FacetFilter v-model="themes" label="Theme" :options="facets.themes" />
    <FacetFilter v-model="keywords" label="Keyword" :options="facets.keywords" />
    <FacetFilter v-model="gameModes" label="Game mode" :options="gameModeOptions" />
    <FacetFilter v-model="esrb" label="ESRB" :options="facets.esrb_ratings" :labels="ESRB_LABELS" />
    <FacetFilter v-model="deckStatuses" label="Steam Deck" :options="DECK_STATUSES" />

    <div class="flex flex-col gap-1">
      <label class="flex items-center gap-2 pb-0.5">
        <input v-model="unplayed" type="checkbox" class="accent-teal-500" />
        Unplayed only
      </label>
      <label class="flex items-center gap-2 pb-0.5">
        <input v-model="showHidden" type="checkbox" class="accent-teal-500" />
        Show hidden
      </label>
    </div>
  </aside>
</template>
