<script setup lang="ts">
import { computed } from 'vue'
import { degisimRengi, isaretliTl, yuzde as yuzdeBicimi } from '@/utils/bicim'

/**
 * Artış / düşüş: yeşil ok yukarı, kırmızı ok aşağı. `tutar` verilirse TL (yanında yüzde parantez içinde),
 * yalnızca `yuzde` verilirse yüzde. Tutar ve yüzde ayrı parçalardır: dar kutuda (telefon) yüzde alt satıra iner,
 * sayfayı yana taşırmaz. Sağa dayalı yerde `class="justify-end"` verilir.
 */
const props = defineProps<{
  yuzde?: number | null
  tutar?: number | null
  kucuk?: boolean
}>()

const tutarVar = computed(() => props.tutar !== undefined)
const deger = computed(() => (tutarVar.value ? (props.tutar ?? null) : (props.yuzde ?? null)))
const ok = computed(() => {
  if (deger.value === null || Math.abs(deger.value) < 0.005) return ''

  return deger.value > 0 ? 'pi pi-arrow-up-right' : 'pi pi-arrow-down-right'
})
</script>

<template>
  <span
    class="inline-flex flex-wrap items-center gap-x-1 font-semibold tabular-nums"
    :class="[degisimRengi(deger), kucuk ? 'text-xs' : 'text-sm']"
  >
    <span class="inline-flex items-center gap-1 whitespace-nowrap">
      <i v-if="ok" :class="ok" class="text-[0.7em]" />
      {{ tutarVar ? isaretliTl(props.tutar) : yuzdeBicimi(props.yuzde) }}
    </span>
    <span
      v-if="tutarVar && props.yuzde !== undefined && props.yuzde !== null"
      class="font-medium whitespace-nowrap opacity-80"
    >
      ({{ yuzdeBicimi(props.yuzde) }})
    </span>
  </span>
</template>
