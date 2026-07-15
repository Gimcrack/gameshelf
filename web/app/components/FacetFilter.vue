<script setup lang="ts">
import FacetCombobox from './FacetCombobox.vue'

defineProps<{ label: string; options: string[] }>()

const selected = defineModel<string[]>({ default: () => [] })
</script>

<template>
  <FacetCombobox v-if="options.length" v-model="selected" :label="label" :options="options" />
</template>
