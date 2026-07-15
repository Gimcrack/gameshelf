<script setup lang="ts">
import { computed, ref } from 'vue'
import {
  Combobox,
  ComboboxButton,
  ComboboxInput,
  ComboboxLabel,
  ComboboxOption,
  ComboboxOptions
} from '@headlessui/vue'
import { filterFacetOptions } from '../utils/facets'

const props = defineProps<{ label: string; options: string[] }>()

const selected = defineModel<string[]>({ default: () => [] })

const query = ref('')
const filtered = computed(() => filterFacetOptions(props.options, query.value))

function removeValue(value: string) {
  selected.value = selected.value.filter((v) => v !== value)
}
</script>

<template>
  <Combobox v-model="selected" multiple as="div" class="flex flex-col gap-1">
    <ComboboxLabel class="text-slate-300">{{ label }}</ComboboxLabel>

    <div class="relative">
      <ComboboxInput
        class="w-full rounded-md border border-slate-700 bg-slate-900 px-2 py-1.5 pr-7 text-slate-100 placeholder-slate-500 focus:border-teal-400 focus:outline-none"
        :placeholder="`Search ${label.toLowerCase()}…`"
        @change="query = $event.target.value"
      />
      <ComboboxButton class="absolute inset-y-0 right-0 flex items-center px-2 text-slate-500">
        <span aria-hidden="true">⌄</span>
      </ComboboxButton>

      <ComboboxOptions
        class="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded-md border border-slate-700 bg-slate-900 py-1 shadow-lg"
      >
        <li v-if="!filtered.length" class="px-2 py-1 text-slate-500">No matches</li>
        <ComboboxOption
          v-for="option in filtered"
          :key="option"
          v-slot="{ active, selected: isSelected }"
          :value="option"
          as="template"
        >
          <li
            class="flex cursor-pointer items-center gap-2 px-2 py-1"
            :class="active ? 'bg-slate-800 text-slate-100' : 'text-slate-400'"
          >
            <span class="w-3 text-teal-400">{{ isSelected ? '✓' : '' }}</span>
            {{ option }}
          </li>
        </ComboboxOption>
      </ComboboxOptions>
    </div>

    <ul v-if="selected.length" class="flex flex-wrap gap-1">
      <li
        v-for="value in selected"
        :key="value"
        class="flex items-center gap-1 rounded bg-slate-800 px-1.5 py-0.5 text-xs text-slate-200"
      >
        {{ value }}
        <button
          type="button"
          class="text-slate-500 hover:text-teal-400"
          :aria-label="`Remove ${value}`"
          @click="removeValue(value)"
        >
          ×
        </button>
      </li>
    </ul>
  </Combobox>
</template>
