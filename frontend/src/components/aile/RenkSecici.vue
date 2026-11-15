<script setup lang="ts">
import { HESAP_RENKLERI } from '@/api/finans'

/**
 * Renk seçici: PrimeVue paletinden daire düğmeler (`var(--p-<renk>-500)`). Hesap ve kart formlarında ortak.
 */
withDefaults(defineProps<{ renkler?: readonly string[] }>(), { renkler: () => HESAP_RENKLERI })
const renk = defineModel<string | null>({ default: null })
</script>

<template>
  <div class="flex flex-wrap gap-2" role="group" aria-label="Renk" data-test="renk-secici">
    <button
      v-for="r in renkler"
      :key="r"
      type="button"
      class="h-8 w-8 rounded-full border-2 transition-transform"
      :class="renk === r ? 'scale-110 border-surface-900' : 'border-transparent'"
      :style="{ background: `var(--p-${r}-500)` }"
      :aria-label="`Renk ${r}`"
      :aria-pressed="renk === r"
      @click="renk = r"
    />
  </div>
</template>
