<script setup lang="ts">
/**
 * İşlem numarası: küçük harfli, kırmızı, kalıcı kimlik (portföy işlemi İ-123, tasarruf T-12).
 */
defineProps<{ no: string }>()
</script>

<template>
  <span
    class="text-xs leading-none font-medium whitespace-nowrap text-red-600 lowercase"
    data-test="islem-no"
    title="İşlem numarası"
    >{{ no }}</span
  >
</template>
