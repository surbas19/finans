<script setup lang="ts">
import KartEtiketi from '@/components/aile/KartEtiketi.vue'
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Accordion from 'primevue/accordion'
import AccordionContent from 'primevue/accordioncontent'
import AccordionHeader from 'primevue/accordionheader'
import AccordionPanel from 'primevue/accordionpanel'
import Button from 'primevue/button'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import Skeleton from 'primevue/skeleton'
import AySecici from '@/components/aile/AySecici.vue'
import KisiEtiketi from '@/components/KisiEtiketi.vue'
import GelirFormu from '@/components/aile/GelirFormu.vue'
import GiderFormu from '@/components/aile/GiderFormu.vue'
import TasarrufFormu from '@/components/aile/TasarrufFormu.vue'
import TasarrufGunuFormu from '@/components/aile/TasarrufGunuFormu.vue'
import VeriGrafigi from '@/components/VeriGrafigi.vue'
import {
  aileApi,
  type AyGunu,
  type AylikOzet,
  type BeklenenGelir,
  type BeklenenGider,
  type DuzenliGiderGirdisi,
  type GelirGirdisi,
  type GelirKaynagi,
  type GiderKaynagi,
  type TasarrufGunu,
} from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { useSonIstek } from '@/composables/useSonIstek'
import { useAuthStore } from '@/stores/auth'
import { useAileStore } from '@/stores/aile'
import { bugun, tarih, tl } from '@/utils/bicim'
import { buAy } from '@/utils/donem'
import { kartRengiCss } from '@/utils/kartRengi'

/**
 * Bütçe aylık özeti: kişi kişi ve hane geliri (beklenenler ayrı), kategorilere göre gider, fatura toplamları (hizmet
 * ayı), gelir − gider; uyarılar. Toplamların hangi kurala göre hesaplandığı sayfada açıklanır.
 */
const aile = useAileStore()
const auth = useAuthStore()
const router = useRouter()
const sonIstek = useSonIstek()
const donem = ref(buAy())
const ozet = ref<AylikOzet | null>(null)
const kaynaklar = ref<GelirKaynagi[]>([])
const gelirFormuAcik = ref(false)
const varsayilan = ref<Partial<GelirGirdisi> | undefined>()
const giderKaynaklari = ref<GiderKaynagi[]>([])
const giderFormuAcik = ref(false)
const giderVarsayilan = ref<Partial<DuzenliGiderGirdisi> | undefined>()
const tasarrufAcik = ref(false)
/** Altın / döviz günü: yeni gün / düzenleme formu ve "bu ayın alımı" için seçili gün */
const gunFormuAcik = ref(false)
const duzenlenenGun = ref<TasarrufGunu | null>(null)
const gunAlimiAcik = ref(false)
const alimGunu = ref<AyGunu | null>(null)

function gunEkle(): void {
  duzenlenenGun.value = null
  gunFormuAcik.value = true
}
function gunDuzenle(g: TasarrufGunu): void {
  duzenlenenGun.value = g
  gunFormuAcik.value = true
}
function gunAlimiYap(g: AyGunu): void {
  alimGunu.value = g
  gunAlimiAcik.value = true
}
function tasarrufAc(): void {
  alimGunu.value = null
  tasarrufAcik.value = true
}
const confirm = useConfirm()
const toast = useToast()

async function yukle(): Promise<void> {
  const veri = await sonIstek(() =>
    Promise.all([aileApi.ozet(donem.value), aileApi.kaynaklar(), aileApi.giderKaynaklari()]),
  )
  if (veri === undefined) return
  ozet.value = veri[0]
  kaynaklar.value = veri[1]
  giderKaynaklari.value = veri[2]
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
      ozet.value.uyarilar.son_odemeler.length > 0 ||
      (auth.yonetici && (ozet.value.uyarilar.zekat?.length ?? 0) > 0)),
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
    gelir_turu_id: b.kaynak.gelir_turu_id,
    duzen: b.kaynak.duzen,
    tutar: b.kaynak.beklenen_tutar,
    para_birimi: b.kaynak.para_birimi,
    donem: b.donem,
    ...(b.tarih && b.tarih <= bugun() ? { tarih: b.tarih } : {}),
  }
  gelirFormuAcik.value = true
}

/** Beklenen düzenli gider ödendi: kaynağın tutarıyla, o dönem için gider formu açılır */
function odendi(b: BeklenenGider): void {
  giderVarsayilan.value = {
    gider_kaynagi_id: b.kaynak.id,
    kategori_id: b.kaynak.kategori_id,
    aciklama: b.kaynak.ad,
    tutar: b.kaynak.beklenen_tutar,
    donem: b.donem,
    ...(b.tarih && b.tarih <= bugun() ? { tarih: b.tarih } : {}),
  }
  giderFormuAcik.value = true
}

function tasarrufSil(id: number, ad: string): void {
  confirm.require({
    header: 'Tasarrufu sil',
    message: `"${ad}" tasarrufu silinsin mi? Portföydeki alış işlemi de silinir.`,
    acceptProps: { label: 'Sil', severity: 'danger' },
    rejectProps: { label: 'Vazgeç', severity: 'secondary', text: true },
    accept: async () => {
      try {
        await aileApi.tasarrufSil(id)
        toast.add({ severity: 'success', summary: 'Tasarruf silindi', life: 3000 })
        await yukle()
      } catch (e) {
        toast.add({ severity: 'error', summary: 'Silinemedi', detail: hataMesaji(e), life: 6000 })
      }
    },
  })
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
      <AySecici v-model="donem" :ileri-sinir="null" genis :yukseklik="100" />
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
            ><KartEtiketi :kart-id="e.kart_id" :ad="e.kart_ad" /> son ödeme
            {{ tarih(e.son_odeme_tarihi) }}</span
          >
          <span class="font-semibold whitespace-nowrap"
            >{{ tl(e.donem_borcu) }} · {{ gunMetni(e.gun) }}</span
          >
        </RouterLink>
        <RouterLink
          v-for="z in auth.yonetici ? (ozet.uyarilar.zekat ?? []) : []"
          :key="`z${z.tur}${z.yil_id ?? ''}`"
          :to="{ name: 'yonetim', query: { sekme: 'zekat' } }"
          class="flex items-center gap-2 rounded-xl bg-emerald-50 px-3 py-2 text-sm text-emerald-900"
          data-test="zekat-uyari"
        >
          <i class="pi pi-heart" />
          <span class="min-w-0 flex-1">{{ z.mesaj }}</span>
          <span v-if="z.kalan" class="font-semibold whitespace-nowrap">{{ tl(z.kalan) }}</span>
        </RouterLink>
      </section>

      <!-- Gelir / gider / tasarruf / kalan -->
      <section class="kart grid grid-cols-2 gap-3 p-4 sm:grid-cols-4 sm:p-5">
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
          <p class="text-xs text-surface-500">Tasarruf</p>
          <p
            class="text-base font-bold text-sky-700 tabular-nums min-[400px]:text-lg"
            data-test="ozet-tasarruf"
          >
            {{ tl(ozet.tasarruf.toplam) }}
          </p>
        </div>
        <div>
          <p class="text-xs text-surface-500">Kalan</p>
          <p
            class="text-base font-bold tabular-nums min-[400px]:text-lg"
            :class="ozet.kalan >= 0 ? 'text-emerald-700' : 'text-red-600'"
            data-test="ozet-kalan"
          >
            {{ tl(ozet.kalan) }}
          </p>
        </div>
        <p class="col-span-2 text-xs text-surface-500 sm:col-span-4" data-test="ozet-fark">
          Kalan = gelir − gider − tasarruf (tasarruf öncesi fark {{ tl(ozet.fark) }}).
        </p>
        <p
          v-if="auth.yonetici && ozet.zekat && ozet.zekat.adet > 0"
          class="col-span-2 text-xs text-emerald-800 sm:col-span-4"
          data-test="ozet-zekat"
        >
          Bu ay verilen zekât: {{ tl(ozet.zekat.verilen) }} (bilgi; gider ve kalana dahil değil).
        </p>
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
            <li v-for="t in k.turler" :key="t.gelir_turu_id" class="flex justify-between gap-2">
              <span class="text-surface-600">{{ t.etiket }}</span>
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

      <section v-if="ozet.gider.beklenenler.length" class="kart p-4">
        <div class="mb-1 flex items-baseline justify-between">
          <h2 class="bolum-etiketi">Beklenen giderler</h2>
          <RouterLink
            :to="{ name: 'aile-gelir-gider', query: { panel: 'gider' } }"
            class="text-sm font-semibold text-primary-600"
            >Giderler</RouterLink
          >
        </div>
        <p class="mb-2 text-xs text-surface-500">
          Düzenli ödemelerden (eğitim, bakıcı…); ödenene kadar gider toplamına katılmaz.
        </p>
        <ul class="divide-y divide-surface-100">
          <li
            v-for="b in ozet.gider.beklenenler"
            :key="b.kaynak.id"
            class="flex items-center gap-3 py-2.5"
          >
            <span class="min-w-0 flex-1">
              <span class="block truncate text-sm font-semibold">{{ b.kaynak.ad }}</span>
              <span v-if="b.tarih" class="block text-xs text-surface-500">{{
                tarih(b.tarih)
              }}</span>
            </span>
            <span class="text-sm text-surface-500 tabular-nums">{{
              tl(b.kaynak.beklenen_tutar)
            }}</span>
            <Button label="Ödendi" size="small" outlined @click="odendi(b)" />
          </li>
        </ul>
      </section>

      <!-- Tasarruf -->
      <section class="kart p-4 sm:p-5" data-test="tasarruf-bolumu">
        <div class="mb-2 flex items-center justify-between gap-2">
          <h2 class="bolum-etiketi">Tasarruf</h2>
          <Button
            label="Tasarruf yap"
            icon="pi pi-wallet"
            size="small"
            data-test="tasarruf-yap"
            @click="tasarrufAc"
          />
        </div>
        <p v-if="!ozet.tasarruf.kalemler.length" class="py-1 text-sm text-surface-500">
          Bu ay tasarruf yok. Altın, döviz, hisse ya da fon alarak bir portföy hesabına
          ayırabilirsiniz.
        </p>
        <ul v-else class="divide-y divide-surface-100">
          <li
            v-for="t in ozet.tasarruf.kalemler"
            :key="t.id"
            class="flex items-center gap-3 py-2.5"
          >
            <span class="min-w-0 flex-1">
              <span class="block truncate text-sm font-semibold">{{ t.varlik_ad }}</span>
              <span class="block text-xs text-surface-500"
                >{{ t.hesap_ad }} · {{ tarih(t.tarih) }}</span
              >
            </span>
            <span class="font-semibold text-sky-700 tabular-nums">{{ tl(t.tutar) }}</span>
            <Button
              icon="pi pi-trash"
              severity="danger"
              text
              rounded
              size="small"
              :aria-label="`${t.varlik_ad} tasarrufunu sil`"
              @click="tasarrufSil(t.id, t.varlik_ad ?? 'Tasarruf')"
            />
          </li>
        </ul>
      </section>

      <!-- Altın / döviz günleri -->
      <section class="kart p-4 sm:p-5" data-test="gunler-bolumu">
        <div class="mb-2 flex items-center justify-between gap-2">
          <h2 class="bolum-etiketi">Altın / döviz günleri</h2>
          <Button
            label="Gün ekle"
            icon="pi pi-plus"
            size="small"
            severity="secondary"
            outlined
            data-test="gun-ekle"
            @click="gunEkle"
          />
        </div>
        <p v-if="!ozet.gunler.length" class="py-1 text-sm text-surface-500">
          Bu ay için gün yok. Her ay belli miktarda altın ya da döviz aldığınız bir gün varsa
          ekleyin; ay gelince buradan alımı kaydedersiniz.
        </p>
        <ul v-else class="divide-y divide-surface-100">
          <li
            v-for="g in ozet.gunler"
            :key="g.gun.id"
            class="flex flex-wrap items-center gap-3 py-2.5"
            data-test="gun-satiri"
          >
            <button type="button" class="min-w-0 flex-1 text-left" @click="gunDuzenle(g.gun)">
              <span class="block truncate text-sm font-semibold">{{ g.gun.ad }}</span>
              <span class="block text-xs text-surface-500">
                {{ g.gun.miktar }} {{ g.gun.varlik_birim ?? '' }} {{ g.gun.varlik_ad }} · her ayın
                {{ g.gun.ay_gunu }}'i · {{ g.gun.hesap_ad }}
              </span>
            </button>
            <span
              v-if="g.durum === 'sira_bizde'"
              class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700"
              data-test="gun-sira-bizde"
              >Sıra bizde</span
            >
            <span
              v-else-if="g.durum === 'yapildi'"
              class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700"
              data-test="gun-yapildi"
              >Alım yapıldı</span
            >
            <Button
              v-else
              :label="g.vadesi_geldi ? 'Bu ayın alımını yap' : 'Alımı şimdi yap'"
              icon="pi pi-check"
              size="small"
              :severity="g.vadesi_geldi ? 'primary' : 'secondary'"
              :outlined="!g.vadesi_geldi"
              data-test="gun-alimi-yap"
              @click="gunAlimiYap(g)"
            />
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
                :disabled="k.diger"
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
          v-if="
            ozet.gider.haric.length ||
            ozet.gider.kartsiz_faturalar > 0 ||
            ozet.gider.duzenli.toplam > 0
          "
          class="mt-3 flex flex-col gap-1 rounded-2xl bg-surface-50 p-3 text-xs text-surface-600"
        >
          <li v-if="ozet.gider.duzenli.toplam > 0">
            Düzenli ödemeler ve tek seferlik giderler {{ tl(ozet.gider.duzenli.toplam) }} giderde
            (Giderler).
          </li>
          <li v-if="ozet.gider.kartsiz_faturalar > 0">
            Kartsız ödenen faturalar {{ tl(ozet.gider.kartsiz_faturalar) }} giderde (Faturalar).
          </li>
          <li v-for="h in ozet.gider.haric" :key="h.tur">
            {{ h.etiket }} {{ tl(h.toplam) }}: ekstre borcunun içinde, ayrıca eklenmedi.
          </li>
        </ul>
      </section>

      <section
        v-if="ozet.gider.taksitler.length || ozet.gider.sonraki_taksitler.length"
        class="kart p-2"
        data-test="taksit-akordiyon"
      >
        <Accordion
          :value="['taksitler']"
          multiple
          class="[&_.p-accordioncontent-wrapper]:min-w-0 [&_.p-accordioncontent-wrapper]:overflow-hidden"
        >
          <AccordionPanel v-if="ozet.gider.taksitler.length" value="taksitler">
            <AccordionHeader>Taksitler ({{ ozet.gider.taksitler.length }})</AccordionHeader>
            <AccordionContent>
              <ul class="divide-y divide-surface-100 text-sm">
                <li
                  v-for="t in ozet.gider.taksitler"
                  :key="t.islem_id"
                  class="flex items-center justify-between gap-3 border-l-4 py-2 pl-3"
                  :style="{ borderLeftColor: kartRengiCss(aile.kart(t.kart_id)) }"
                  data-test="taksit-satiri"
                >
                  <span class="min-w-0">
                    <span class="block truncate font-semibold">{{ t.aciklama }}</span>
                    <span class="block text-xs text-surface-500">
                      <KartEtiketi :kart-id="t.kart_id" /> · {{ t.taksit_no }}/{{
                        t.taksit_sayisi
                      }}
                      · toplam borç {{ tl(t.toplam_borc)
                      }}{{ t.toplam_tahmini ? ' (yaklaşık)' : '' }}
                    </span>
                  </span>
                  <span class="text-right">
                    <span class="block font-semibold tabular-nums">{{ tl(t.aya_dusen) }}</span>
                    <span class="block text-[11px] text-surface-500">bu aya düşen</span>
                  </span>
                </li>
              </ul>
            </AccordionContent>
          </AccordionPanel>

          <AccordionPanel v-if="ozet.gider.sonraki_taksitler.length" value="sonraki">
            <AccordionHeader data-test="sonraki-taksitler-baslik"
              >Sonraki taksitler</AccordionHeader
            >
            <AccordionContent>
              <p class="mb-2 text-xs text-surface-500">
                Bu aydan sonra ödenecek kalan taksitlerin kart başına toplamı.
              </p>
              <ul class="flex flex-col gap-3 text-sm">
                <li
                  v-for="k in ozet.gider.sonraki_taksitler"
                  :key="k.kart_id"
                  class="border-l-4 pl-3"
                  :style="{ borderLeftColor: kartRengiCss(aile.kart(k.kart_id)) }"
                  data-test="sonraki-taksit-karti"
                >
                  <div class="flex items-center justify-between gap-3">
                    <span class="font-semibold"><KartEtiketi :kart-id="k.kart_id" /></span>
                    <span class="font-semibold tabular-nums"
                      >{{ tl(k.toplam) }}{{ k.tahmini ? ' (yaklaşık)' : '' }}</span
                    >
                  </div>
                  <ul class="mt-1 divide-y divide-surface-100 text-xs text-surface-500">
                    <li
                      v-for="m in k.kalemler"
                      :key="m.islem_id"
                      class="flex justify-between gap-3 py-1"
                    >
                      <span class="min-w-0 flex-1">
                        <span class="block break-words">{{ m.aciklama }}</span>
                        <span class="block">{{ m.kalan_adet }} taksit × {{ tl(m.aya_dusen) }}</span>
                      </span>
                      <span class="self-start tabular-nums">{{ tl(m.kalan_toplam) }}</span>
                    </li>
                  </ul>
                </li>
              </ul>
            </AccordionContent>
          </AccordionPanel>
        </Accordion>
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
            Gider: kart gideri, o ay kesilen ekstrenin dönem borcunun tamamıdır (devreden borç,
            taksit, faiz, ödeme ve bonus dahil; ayrılmaz). Kategori grafiği ekstre satırlarından
            çıkar, borçla farkı "Ekstredeki diğer" olarak görünür. Ekstresi henüz gelmemiş elle
            girilen kart harcamaları ay giderine eklenir.
          </li>
          <li>
            Kartla ödenen fatura ekstre borcunun içindedir, ayrıca sayılmaz; kartsız ödenen fatura
            ödendiği ayın gideridir.
          </li>
          <li>
            Düzenli ödemeler (Giderler) ödendi diye girildiği ayın gideridir; kartla ödenenler kart
            ekstresinden gelir, ayrıca girilmez.
          </li>
          <li>
            Tasarruf gider değildir: seçilen portföy hesabına alış olarak yazılır, kalan paradan
            düşülür (kalan = gelir − gider − tasarruf).
          </li>
          <li>
            Faturalar bölümü hizmet ayına göredir ve bilgi içindir; gidere ikinci kez eklenmez.
          </li>
        </ul>
      </details>
    </template>

    <GiderFormu
      v-model:visible="giderFormuAcik"
      :varsayilan="giderVarsayilan"
      :kaynaklar="giderKaynaklari"
      @kaydedildi="yukle"
    />
    <TasarrufFormu v-model:visible="tasarrufAcik" @kaydedildi="yukle" />
    <TasarrufFormu v-model:visible="gunAlimiAcik" :gun="alimGunu" @kaydedildi="yukle" />
    <TasarrufGunuFormu v-model:visible="gunFormuAcik" :gun="duzenlenenGun" @kaydedildi="yukle" />
    <GelirFormu
      v-model:visible="gelirFormuAcik"
      :varsayilan="varsayilan"
      :kaynaklar="kaynaklar"
      @kaydedildi="yukle"
    />
  </div>
</template>
