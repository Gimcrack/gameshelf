<script setup lang="ts">
import { computed } from 'vue'
import { libraryStatusLabel, type DeckStatus, type LibraryFacets, type LibraryStatus } from '~/utils/library'
import { unifiedGameModeOptions } from '~/utils/facets'

const props = defineProps<{ facets: LibraryFacets }>()

// T44: parent owns the save (name prompt + POST) since it also holds the
// toolbar filters (q/sort); the sidebar just surfaces the affordance.
const emit = defineEmits<{ save: [] }>()

const gameModeOptions = computed(() => unifiedGameModeOptions(props.facets.game_modes))

const platforms = defineModel<string[]>('platforms', { default: () => [] })
const genres = defineModel<string[]>('genres', { default: () => [] })
const themes = defineModel<string[]>('themes', { default: () => [] })
const keywords = defineModel<string[]>('keywords', { default: () => [] })
const gameModes = defineModel<string[]>('gameModes', { default: () => [] })
const deckStatuses = defineModel<DeckStatus[]>('deckStatuses', { default: () => [] })
// T36: multi-select; 'none' = unrated, displayed "No Rating".
const esrb = defineModel<string[]>('esrb', { default: () => [] })
// T38/V42: union per-entry status.
const libraryStatuses = defineModel<LibraryStatus[]>('libraryStatuses', { default: () => [] })
// T40: personal rating; '1'..'5' + 'none' (unrated).
const ratings = defineModel<string[]>('ratings', { default: () => [] })
const unplayed = defineModel<boolean>('unplayed', { default: false })
const showHidden = defineModel<boolean>('showHidden', { default: false })

// T36: static list — no facets source for deck status (I.api).
const DECK_STATUSES: DeckStatus[] = ['unknown', 'unsupported', 'playable', 'verified']

const ESRB_LABELS: Record<string, string> = { none: 'No Rating' }

// T38/T60: static list — no facets source for library status (I.api).
const LIBRARY_STATUSES: LibraryStatus[] = ['owned', 'free', 'shared', 'wishlist', 'none']

// T54: shared with the game-detail page badge, ⊥ duplicate label copies.
const LIBRARY_STATUS_LABELS: Record<string, string> = Object.fromEntries(
  LIBRARY_STATUSES.map((status) => [status, libraryStatusLabel(status)])
)

// T40: static list — personal rating is not a facets source (I.api).
const RATINGS: string[] = ['1', '2', '3', '4', '5', 'none']

const RATING_LABELS: Record<string, string> = {
  1: '★',
  2: '★★',
  3: '★★★',
  4: '★★★★',
  5: '★★★★★',
  none: 'Unrated'
}

// T44: the "Save as collection" affordance shows only when ≥1 sidebar filter
// is active — an empty preset isn't worth saving.
const hasActiveFilters = computed(
  () =>
    platforms.value.length > 0 ||
    genres.value.length > 0 ||
    themes.value.length > 0 ||
    keywords.value.length > 0 ||
    gameModes.value.length > 0 ||
    deckStatuses.value.length > 0 ||
    esrb.value.length > 0 ||
    libraryStatuses.value.length > 0 ||
    ratings.value.length > 0 ||
    unplayed.value
)
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
    <FacetFilter
      v-model="libraryStatuses"
      label="Library status"
      :options="LIBRARY_STATUSES"
      :labels="LIBRARY_STATUS_LABELS"
    />
    <FacetFilter v-model="ratings" label="Rating" :options="RATINGS" :labels="RATING_LABELS" />

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

    <button
      v-if="hasActiveFilters"
      type="button"
      class="rounded-md border border-teal-400/50 px-3 py-1.5 text-sm font-medium text-teal-300 transition hover:border-teal-400 hover:text-teal-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-400"
      @click="emit('save')"
    >
      Save as collection
    </button>
  </aside>
</template>
