<script setup lang="ts">
import { computed } from 'vue'
import { useAileStore } from '@/stores/aile'
import { kartRengiCss } from '@/utils/kartRengi'

/**
 * Kart adı + kartın rengiyle küçük nokta. Kart adının göründüğü her yerde aynı kart aynı renkte görünür.
 * `ad` verilirse onu yazar (kart store'da yoksa bile); renk store'daki karttan gelir.
 */
const props = defineProps<{ kartId?: number | null; ad?: string | null; yalnizNokta?: boolean }>()
const aile = useAileStore()
const kart = computed(() => aile.kart(props.kartId))
const metin = computed(() => props.ad ?? kart.value?.ad ?? '')
</script>

<template>
  <span class="inline-flex min-w-0 items-center gap-1.5" data-test="kart-etiketi">
    <span
      class="h-2.5 w-2.5 shrink-0 rounded-full"
      :style="{ background: kartRengiCss(kart ?? (kartId ? { id: kartId } : null)) }"
      aria-hidden="true"
    />
    <span v-if="!yalnizNokta" class="truncate">{{ metin }}</span>
  </span>
</template>
