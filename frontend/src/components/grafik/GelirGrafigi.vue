<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import SelectButton from 'primevue/selectbutton'
import DonemGrafikKarti from '@/components/grafik/DonemGrafikKarti.vue'
import ZamanCizgiGrafigi, { type ZamanSerisi } from '@/components/grafik/ZamanCizgiGrafigi.vue'
import { seriRengi, TOPLAM_RENGI, sonAyIndeksi } from '@/components/grafik/renkler'
import { useDonemGrafikleri } from '@/composables/useDonemGrafikleri'

/**
 * Gelirler: seçilen gelir türlerinin (varsayılan: Maaş ve adında "ek ders" geçenler) ay ay toplamı ve kişi başına
 * (bireysel) çizgileri. Seçili tür yoksa bütün gelirler.
 */
const { yil, veri, yukleniyor, hata } = useDonemGrafikleri()
const secili = ref<number[] | null>(null)

const turler = computed(() => veri.value?.gelir.turler ?? [])
// İlk veri gelince varsayılan seçim; kullanıcı seçtikten sonra dokunulmaz
watch(turler, (t) => {
  if (secili.value !== null || !t.length) return
  const varsayilan = t.filter((x) => /^maaş$/i.test(x.ad.trim()) || /ek ders/i.test(x.ad))
  secili.value = (varsayilan.length ? varsayilan : t).map((x) => x.gelir_turu_id)
})

const turSecenekleri = computed(() => turler.value.map((t) => ({ id: t.gelir_turu_id, ad: t.ad })))
const seciliTurler = computed(() =>
  turler.value.filter((t) => (secili.value ?? []).includes(t.gelir_turu_id)),
)

const topla = (diziler: number[][]): number[] =>
  Array.from(
    { length: 12 },
    (_, i) => Math.round(diziler.reduce((t, d) => t + (d[i] ?? 0), 0) * 100) / 100,
  )

const seriler = computed<ZamanSerisi[]>(() => {
  const g = veri.value?.gelir
  if (!g) return []
  const kapsam = seciliTurler.value
  // Hiç tür seçili değilse bütün gelirler
  const toplam = kapsam.length ? topla(kapsam.map((t) => t.toplam)) : g.toplam
  const kisiler = kapsam.length
    ? g.kisiler.map((k) => ({
        ...k,
        toplam: topla(
          kapsam.map((t) => t.kisiler.find((x) => x.uye_id === k.uye_id)?.toplam ?? []),
        ),
      }))
    : g.kisiler

  return [
    { anahtar: 'toplam', ad: 'Toplam', veri: toplam, renk: TOPLAM_RENGI, kalin: true },
    ...kisiler.map((k, i) => ({
      anahtar: `kisi-${k.uye_id}`,
      ad: k.ad,
      veri: k.toplam,
      renk: seriRengi(i),
    })),
  ]
})
</script>

<template>
  <DonemGrafikKarti
    v-model:yil="yil"
    baslik="Maaş ve ek ders (aylık)"
    :yukleniyor="yukleniyor"
    :hata="hata"
    data-test="gelir-grafigi"
  >
    <div v-if="turSecenekleri.length" class="-mx-1 mb-3 overflow-x-auto px-1 pb-1">
      <SelectButton
        v-model="secili"
        :options="turSecenekleri"
        option-label="ad"
        option-value="id"
        multiple
        size="small"
        aria-label="Gelir türleri"
        class="w-max"
        data-test="gelir-turleri"
      />
    </div>
    <ZamanCizgiGrafigi
      v-if="veri"
      :etiketler="veri.etiketler"
      :seriler="seriler"
      :son-ay="sonAyIndeksi(yil)"
      :aciklama="`${yil} yılı seçili gelir türlerinin aylık toplamı ve kişi başına dağılımı`"
      bos-metin="Bu yıl için gelir yok."
    />
  </DonemGrafikKarti>
</template>
