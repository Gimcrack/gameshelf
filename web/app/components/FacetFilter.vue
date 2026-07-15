<script setup lang="ts">
import { computed } from 'vue'
import { facetControlKind } from '../utils/facets'
import FacetCombobox from './FacetCombobox.vue'

const props = defineProps<{ label: string; options: string[] }>()

const selected = defineModel<string[]>({ default: () => [] })

const kind = computed(() => facetControlKind(props.options.length))
</script>

<template>
  <FacetCombobox v-if="kind === 'combobox'" v-model="selected" :label="label" :options="options" />

  <fieldset v-else-if="options.length" class="flex flex-col gap-1">
    <legend class="mb-1 text-slate-300">{{ label }}</legend>
    <label v-for="option in options" :key="option" class="flex items-center gap-2 pb-0.5">
      <input v-model="selected" type="checkbox" :value="option" class="accent-teal-500" />
      {{ option }}
    </label>
  </fieldset>
</template>
