<script setup lang="ts">
import { computed } from 'vue'
import type { RouteLocationRaw } from 'vue-router'
import Skeleton from 'primevue/skeleton'

/**
 * Dashboard sayı kartı. `deger` null iken yükleniyor iskeleti gösterir; `to` verilirse kart bağlantı olur.
 * Renk tonları okul sisteminin docs/TASARIM.md belgesi'deki anlamlarla kullanılır (yesil = olumlu, turuncu = dikkat, kirmizi = sorun).
 */
export type KartTonu = 'primary' | 'yesil' | 'turuncu' | 'kirmizi' | 'mavi' | 'gri'

const props = withDefaults(
  defineProps<{
    etiket: string
    deger: number | string | null
    ikon: string
    ton?: KartTonu
    aciklama?: string
    to?: RouteLocationRaw
    mobilDikey?: boolean
  }>(),
  { ton: 'primary', aciklama: undefined, to: undefined, mobilDikey: false },
)

const TONLAR: Record<KartTonu, string> = {
  primary: 'bg-primary-50 text-primary-600',
  yesil: 'bg-emerald-50 text-emerald-600',
  turuncu: 'bg-amber-50 text-amber-600',
  kirmizi: 'bg-red-50 text-red-600',
  mavi: 'bg-sky-50 text-sky-600',
  gri: 'bg-surface-100 text-surface-600',
}

const gosterilen = computed(() =>
  typeof props.deger === 'number' ? props.deger.toLocaleString('tr-TR') : props.deger,
)
</script>

<template>
  <component
    :is="to ? 'RouterLink' : 'div'"
    :to="to"
    class="kart flex p-4 sm:p-5"
    :class="[
      to ? 'etkilesimli-kart' : '',
      mobilDikey
        ? 'flex-col items-center gap-2 text-center sm:flex-row sm:items-start sm:gap-4 sm:text-left'
        : 'items-start gap-4',
    ]"
  >
    <span
      class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl text-lg"
      :class="TONLAR[ton]"
    >
      <i :class="ikon" />
    </span>
    <span class="min-w-0 w-full flex-1 sm:w-auto">
      <span
        class="block text-sm font-medium text-surface-500"
        :class="mobilDikey ? 'line-clamp-2 sm:truncate' : 'truncate'"
      >
        {{ etiket }}
      </span>
      <Skeleton v-if="deger === null" width="4rem" height="2.25rem" class="mt-1" />
      <span
        v-else
        class="sayi-giris mt-0.5 block leading-tight font-bold tracking-tight text-surface-900 tabular-nums"
        :class="mobilDikey ? 'text-base whitespace-nowrap sm:text-3xl' : 'text-3xl'"
        data-test="deger"
      >
        {{ gosterilen }}
      </span>
      <span v-if="aciklama" class="mt-0.5 block truncate text-xs text-surface-500">
        {{ aciklama }}
      </span>
    </span>
  </component>
</template>
