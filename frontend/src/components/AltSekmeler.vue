<script setup lang="ts">
/**
 * Bölüm içi alt sekmeler (Portföy: Özet · Hesaplar · İşlemler; Bütçe: Özet · Gelir / Gider …). Alt çizgili ince satır,
 * üst çubuğun hemen altında yapışkan; telefonda yana kayar. Sağdaki `#sag` yuvası simge düğmesi içindir (Bütçe ayarları).
 * Yükseklik `--alt-sekme-yukseklik` (2.75rem): altındaki yapışkan öğeler (ay çarkı) buna göre konumlanır.
 */
defineProps<{
  sekmeler: readonly { ad: string; etiket: string }[]
  seciliMi: (ad: string) => boolean
  etiket: string
}>()
</script>

<template>
  <div
    class="alt-sekmeler sticky top-[var(--ust-yukseklik)] z-10 -mx-4 -mt-4 flex h-[var(--alt-sekme-yukseklik)] items-stretch border-b border-surface-200/80 bg-surface-50/90 px-4 backdrop-blur"
  >
    <nav
      class="flex min-w-0 flex-1 gap-5 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
      :aria-label="etiket"
    >
      <RouterLink
        v-for="s in sekmeler"
        :key="s.ad"
        :to="{ name: s.ad }"
        class="-mb-px flex shrink-0 items-center border-b-2 text-sm font-semibold whitespace-nowrap transition-colors"
        :class="
          seciliMi(s.ad)
            ? 'border-primary-600 text-primary-700'
            : 'border-transparent text-surface-500 hover:text-surface-800'
        "
        :aria-current="seciliMi(s.ad) ? 'page' : undefined"
      >
        {{ s.etiket }}
      </RouterLink>
    </nav>
    <div v-if="$slots.sag" class="flex shrink-0 items-center pl-2">
      <slot name="sag" />
    </div>
  </div>
</template>
