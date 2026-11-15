<script setup lang="ts">
import { computed } from 'vue'
import Message from 'primevue/message'
import { ZEKAT_UYARISI, type ZekatKalemi, type ZekatSonucu } from '@/api/aile'
import { miktar, tl } from '@/utils/bicim'

/**
 * Zekât sonucu: brüt, borç, net, nisab, zekât; kalemler hesaba göre; fiyatı olmayan kalem uyarısı (sıfır sayılmaz).
 * Hem canlı önizleme hem kayıtlı yıl (değişmez anlık görüntü) için kullanılır. Sabit uyarı her zaman görünür.
 */
const props = withDefaults(
  defineProps<{
    sonuc: ZekatSonucu
    /** Zekât günü dışında hesaplandı: sonuç tahmindir */
    tahmini?: boolean
  }>(),
  { tahmini: false },
)

const hesaplar = computed(() => {
  const grup = new Map<string, ZekatKalemi[]>()
  for (const k of props.sonuc.kalemler) {
    const liste = grup.get(k.hesap_ad) ?? []
    liste.push(k)
    grup.set(k.hesap_ad, liste)
  }

  return [...grup.entries()].map(([ad, kalemler]) => ({
    ad,
    toplam: kalemler.reduce((t, k) => t + k.hesaba_giren, 0),
    kalemler,
  }))
})

const olcutEtiketi = computed(() => (props.sonuc.nisab.olcut === 'gumus' ? 'gümüş' : 'altın'))
</script>

<template>
  <section class="flex flex-col gap-3" data-test="zekat-sonuc">
    <Message v-if="tahmini" severity="warn" :closable="false" data-test="zekat-tahmini">
      Bugün zekât günü değil: sonuç tahminidir. Nisab yalnızca zekât gününde denetlenir
      (basitleştirilmiş hesap).
    </Message>
    <Message v-if="sonuc.eksik_veri" severity="warn" :closable="false" data-test="zekat-eksik">
      Eksik veri, kesin değil: fiyatı olmayan {{ sonuc.eksikler.length }} kalem sıfır sayılmadı,
      toplama girmedi.
      <ul class="mt-1 list-disc pl-5">
        <li v-for="(e, i) in sonuc.eksikler" :key="i">
          {{ e.hesap_ad }}: {{ e.varlik_kod }} {{ e.varlik_ad }}
        </li>
      </ul>
    </Message>

    <div class="kart grid grid-cols-2 gap-3 p-4 sm:grid-cols-4 sm:p-5">
      <div>
        <p class="text-xs text-surface-500">Zekâta tabi toplam</p>
        <p class="text-base font-bold tabular-nums min-[400px]:text-lg" data-test="zekat-brut">
          {{ tl(sonuc.brut) }}
        </p>
      </div>
      <div>
        <p class="text-xs text-surface-500">Düşülen borç</p>
        <p class="text-base font-bold tabular-nums min-[400px]:text-lg" data-test="zekat-borc">
          {{ tl(sonuc.borc) }}
        </p>
      </div>
      <div>
        <p class="text-xs text-surface-500">Net değer</p>
        <p class="text-base font-bold tabular-nums min-[400px]:text-lg" data-test="zekat-net">
          {{ tl(sonuc.net) }}
        </p>
      </div>
      <div>
        <p class="text-xs text-surface-500">Nisab ({{ olcutEtiketi }})</p>
        <p class="text-base font-bold tabular-nums min-[400px]:text-lg" data-test="zekat-nisab">
          {{ tl(sonuc.nisab.tl) }}
        </p>
      </div>
      <p class="col-span-2 text-xs text-surface-500 sm:col-span-4">
        Nisab = {{ miktar(sonuc.nisab.gram) }} gr {{ olcutEtiketi }} ×
        {{ tl(sonuc.nisab.birim_fiyat) }}.
        <template v-if="!sonuc.borc_dusuldu && sonuc.borc === 0"> Borç düşme kapalı. </template>
      </p>
    </div>

    <div
      class="kart p-4 sm:p-5"
      :class="sonuc.nisab_ustu ? 'bg-emerald-50' : 'bg-surface-50'"
      data-test="zekat-sonuc-kutusu"
    >
      <template v-if="sonuc.nisab_ustu">
        <p class="text-xs text-emerald-800">Nisabı aşıyor: ödenecek zekât</p>
        <p class="text-2xl font-bold text-emerald-800 tabular-nums" data-test="zekat-tutar">
          {{ tl(sonuc.zekat) }}
        </p>
        <p v-if="sonuc.oran_binde" class="text-xs text-emerald-800">
          Net değerin %{{ (sonuc.oran_binde / 10).toLocaleString('tr-TR') }}'i
        </p>
      </template>
      <template v-else>
        <p class="text-sm font-semibold text-surface-700" data-test="zekat-alti">
          Net değer nisabın altında: zekât çıkmıyor.
        </p>
      </template>
    </div>

    <div v-if="sonuc.borclar.length" class="kart p-4" data-test="zekat-borclar">
      <h3 class="bolum-etiketi mb-2">Düşülen borçlar</h3>
      <ul class="divide-y divide-surface-100 text-sm">
        <li v-for="(b, i) in sonuc.borclar" :key="i" class="flex justify-between gap-2 py-1.5">
          <span class="min-w-0 truncate">{{ b.ad }}</span>
          <span class="whitespace-nowrap tabular-nums">{{ tl(b.tutar) }}</span>
        </li>
      </ul>
    </div>

    <div class="flex flex-col gap-3" data-test="zekat-kalemler">
      <div v-for="h in hesaplar" :key="h.ad" class="kart p-4">
        <div class="mb-1 flex items-baseline justify-between gap-2">
          <h3 class="min-w-0 truncate font-bold text-surface-900">{{ h.ad }}</h3>
          <span class="font-semibold whitespace-nowrap tabular-nums">{{ tl(h.toplam) }}</span>
        </div>
        <ul class="divide-y divide-surface-100">
          <li
            v-for="(k, i) in h.kalemler"
            :key="i"
            class="flex items-start justify-between gap-3 py-2 text-sm"
            :class="k.dahil_edilen ? '' : 'text-surface-400'"
          >
            <span class="min-w-0">
              <span class="block truncate font-medium">{{ k.varlik_kod }} {{ k.varlik_ad }}</span>
              <span class="block text-xs">
                {{ miktar(k.miktar) }}
                <template v-if="k.dislanma_nedeni"> · {{ k.dislanma_nedeni }}</template>
                <template v-else-if="k.kapsam_yuzde < 100">
                  · %{{ k.kapsam_yuzde }} sayıldı</template
                >
              </span>
            </span>
            <span class="text-right whitespace-nowrap tabular-nums">
              <template v-if="k.deger === null"
                ><span class="text-amber-700">Fiyat yok</span></template
              >
              <template v-else>{{ tl(k.deger) }}</template>
            </span>
          </li>
        </ul>
      </div>
      <p v-if="!sonuc.kalemler.length" class="text-sm text-surface-500">
        Portföyde açık pozisyon yok.
      </p>
    </div>

    <ul v-if="sonuc.notlar?.length" class="list-disc pl-5 text-xs text-surface-500">
      <li v-for="(n, i) in sonuc.notlar" :key="i">{{ n }}</li>
    </ul>

    <p
      class="rounded-2xl bg-amber-50 p-3 text-xs font-medium text-amber-900"
      data-test="zekat-uyari"
    >
      {{ ZEKAT_UYARISI }}
    </p>
  </section>
</template>
