<script setup lang="ts">
import Tag from 'primevue/tag'
import type { Pozisyon } from '@/api/finans'
import { VARLIK_TURLERI } from '@/api/finans'
import DegisimEtiketi from '@/components/DegisimEtiketi.vue'
import { fiyat, miktar, tl } from '@/utils/bicim'

/**
 * Varlıklar (pozisyonlar): ad, miktar, değer, kâr / zarar, bugünkü değişim. Satıra dokununca varlık sayfası açılır.
 * Maliyeti bilinmeyen kısım ve fiyatı olmayan varlık açıkça yazılır (sıfır sayılmaz).
 */
defineProps<{
  pozisyonlar: Pozisyon[]
  /** Satırda hesap adı (varlığın hangi hesapta olduğu) */
  hesapAdi?: (p: Pozisyon) => string | undefined
  /** Varlık kimliği → soldaki şeridin rengi (Özet'teki halka dilimiyle aynı; `--p-...` değişkeni) */
  renkler?: Map<number, string>
}>()
</script>

<template>
  <ul class="divide-y divide-surface-100">
    <li v-for="p in pozisyonlar" :key="`${p.hesap_id}-${p.varlik.id}`">
      <RouterLink
        :to="{ name: 'varlik', params: { id: p.varlik.id } }"
        class="relative flex items-center gap-3 px-1 py-3.5 transition-colors hover:bg-surface-50 sm:px-3 sm:py-3"
      >
        <!-- Şerit kartın sol boşluğunda durur: telefonda varlık adına yer kalsın -->
        <span
          v-if="renkler"
          data-test="pozisyon-seridi"
          class="absolute inset-y-3 -left-2.5 w-1 rounded-full sm:-left-1"
          :style="{ background: `var(${renkler.get(p.varlik.id) ?? '--p-surface-200'})` }"
        />
        <span
          class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-surface-100 text-surface-600"
          :title="VARLIK_TURLERI[p.varlik.tur].etiket"
        >
          <i :class="VARLIK_TURLERI[p.varlik.tur].ikon" />
        </span>
        <!-- Dar ekranda ad en az bu kadar yer alır; sağdaki K/Z sığmazsa yüzde alt satıra iner -->
        <span class="min-w-[5.5rem] flex-1">
          <span class="flex items-center gap-2">
            <span class="truncate font-semibold text-surface-900">{{ p.varlik.ad }}</span>
            <span class="shrink-0 text-xs text-surface-400">{{ p.varlik.kod }}</span>
          </span>
          <span class="block truncate text-xs text-surface-500">
            {{ miktar(p.miktar) }} {{ p.varlik.birim }}
            <template v-if="p.fiyat_tl !== null"> × {{ fiyat(p.fiyat_tl) }}</template>
            <template v-if="hesapAdi?.(p)"> · {{ hesapAdi(p) }}</template>
            <template v-else-if="(p.hesap_sayisi ?? 0) > 1">
              · {{ p.hesap_sayisi }} hesapta</template
            >
          </span>
          <span v-if="p.maliyeti_bilinmeyen_miktar > 0" class="block text-xs text-amber-700">
            {{ miktar(p.maliyeti_bilinmeyen_miktar) }} {{ p.varlik.birim }} maliyeti bilinmiyor
          </span>
        </span>
        <span class="flex flex-col items-end gap-0.5 text-right">
          <span
            v-if="p.deger !== null"
            class="font-semibold whitespace-nowrap text-surface-900 tabular-nums"
          >
            {{ tl(p.deger) }}
          </span>
          <Tag v-else value="Fiyat yok" severity="warn" />
          <DegisimEtiketi
            v-if="p.kz !== null"
            :tutar="p.kz"
            :yuzde="p.kz_yuzde"
            kucuk
            class="justify-end"
          />
          <span v-else class="text-xs text-surface-400">K/Z —</span>
        </span>
      </RouterLink>
    </li>
  </ul>
</template>
