<script setup lang="ts">
import { computed, ref, watch } from 'vue'

/**
 * Kişinin yuvarlak resmi; resim yoksa ya da açılamazsa baş harfler (`renk`: Tailwind / PrimeVue renk adı, ör. teal).
 * Ad hep yanında yazdığı için resim süs sayılır (`alt` boş).
 */
const props = withDefaults(
  defineProps<{
    ad: string
    resim?: string | null
    renk?: string | null
    boyut?: 'xs' | 'sm' | 'md' | 'lg'
  }>(),
  { resim: null, renk: null, boyut: 'sm' },
)

const BOYUTLAR = {
  xs: 'h-5 w-5 text-[9px]',
  sm: 'h-7 w-7 text-[11px]',
  md: 'h-12 w-12 text-base',
  lg: 'h-20 w-20 text-2xl',
} as const

const bozuk = ref(false)
watch(
  () => props.resim,
  () => {
    bozuk.value = false
  },
)

const basHarfler = computed(
  () =>
    props.ad
      .trim()
      .split(/\s+/)
      .slice(0, 2)
      .map((s) => s.charAt(0).toLocaleUpperCase('tr-TR'))
      .join('') || '?',
)
</script>

<template>
  <img
    v-if="resim && !bozuk"
    :src="resim"
    alt=""
    loading="lazy"
    data-test="kisi-resmi"
    class="shrink-0 rounded-full bg-surface-100 object-cover"
    :class="BOYUTLAR[boyut]"
    @error="bozuk = true"
  />
  <span
    v-else
    aria-hidden="true"
    data-test="kisi-bas-harf"
    class="inline-flex shrink-0 items-center justify-center rounded-full leading-none font-semibold text-white select-none"
    :class="BOYUTLAR[boyut]"
    :style="{ background: `var(--p-${renk ?? 'primary'}-500)` }"
    >{{ basHarfler }}</span
  >
</template>
