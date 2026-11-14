<script setup lang="ts">
/**
 * Varlığın listelerdeki yazımı: kod vurgulu, açıklama (ad) yanında küçük. Sığmazsa ad kısalır, kod kısalmaz.
 */
defineProps<{
  kod: string
  ad: string
}>()
</script>

<template>
  <span class="flex min-w-0 items-baseline gap-1.5">
    <span data-test="varlik-kodu" class="shrink-0 font-semibold text-surface-900">{{ kod }}</span>
    <span data-test="varlik-adi" class="min-w-0 truncate text-xs text-surface-500">{{ ad }}</span>
  </span>
</template>
