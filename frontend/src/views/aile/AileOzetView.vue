<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import AySecici from '@/components/aile/AySecici.vue'
import KisiEtiketi from '@/components/KisiEtiketi.vue'
import GelirFormu from '@/components/aile/GelirFormu.vue'
import VeriGrafigi from '@/components/VeriGrafigi.vue'
import {
  aileApi,
  GELIR_TURLERI,
  type AylikOzet,
  type BeklenenGelir,
  type GelirGirdisi,
  type GelirKaynagi,
} from '@/api/aile'
import { useSonIstek } from '@/composables/useSonIstek'
import { useAileStore } from '@/stores/aile'
import { bugun, tarih, tl } from '@/utils/bicim'
import { buAy } from '@/utils/donem'

/**
 * Bütçe aylık özeti: kişi kişi ve hane geliri (beklenenler ayrı), kategorilere göre gider, fatura toplamları (hizmet
 * ayı), gelir − gider; uyarılar. Toplamların hangi kurala göre hesaplandığı sayfada açıklanır.
 */
const aile = useAileStore()
const router = useRouter()
const sonIstek = useSonIstek()
const donem = ref(buAy())
const ozet = ref<AylikOzet | null>(null)
const kaynaklar = ref<GelirKaynagi[]>([])
const gelirFormuAcik = ref(false)
const varsayilan = ref<Partial<GelirGirdisi> | undefined>()

async function yukle(): Promise<void> {
  const veri = await sonIstek(() => Promise.all([aileApi.ozet(donem.value), aileApi.kaynaklar()]))
  if (veri === undefined) return
  ozet.value = veri[0]
  kaynaklar.value = veri[1]
}
onMounted(async () => {
  await aile.yukle()
  await yukle()
})
watch(donem, () => {
  ozet.value = null
  void yukle()
})

const uyariVar = computed(
  () =>
    !!ozet.value &&
    (ozet.value.uyarilar.gecikmis_faturalar.length > 0 ||
      ozet.value.uyarilar.bekleyen_faturalar.length > 0 ||
      ozet.value.uyarilar.son_odemeler.length > 0),
)

const grafik = computed(() => {
  const k = ozet.value?.gider.kategoriler.filter((x) => x.toplam > 0) ?? []

  return {
    etiketler: k.map((x) => x.ad),
    seriler: [
      {
        ad: 'Gider',
        veri: k.map((x) => x.toplam),
        renk: k.map((x) => `--p-${x.renk ?? 'slate'}-500`),
      },
    ],
  }
})

function alindi(b: BeklenenGelir): void {
  varsayilan.value = {
    uye_id: b.kaynak.uye_id,
    gelir_kaynagi_id: b.kaynak.id,
    tur: b.kaynak.tur,
    duzen: b.kaynak.duzen,
    tutar: b.kaynak.beklenen_tutar,
    para_birimi: b.kaynak.para_birimi,
    donem: b.donem,
    ...(b.tarih && b.tarih <= bugun() ? { tarih: b.tarih } : {}),
  }
  gelirFormuAcik.value = true
}

const gunMetni = (gun: number | null | undefined) =>
  gun === null || gun === undefined
    ? ''
    : gun < 0
      ? `${-gun} gün geçti`
      : gun === 0
        ? 'bugün'
        : `${gun} gün kaldı`
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <AySecici v-model="donem" :ileri-sinir="null" />
      <span v-if="aile.hane" class="text-sm text-surface-500">{{ aile.hane.hane.ad }}</span>
    </div>

    <Skeleton v-if="!ozet" height="10rem" border-radius="1.5rem" />
    <template v-else>
      <!-- Uyarılar -->
      <section v-if="uyariVar" class="kart flex flex-col gap-2 p-4" aria-label="Uyarılar">
        <RouterLink
          v-for="f in ozet.uyarilar.gecikmis_faturalar"
          :key="`g${f.id}`"
          :to="{ name: 'aile-faturalar' }"
          class="flex items-center gap-2 rounded-xl bg-red-50 px-3 py-2 text-sm text-red-800"
        >
          <i class="pi pi-exclamation-circle" />
          <span class="min-w-0 flex-1 truncate"
            >{{ f.saglayici_ad }} {{ f.hizmet_donemi_etiketi ?? '' }} faturası gecikti</span
          >
          <span class="font-semibold whitespace-nowrap"
            >{{ tl(f.tutar) }} · {{ gunMetni(f.gun) }}</span
          >
        </RouterLink>
        <RouterLink
          v-for="f in ozet.uyarilar.bekleyen_faturalar"
          :key="`b${f.id}`"
          :to="{ name: 'aile-faturalar' }"
          class="flex items-center gap-2 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900"
        >
          <i class="pi pi-clock" />
          <span class="min-w-0 flex-1 truncate"
            >{{ f.saglayici_ad }} {{ f.hizmet_donemi_etiketi ?? '' }} ödenmedi</span
          >
          <span class="font-semibold whitespace-nowrap"
            >{{ tl(f.tutar)
            }}<template v-if="f.son_odeme_tarihi"> · {{ gunMetni(f.gun) }}</template></span
          >
        </RouterLink>
        <RouterLink
          v-for="e in ozet.uyarilar.son_odemeler"
          :key="`e${e.id}`"
          :to="{ name: 'aile-ekstre', params: { id: e.id } }"
          class="flex items-center gap-2 rounded-xl bg-sky-50 px-3 py-2 text-sm text-sky-900"
        >
          <i class="pi pi-credit-card" />
          <span class="min-w-0 flex-1 truncate"
            >{{ e.kart_ad }} son ödeme {{ tarih(e.son_odeme_tarihi) }}</span
          >
          <span class="font-semibold whitespace-nowrap"
            >{{ tl(e.donem_borcu) }} · {{ gunMetni(e.gun) }}</span
          >
        </RouterLink>
      </section>

      <!-- Gelir / gider / fark -->
      <section class="kart grid grid-cols-3 gap-2 p-4 sm:p-5">
        <div>
          <p class="text-xs text-surface-500">Gelir</p>
          <p
            class="text-base font-bold text-emerald-700 tabular-nums min-[400px]:text-lg"
            data-test="ozet-gelir"
          >
            {{ tl(ozet.gelir.toplam) }}
          </p>
        </div>
        <div>
          <p class="text-xs text-surface-500">Gider</p>
          <p
            class="text-base font-bold text-surface-900 tabular-nums min-[400px]:text-lg"
            data-test="ozet-gider"
          >
            {{ tl(ozet.gider.toplam) }}
          </p>
        </div>
        <div>
          <p class="text-xs text-surface-500">Fark</p>
          <p
            class="text-base font-bold tabular-nums min-[400px]:text-lg"
            :class="ozet.fark >= 0 ? 'text-emerald-700' : 'text-red-600'"
            data-test="ozet-fark"
          >
            {{ tl(ozet.fark) }}
          </p>
        </div>
      </section>

      <!-- Kişiler -->
      <section class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div v-for="k in ozet.gelir.kisiler" :key="k.uye.id" class="kart p-4">
          <div class="flex items-baseline justify-between gap-2">
            <h3 class="font-bold text-surface-900">{{ k.uye.ad }}</h3>
            <span class="font-semibold tabular-nums">{{ tl(k.toplam) }}</span>
          </div>
          <p class="mt-1 text-xs text-surface-500">
            Maaş ve düzenli {{ tl(k.duzenli) }} · ek / düzensiz
            {{ tl(k.duzensiz + k.tek_seferlik) }}
          </p>
          <ul v-if="k.turler.length" class="mt-2 flex flex-col gap-1 text-sm">
            <li v-for="t in k.turler" :key="t.tur" class="flex justify-between gap-2">
              <span class="text-surface-600">{{ GELIR_TURLERI[t.tur] }}</span>
              <span class="tabular-nums">{{ tl(t.toplam) }}</span>
            </li>
          </ul>
          <p v-if="k.beklenen_sayisi" class="mt-2 text-xs text-amber-700">
            {{ k.beklenen_sayisi }} beklenen gelir (alınmadı)
          </p>
        </div>
      </section>

      <section v-if="ozet.gelir.beklenenler.length" class="kart p-4">
        <h2 class="bolum-etiketi mb-1">Beklenen gelirler</h2>
        <p class="mb-2 text-xs text-surface-500">
          Düzenli kaynaklardan; alınana kadar gelir toplamına katılmaz.
        </p>
        <ul class="divide-y divide-surface-100">
          <li
            v-for="b in ozet.gelir.beklenenler"
            :key="b.kaynak.id"
            class="flex items-center gap-3 py-2.5"
          >
            <span class="min-w-0 flex-1">
              <span class="block truncate text-sm font-semibold">{{ b.kaynak.ad }}</span>
              <span class="block text-xs text-surface-500"
                ><KisiEtiketi :kisi="aile.uye(b.kaynak.uye_id)" /><template v-if="b.tarih">
                  · {{ tarih(b.tarih) }}</template
                ></span
              >
            </span>
            <span class="text-sm text-surface-500 tabular-nums">{{
              tl(b.kaynak.beklenen_tutar)
            }}</span>
            <Button label="Alındı" size="small" outlined @click="alindi(b)" />
          </li>
        </ul>
      </section>

      <!-- Giderler -->
      <section class="kart p-4 sm:p-5">
        <div class="mb-2 flex items-baseline justify-between">
          <h2 class="bolum-etiketi">Kategorilere göre gider</h2>
          <RouterLink
            :to="{ name: 'aile-islemler', query: { ay: donem } }"
            class="text-sm font-semibold text-primary-600"
            >İşlemler</RouterLink
          >
        </div>
        <p v-if="!ozet.gider.kategoriler.length" class="py-3 text-sm text-surface-500">
          Bu ay gider yok.
        </p>
        <div v-else class="grid grid-cols-1 items-center gap-4 sm:grid-cols-2">
          <VeriGrafigi
            tur="doughnut"
            :etiketler="grafik.etiketler"
            :seriler="grafik.seriler"
            aciklama="Kategorilere göre gider"
            yukseklik="13rem"
            :gosterge="false"
            :deger-bicimi="tl"
          />
          <ul class="flex flex-col gap-1.5 text-sm">
            <li v-for="k in ozet.gider.kategoriler" :key="k.ad">
              <button
                type="button"
                class="flex w-full items-center justify-between gap-2 text-left"
                @click="
                  router.push({
                    name: 'aile-islemler',
                    query: { ay: donem, kategori_id: k.kategori_id ?? 'yok' },
                  })
                "
              >
                <span class="flex items-center gap-2 text-surface-700">
                  <span
                    class="h-2.5 w-2.5 rounded-full"
                    :style="{ background: `var(--p-${k.renk ?? 'slate'}-500)` }"
                  />
                  {{ k.ad }}
                </span>
                <span
                  class="font-semibold tabular-nums"
                  :class="k.toplam < 0 ? 'text-emerald-700' : ''"
                  >{{ tl(k.toplam) }}</span
                >
              </button>
            </li>
          </ul>
        </div>
        <ul
          v-if="ozet.gider.haric.length || ozet.gider.kartsiz_faturalar > 0"
          class="mt-3 flex flex-col gap-1 rounded-2xl bg-surface-50 p-3 text-xs text-surface-600"
        >
          <li v-if="ozet.gider.kartsiz_faturalar > 0">
            Kartsız ödenen faturalar {{ tl(ozet.gider.kartsiz_faturalar) }} giderde (Faturalar).
          </li>
          <li v-for="h in ozet.gider.haric" :key="h.tur">
            {{ h.etiket }} {{ tl(h.toplam) }}: gidere sayılmadı{{
              h.tur === 'odeme' ? ' (ekstredeki harcamalar zaten sayıldı)' : ''
            }}.
          </li>
        </ul>
      </section>

      <section v-if="ozet.gider.taksitler.length" class="kart p-4">
        <h2 class="bolum-etiketi mb-2">Taksitler</h2>
        <ul class="divide-y divide-surface-100 text-sm">
          <li
            v-for="t in ozet.gider.taksitler"
            :key="t.islem_id"
            class="flex items-center justify-between gap-3 py-2"
          >
            <span class="min-w-0">
              <span class="block truncate font-semibold">{{ t.aciklama }}</span>
              <span class="block text-xs text-surface-500">
                {{ t.taksit_no }}/{{ t.taksit_sayisi }} · toplam borç {{ tl(t.toplam_borc)
                }}{{ t.toplam_tahmini ? ' (yaklaşık)' : '' }}
              </span>
            </span>
            <span class="text-right">
              <span class="block font-semibold tabular-nums">{{ tl(t.aya_dusen) }}</span>
              <span class="block text-[11px] text-surface-500">bu aya düşen</span>
            </span>
          </li>
        </ul>
      </section>

      <!-- Faturalar (hizmet ayı) -->
      <section class="kart p-4">
        <div class="mb-1 flex items-baseline justify-between">
          <h2 class="bolum-etiketi">Faturalar (hizmet ayı)</h2>
          <RouterLink
            :to="{ name: 'aile-faturalar' }"
            class="text-sm font-semibold text-primary-600"
            >Faturalar</RouterLink
          >
        </div>
        <p class="mb-2 text-xs text-surface-500">
          Bu ayın hizmeti için faturalar; ödendikleri ayın gideri olarak zaten sayıldı, burada
          ayrıca toplanmaz.
        </p>
        <p v-if="!ozet.faturalar.turler.length" class="text-sm text-surface-500">
          Bu hizmet ayına fatura yok.
        </p>
        <ul v-else class="flex flex-col gap-1.5 text-sm">
          <li
            v-for="t in ozet.faturalar.turler"
            :key="t.fatura_turu_id"
            class="flex justify-between gap-2"
          >
            <span class="flex items-center gap-2"
              ><i :class="t.ikon ?? 'pi pi-file'" class="text-surface-400" /> {{ t.ad }}</span
            >
            <span class="font-semibold tabular-nums">{{ tl(t.toplam) }}</span>
          </li>
          <li class="flex justify-between gap-2 border-t border-surface-100 pt-1.5 font-semibold">
            <span>Toplam</span><span class="tabular-nums">{{ tl(ozet.faturalar.toplam) }}</span>
          </li>
          <li v-if="ozet.faturalar.bekleyen > 0" class="text-xs text-amber-700">
            Ödenmemiş: {{ tl(ozet.faturalar.bekleyen) }}
          </li>
        </ul>
        <p v-if="ozet.faturalar.donemi_bilinmeyen" class="mt-2 text-xs text-surface-500">
          {{ ozet.faturalar.donemi_bilinmeyen }} faturanın hizmet ayı bilinmiyor (hiçbir aya
          yazılmadı).
        </p>
      </section>

      <details class="kart p-4 text-sm text-surface-600">
        <summary class="cursor-pointer font-semibold text-surface-800">
          Toplamlar nasıl hesaplanıyor?
        </summary>
        <ul class="mt-2 list-disc space-y-1 pl-5">
          <li>
            Gelir: bu aya ait (dönemi bu ay) alınmış gelirler. Beklenen ama alınmamış gelir toplama
            katılmaz.
          </li>
          <li>
            Gider: kart harcamaları işlem tarihinin ayına; taksitler ekstrenin kapandığı aya (aya
            düşen taksit). Faiz / ücret eklenir, iade düşülür.
          </li>
          <li>
            Kart borcu ödemesi, hesaplar arası transfer ve nakit avans gider değildir; ekstredeki
            harcamalar zaten sayıldı.
          </li>
          <li>
            Kartla ödenen fatura kart harcaması olarak bir kez sayılır; kartsız ödenen fatura
            ödendiği ayın gideridir.
          </li>
          <li>
            Faturalar bölümü hizmet ayına göredir ve bilgi içindir; gidere ikinci kez eklenmez.
          </li>
        </ul>
      </details>
    </template>

    <GelirFormu
      v-model:visible="gelirFormuAcik"
      :varsayilan="varsayilan"
      :kaynaklar="kaynaklar"
      @kaydedildi="yukle"
    />
  </div>
</template>
