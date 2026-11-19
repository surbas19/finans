<script setup lang="ts">
import { computed } from 'vue'
import { useAileStore } from '@/stores/aile'
import { turRengi } from '@/utils/fatura'

/** Fatura türü ikonu: türün ikonu, türün sabit renginde açık zeminli kutu. `soluk`: henüz gelmemiş fatura (kesikli) */
const props = defineProps<{ turId: number; soluk?: boolean }>()
const aile = useAileStore()
const renk = computed(() => turRengi(props.turId))
</script>

<template>
  <span
    class="flex size-9 shrink-0 items-center justify-center rounded-xl"
    :class="soluk ? 'border-[1.5px] border-dashed border-surface-300 text-surface-400' : ''"
    :style="
      soluk ? undefined : { background: `var(--p-${renk}-100)`, color: `var(--p-${renk}-700)` }
    "
    aria-hidden="true"
  >
    <i :class="aile.tur(turId)?.ikon ?? 'pi pi-file'" class="text-sm" />
  </span>
</template>
