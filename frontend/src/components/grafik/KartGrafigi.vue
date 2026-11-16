<script setup lang="ts">
import { computed } from 'vue'
import DonemGrafikKarti from '@/components/grafik/DonemGrafikKarti.vue'
import ZamanCizgiGrafigi, { type ZamanSerisi } from '@/components/grafik/ZamanCizgiGrafigi.vue'
import { TOPLAM_RENGI, sonAyIndeksi } from '@/components/grafik/renkler'
import { useDonemGrafikleri } from '@/composables/useDonemGrafikleri'
import { kartRengiCss } from '@/utils/kartRengi'

/**
 * Kartlar: ay ay tüm kartların toplam dönem sonu ödemesi ve her kartın kendi rengiyle dönem sonu ödemesi
 * (son ödeme ayı o ay olan onaylı ekstrenin dönem borcu).
 */
const { yil, veri, yukleniyor, hata } = useDonemGrafikleri()

const seriler = computed<ZamanSerisi[]>(() => {
  const k = veri.value?.kartlar
  if (!k) return []

  return [
    { anahtar: 'toplam', ad: 'Toplam', veri: k.toplam, renk: TOPLAM_RENGI, kalin: true },
    ...k.kartlar.map((c) => ({
      anahtar: `kart-${c.kart_id}`,
      ad: c.ad,
      veri: c.tutar,
      renk: kartRengiCss({ id: c.kart_id, renk: c.renk }),
    })),
  ]
})
</script>

<template>
  <DonemGrafikKarti
    v-model:yil="yil"
    baslik="Dönem sonu ödemeler"
    :yukleniyor="yukleniyor"
    :hata="hata"
    data-test="kart-grafigi"
  >
    <ZamanCizgiGrafigi
      v-if="veri"
      :etiketler="veri.etiketler"
      :seriler="seriler"
      :son-ay="sonAyIndeksi(yil)"
      :aciklama="`${yil} yılı kartların aylık dönem sonu ödemeleri`"
      bos-metin="Bu yıl için onaylı ekstre yok."
    />
    <p class="mt-2 text-[11px] text-surface-500">
      Dönem sonu ödeme: ayında kesilen onaylı ekstrenin dönem borcu.
    </p>
  </DonemGrafikKarti>
</template>
