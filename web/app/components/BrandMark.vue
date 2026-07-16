<script setup lang="ts">
/*
 * SPEC §C.brand T43: the "Nest Hex" brandmark — concentric open hexagons
 * around a teal alcove. The supplied colour brandmark strokes deep indigo,
 * invisible on the charcoal shell, so this derives the on-dark variant per the
 * app-icon treatment (white + #AAB4C3 + teal) with the paths verbatim.
 */
withDefaults(defineProps<{ size?: number | string }>(), { size: 32 })
</script>

<template>
  <svg
    :width="size"
    :height="size"
    viewBox="0 0 512 512"
    role="img"
    aria-label="GameBower"
    fill="none"
  >
    <g stroke-linecap="round" stroke-linejoin="round">
      <path d="M256 48 L430 148 L430 342 L382 370" stroke="#FFFFFF" stroke-width="34" />
      <path d="M256 48 L82 148 L82 342 L130 370" stroke="#FFFFFF" stroke-width="34" />
      <path d="M256 112 L374 180 L374 314 L338 334" stroke="#AAB4C3" stroke-width="30" />
      <path d="M256 112 L138 180 L138 314 L174 334" stroke="#AAB4C3" stroke-width="30" />
      <path d="M256 170 L322 208 L322 286 L300 298" stroke="#FFFFFF" stroke-width="26" />
      <path d="M256 170 L190 208 L190 286 L212 298" stroke="#FFFFFF" stroke-width="26" />
    </g>
    <path d="M220 332 V252 L256 230 L292 252 V332 Z" fill="#2FA7A0" />
  </svg>
</template>
