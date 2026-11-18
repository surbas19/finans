<script setup lang="ts">
import { useRoute } from 'vue-router'
import AltSekmeler from '@/components/AltSekmeler.vue'

/**
 * Bütçe bölümü: üstte yapışkan alt sekmeler (Özet · Gelir / Gider · Tasarruf · Kartlar · İşlemler · Faturalar), sağda
 * ⚙ Bütçe ayarları; altında sayfa. Sayfaların ay çarkı (AySecici) sekmelerin hemen altında yapışkan durur.
 */
const route = useRoute()

const SEKMELER = [
  { ad: 'aile', etiket: 'Özet' },
  { ad: 'aile-gelir-gider', etiket: 'Gelir / Gider' },
  { ad: 'aile-tasarruf', etiket: 'Tasarruf' },
  { ad: 'aile-kartlar', etiket: 'Kartlar' },
  { ad: 'aile-islemler', etiket: 'İşlemler' },
  { ad: 'aile-faturalar', etiket: 'Faturalar' },
] as const

/** Kart detayı, ekstreler (aktarım merkezi, inceleme, banka hesabı önizlemesi) Kartlar sekmesindedir */
function seciliMi(ad: string): boolean {
  const simdiki = String(route.name ?? '')
  if (ad === 'aile-kartlar')
    return [
      'aile-kartlar',
      'aile-kart',
      'aile-ekstre',
      'aile-ekstreler',
      'aile-hesap-ekstresi',
    ].includes(simdiki)

  return simdiki === ad
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <AltSekmeler :sekmeler="SEKMELER" :secili-mi="seciliMi" etiket="Bütçe bölümleri">
      <template #sag>
        <RouterLink
          :to="{ name: 'aile-ayarlar' }"
          class="flex h-9 w-9 items-center justify-center rounded-full transition-colors hover:bg-surface-100"
          :class="
            route.name === 'aile-ayarlar' ? 'bg-primary-50 text-primary-700' : 'text-surface-500'
          "
          aria-label="Bütçe ayarları"
          title="Bütçe ayarları"
          :aria-current="route.name === 'aile-ayarlar' ? 'page' : undefined"
          data-test="butce-ayarlar"
        >
          <i class="pi pi-cog" />
        </RouterLink>
      </template>
    </AltSekmeler>
    <RouterView />
  </div>
</template>
