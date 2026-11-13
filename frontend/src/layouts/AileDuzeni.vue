<script setup lang="ts">
import { useRoute } from 'vue-router'

/**
 * Bütçe bölümü: üstte sekmeler (Özet · Gelirler · Kartlar · İşlemler · Faturalar · Ayarlar), altında sayfa.
 * Telefonda sekmeler yana kayar.
 */
const route = useRoute()

const SEKMELER = [
  { ad: 'aile', etiket: 'Özet', ikon: 'pi pi-chart-bar' },
  { ad: 'aile-gelirler', etiket: 'Gelirler', ikon: 'pi pi-arrow-down-left' },
  { ad: 'aile-kartlar', etiket: 'Kartlar', ikon: 'pi pi-credit-card' },
  { ad: 'aile-islemler', etiket: 'İşlemler', ikon: 'pi pi-list' },
  { ad: 'aile-faturalar', etiket: 'Faturalar', ikon: 'pi pi-bolt' },
  { ad: 'aile-ayarlar', etiket: 'Ayarlar', ikon: 'pi pi-sliders-h' },
] as const

/** Kart detayı ve ekstre incelemesi Kartlar sekmesindedir */
function seciliMi(ad: string): boolean {
  const simdiki = String(route.name ?? '')
  if (ad === 'aile-kartlar') return ['aile-kartlar', 'aile-kart', 'aile-ekstre'].includes(simdiki)

  return simdiki === ad
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <nav
      class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
      aria-label="Bütçe bölümleri"
    >
      <RouterLink
        v-for="s in SEKMELER"
        :key="s.ad"
        :to="{ name: s.ad }"
        class="flex shrink-0 items-center gap-1.5 rounded-full px-3.5 py-2 text-sm font-semibold transition-colors"
        :class="
          seciliMi(s.ad)
            ? 'bg-primary-600 text-white'
            : 'bg-white text-surface-600 ring-1 ring-surface-200'
        "
        :aria-current="seciliMi(s.ad) ? 'page' : undefined"
      >
        <i :class="s.ikon" class="text-xs" />
        {{ s.etiket }}
      </RouterLink>
    </nav>
    <RouterView />
  </div>
</template>
