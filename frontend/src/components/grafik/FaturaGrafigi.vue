<script setup lang="ts">
import { computed, ref } from 'vue'
import SelectButton from 'primevue/selectbutton'
import DonemGrafikKarti from '@/components/grafik/DonemGrafikKarti.vue'
import ZamanCizgiGrafigi, { type ZamanSerisi } from '@/components/grafik/ZamanCizgiGrafigi.vue'
import { seriRengi, TOPLAM_RENGI, sonAyIndeksi } from '@/components/grafik/renkler'
import { useDonemGrafikleri } from '@/composables/useDonemGrafikleri'

/**
 * Faturalar: ay ay toplam fatura ve her fatura türü; "Gelire oran" görünümünde aylık kazancın yüzdesi olarak
 * (gelir girilmemiş ayın oranı boş kalır, uydurulmaz).
 */
const { yil, veri, yukleniyor, hata } = useDonemGrafikleri()
const gorunum = ref<'tutar' | 'oran'>('tutar')
const GORUNUMLER = [
  { deger: 'tutar', etiket: 'Tutar' },
  { deger: 'oran', etiket: 'Gelire oran %' },
]

const seriler = computed<ZamanSerisi[]>(() => {
  const f = veri.value?.faturalar
  if (!f) return []
  const oran = gorunum.value === 'oran'

  return [
    {
      anahtar: 'toplam',
      ad: 'Toplam',
      veri: oran ? f.toplam_oran : f.toplam,
      renk: TOPLAM_RENGI,
      kalin: true,
    },
    ...f.turler.map((t, i) => ({
      anahtar: `tur-${t.fatura_turu_id}`,
      ad: t.ad,
      veri: oran ? t.oran : t.tutar,
      renk: seriRengi(i),
    })),
  ]
})
</script>

<template>
  <DonemGrafikKarti
    v-model:yil="yil"
    baslik="Aylık faturalar"
    :yukleniyor="yukleniyor"
    :hata="hata"
    data-test="fatura-grafigi"
  >
    <SelectButton
      v-model="gorunum"
      :options="GORUNUMLER"
      option-label="etiket"
      option-value="deger"
      :allow-empty="false"
      size="small"
      aria-label="Grafik görünümü"
      class="mb-3 w-full sm:w-auto [&>.p-togglebutton]:flex-1"
      data-test="fatura-gorunum"
    />
    <ZamanCizgiGrafigi
      v-if="veri"
      :etiketler="veri.etiketler"
      :seriler="seriler"
      :birim="gorunum === 'oran' ? 'yuzde' : 'tl'"
      :son-ay="sonAyIndeksi(yil)"
      :aciklama="`${yil} yılı aylık fatura toplamı ve fatura türleri`"
      bos-metin="Bu yıl için fatura yok."
    />
    <p v-if="gorunum === 'oran'" class="mt-2 text-[11px] text-surface-500">
      Oran: o ayın fatura tutarı / o ayın geliri. Geliri girilmemiş ayda oran gösterilmez.
    </p>
  </DonemGrafikKarti>
</template>
