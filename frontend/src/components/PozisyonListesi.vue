<script setup lang="ts">
import Tag from 'primevue/tag'
import type { Pozisyon } from '@/api/finans'
import { VARLIK_TURLERI } from '@/api/finans'
import DegisimEtiketi from '@/components/DegisimEtiketi.vue'
import VarlikAdi from '@/components/VarlikAdi.vue'
import { fiyat, miktar, tl } from '@/utils/bicim'

/**
 * Varlıklar (pozisyonlar): ad, miktar, değer, kâr / zarar, bugünkü değişim. Satıra dokununca varlık sayfası açılır.
 * Maliyeti bilinmeyen kısım ve fiyatı olmayan varlık açıkça yazılır (sıfır sayılmaz).
 */
const props = defineProps<{
  pozisyonlar: Pozisyon[]
  /** Satırda hesap adı (varlığın hangi hesapta olduğu) */
  hesapAdi?: (p: Pozisyon) => string | undefined
  /** Varlık kimliği → soldaki şeridin rengi (Özet'teki halka dilimiyle aynı; `--p-...` değişkeni) */
  renkler?: Map<number, string>
  /** Her satırın yanında "Genel toplama dahil" düğmesi (yalnız hesaba bağlı satırlarda) */
  degistirilebilir?: boolean
}>()
const emit = defineEmits<{
  dahilDegistir: [p: Pozisyon, dahil: boolean]
  aktar: [p: Pozisyon]
}>()

/** Miktar × fiyat · hesap. Nakitte miktar yazılmaz (değer yeterli); bireysel emeklilikte ödenen tutar. */
function altSatir(p: Pozisyon): string {
  const tur = p.varlik.tur
  let ilk: string | null = `${miktar(p.miktar)} ${p.varlik.birim}`
  if (p.fiyat_tl !== null) ilk += ` × ${fiyat(p.fiyat_tl)}`
  if (tur === 'nakit') ilk = null
  if (tur === 'bes') ilk = p.maliyet !== null ? `Ödenen ${tl(p.maliyet)}` : null
  const hesap =
    props.hesapAdi?.(p) || ((p.hesap_sayisi ?? 0) > 1 ? `${p.hesap_sayisi} hesapta` : null)

  return [ilk, hesap].filter(Boolean).join(' · ')
}
</script>

<template>
  <ul class="divide-y divide-surface-100">
    <li
      v-for="p in pozisyonlar"
      :key="`${p.hesap_id}-${p.varlik.id}`"
      class="flex items-center"
      :class="p.dahil === false ? 'opacity-55' : ''"
      :data-dahil="p.dahil === false ? 'hayir' : 'evet'"
    >
      <RouterLink
        :to="{ name: 'varlik', params: { id: p.varlik.id } }"
        class="relative flex min-w-0 flex-1 items-center gap-2 px-1 py-3.5 sm:gap-3 transition-colors hover:bg-surface-50 sm:px-3 sm:py-3"
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
        <span class="min-w-[4.5rem] flex-1">
          <span class="flex items-center gap-1.5">
            <VarlikAdi :kod="p.varlik.kod" :ad="p.varlik.ad" />
            <Tag
              v-if="p.dahil === false"
              value="Hariç"
              severity="secondary"
              data-test="haric-etiketi"
            />
          </span>
          <span class="block truncate text-xs text-surface-500">{{ altSatir(p) }}</span>
          <span v-if="p.maliyeti_bilinmeyen_miktar > 0" class="block text-xs text-amber-700">
            <template v-if="p.varlik.tur === 'bes'">Kâr yüzdesi girilmedi</template>
            <template v-else>
              {{ miktar(p.maliyeti_bilinmeyen_miktar) }} {{ p.varlik.birim }} maliyeti bilinmiyor
            </template>
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
          <!-- Nakdin kârı / zararı olmaz -->
          <template v-if="p.varlik.tur !== 'nakit'">
            <DegisimEtiketi
              v-if="p.kz !== null"
              :tutar="p.kz"
              :yuzde="p.kz_yuzde"
              kucuk
              class="justify-end"
            />
            <span v-else class="text-xs text-surface-400">K/Z —</span>
          </template>
        </span>
      </RouterLink>
      <!-- Başka hesaba aktar (bireysel emeklilik aktarılamaz) -->
      <button
        v-if="degistirilebilir && p.hesap_id !== null && p.varlik.tur !== 'bes'"
        type="button"
        class="flex w-9 shrink-0 flex-col items-center gap-0.5 rounded-xl py-1.5 text-surface-500 transition-colors hover:bg-surface-100 sm:w-11"
        :aria-label="`${p.varlik.ad} başka hesaba aktarılsın`"
        data-test="aktar-dugmesi"
        @click="emit('aktar', p)"
      >
        <i class="pi pi-arrow-right-arrow-left text-base" />
        <span class="hidden text-[10px] leading-none font-semibold sm:block">Aktar</span>
      </button>
      <!-- Genel toplama dahil: kapalıysa satır toplamlara ve zekâta girmez -->
      <button
        v-if="degistirilebilir && p.hesap_id !== null"
        type="button"
        class="flex w-9 shrink-0 flex-col items-center gap-0.5 rounded-xl py-1.5 text-surface-500 transition-colors hover:bg-surface-100 sm:ml-1 sm:w-11"
        :aria-label="
          p.dahil === false
            ? `${p.varlik.ad} genel toplama eklensin`
            : `${p.varlik.ad} genel toplamdan çıkarılsın`
        "
        :aria-pressed="p.dahil !== false"
        data-test="dahil-dugmesi"
        @click="emit('dahilDegistir', p, p.dahil === false)"
      >
        <i
          :class="p.dahil === false ? 'pi pi-eye-slash' : 'pi pi-eye text-primary-600'"
          class="text-base"
        />
        <span class="hidden text-[10px] leading-none font-semibold sm:block">{{
          p.dahil === false ? 'Hariç' : 'Dahil'
        }}</span>
      </button>
    </li>
  </ul>
</template>
