<script setup lang="ts">
import { computed } from 'vue'
import Button from 'primevue/button'
import { buAy, donemEkle, donemEtiketi } from '@/utils/donem'

/**
 * Ay seçici: ‹ Kasım 2026 ›. Geçmiş aylar serbest; `ileriSinir` verilirse o aydan sonrasına gidilmez.
 */
const props = withDefaults(defineProps<{ ileriSinir?: string | null }>(), { ileriSinir: undefined })
const donem = defineModel<string>({ required: true })

const sinir = computed(() => (props.ileriSinir === undefined ? buAy() : props.ileriSinir))
const ileriKapali = computed(() => sinir.value !== null && donem.value >= sinir.value)
</script>

<template>
  <div class="inline-flex items-center gap-1 rounded-full bg-white p-1 ring-1 ring-surface-200">
    <Button
      icon="pi pi-chevron-left"
      text
      rounded
      size="small"
      severity="secondary"
      class="!h-9 !w-9"
      aria-label="Önceki ay"
      @click="donem = donemEkle(donem, -1)"
    />
    <span
      class="min-w-[7.5rem] text-center text-sm font-semibold text-surface-900"
      aria-live="polite"
      data-test="ay-secici"
      >{{ donemEtiketi(donem) }}</span
    >
    <Button
      icon="pi pi-chevron-right"
      text
      rounded
      size="small"
      severity="secondary"
      class="!h-9 !w-9"
      aria-label="Sonraki ay"
      :disabled="ileriKapali"
      @click="donem = donemEkle(donem, 1)"
    />
  </div>
</template>
