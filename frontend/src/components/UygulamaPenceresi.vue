<script setup lang="ts">
import Dialog from 'primevue/dialog'
import { useDarEkran } from '@/composables/useDarEkran'

/**
 * Uygulamanın penceresi (PrimeVue Dialog sarmalı). Masaüstünde ortada, `genislik` rem genişliğinde; telefonda alttan
 * açılan tam genişlik sayfa: üst köşeler yuvarlak, üstte tutamak, alt çentik payı, boşluğa dokununca kapanır
 * (biçim: assets/hareket.css → `.pencere`).
 *
 *   <UygulamaPenceresi v-model:visible="acik" baslik="Hesap ekle" :genislik="30">…</UygulamaPenceresi>
 */
withDefaults(defineProps<{ baslik?: string; genislik?: number }>(), {
  baslik: undefined,
  genislik: 28,
})
const gorunur = defineModel<boolean>('visible', { default: false })
const dar = useDarEkran()
</script>

<template>
  <Dialog
    v-model:visible="gorunur"
    modal
    :header="baslik"
    :position="dar ? 'bottom' : 'center'"
    :dismissable-mask="dar"
    :draggable="false"
    class="pencere"
    :style="{ width: dar ? '100%' : `min(${genislik}rem, calc(100vw - 2rem))` }"
  >
    <template v-if="$slots.header" #header><slot name="header" /></template>
    <slot />
    <template v-if="$slots.footer" #footer><slot name="footer" /></template>
  </Dialog>
</template>
