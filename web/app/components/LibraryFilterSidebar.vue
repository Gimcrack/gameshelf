<script setup lang="ts">
import { computed } from 'vue'
import type { DeckStatus, LibraryFacets } from '~/utils/library'
import { excludeDedicatedGameModes } from '~/utils/facets'

const props = defineProps<{ facets: LibraryFacets }>()

const gameModeOptions = computed(() => excludeDedicatedGameModes(props.facets.game_modes))

const platforms = defineModel<string[]>('platforms', { default: () => [] })
const genres = defineModel<string[]>('genres', { default: () => [] })
const themes = defineModel<string[]>('themes', { default: () => [] })
const keywords = defineModel<string[]>('keywords', { default: () => [] })
const gameModes = defineModel<string[]>('gameModes', { default: () => [] })
const deckStatuses = defineModel<DeckStatus[]>('deckStatuses', { default: () => [] })
const esrb = defineModel<string>('esrb', { default: '' })
const unplayed = defineModel<boolean>('unplayed', { default: false })
const showHidden = defineModel<boolean>('showHidden', { default: false })
const multiplayer = defineModel<boolean>('multiplayer', { default: false })
const coop = defineModel<boolean>('coop', { default: false })
const localMultiplayer = defineModel<boolean>('localMultiplayer', { default: false })
const localCoop = defineModel<boolean>('localCoop', { default: false })

const DECK_STATUSES: DeckStatus[] = ['unknown', 'unsupported', 'playable', 'verified']
</script>

<template>
  <aside class="flex w-56 shrink-0 flex-col gap-5 text-sm text-slate-400">
    <label class="flex flex-col gap-1">
      ESRB
      <select
        v-model="esrb"
        class="rounded-md border border-slate-700 bg-slate-900 px-2 py-1.5 text-slate-100 focus:border-teal-400 focus:outline-none"
      >
        <option value="">Any</option>
        <option value="RP">RP</option>
        <option value="E">E</option>
        <option value="E10">E10+</option>
        <option value="T">T</option>
        <option value="M">M</option>
        <option value="AO">AO</option>
      </select>
    </label>

    <FacetFilter v-model="platforms" label="Platform" :options="facets.platforms" />
    <FacetFilter v-model="genres" label="Genre" :options="facets.genres" />
    <FacetFilter v-model="themes" label="Theme" :options="facets.themes" />
    <FacetFilter v-model="keywords" label="Keyword" :options="facets.keywords" />
    <FacetFilter v-model="gameModes" label="Game mode" :options="gameModeOptions" />

    <fieldset class="flex flex-col gap-1">
      <legend class="mb-1 text-slate-300">Steam Deck</legend>
      <label v-for="status in DECK_STATUSES" :key="status" class="flex items-center gap-2 pb-0.5">
        <input v-model="deckStatuses" type="checkbox" :value="status" class="accent-teal-500" />
        {{ status }}
      </label>
    </fieldset>

    <div class="flex flex-col gap-1">
      <label class="flex items-center gap-2 pb-0.5">
        <input v-model="unplayed" type="checkbox" class="accent-teal-500" />
        Unplayed only
      </label>
      <label class="flex items-center gap-2 pb-0.5">
        <input v-model="showHidden" type="checkbox" class="accent-teal-500" />
        Show hidden
      </label>
      <label class="flex items-center gap-2 pb-0.5">
        <input v-model="multiplayer" type="checkbox" class="accent-teal-500" />
        Multiplayer
      </label>
      <label class="flex items-center gap-2 pb-0.5">
        <input v-model="coop" type="checkbox" class="accent-teal-500" />
        Co-op
      </label>
      <label class="flex items-center gap-2 pb-0.5">
        <input v-model="localMultiplayer" type="checkbox" class="accent-teal-500" />
        Local multiplayer
      </label>
      <label class="flex items-center gap-2 pb-0.5">
        <input v-model="localCoop" type="checkbox" class="accent-teal-500" />
        Local co-op
      </label>
    </div>
  </aside>
</template>
