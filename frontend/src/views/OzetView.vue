<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import BosDurum from '@/components/BosDurum.vue'
import DagilimKarti from '@/components/DagilimKarti.vue'
import DegisimEtiketi from '@/components/DegisimEtiketi.vue'
import IslemFormu from '@/components/IslemFormu.vue'
import PerformansGrafigi from '@/components/PerformansGrafigi.vue'
import VeriGrafigi from '@/components/VeriGrafigi.vue'
import PozisyonListesi from '@/components/PozisyonListesi.vue'
import VarlikAkordiyonu from '@/components/VarlikAkordiyonu.vue'
import TemettuSeridi from '@/components/TemettuSeridi.vue'
import UygulamaKurKarti from '@/components/UygulamaKurKarti.vue'
import { finansApi, type HesapOzeti, type Ozet, type Toplam } from '@/api/finans'
import { usePozisyonDahil } from '@/composables/usePozisyonDahil'
import PozisyonAktarDialog from '@/components/PozisyonAktarDialog.vue'
import { usePozisyonAktar } from '@/composables/usePozisyonAktar'
import { useSonIstek } from '@/composables/useSonIstek'
import { neZaman, tl } from '@/utils/bicim'
import { hesapDilimleri, hesapRengi, varlikDilimleri, type HalkaDilimi } from '@/utils/halka'

/**
 * Portföy özeti: üstte seçilenin (bütün hesaplar ya da bir hesap) değeri, kâr / zararı, bugünkü değişimi ve küçük
 * dağılım halkası; altında hesap çipleri (dokununca üst kart o hesaba geçer), dağılım, türe göre akordiyon varlıklar ve en altta performans. Hepsi
 * tek özet isteğinden gelir; hesap değişince yalnız performans grafiği kendi verisini ister. Seçilen hesap adreste
 * (`?hesap=`): varlık sayfasından geri dönünce aynı hesap açılır.
 */
const route = useRoute()
const router = useRouter()
const ozet = ref<Ozet | null>(null)
const secilenHesap = ref<number | null>(adrestekiHesap())
const islemAcik = ref(false)
/** İşlem kaydedilince performans grafiği yenilenir */
const yenile = ref(0)
const sonIstek = useSonIstek()

function adrestekiHesap(): number | null {
  const id = Number(route.query.hesap)

  return Number.isInteger(id) && id > 0 ? id : null
}

async function yukle(): Promise<void> {
  const veri = await sonIstek(() => finansApi.ozet(null))
  if (veri === undefined) return
  ozet.value = veri
  // Silinmiş / başkasının hesabı adreste kaldıysa bütün hesaplara dön
  if (veri.hesaplar.length < 2 || !veri.hesaplar.some((h) => h.id === secilenHesap.value)) {
    secilenHesap.value = null
  }
}

onMounted(yukle)

watch(secilenHesap, (id) => {
  if (adrestekiHesap() === id) return
  void router.replace({ query: { ...route.query, hesap: id ?? undefined } })
})
// Adres dışarıdan değişirse (ör. alt çubukta "Özet"e dokunmak `?hesap`'ı siler) seçim de değişir
watch(
  () => route.query.hesap,
  () => {
    const id = adrestekiHesap()
    if (id !== secilenHesap.value) secilenHesap.value = id
  },
)

/** Birden çok hesap varsa her hesabın slaytı olur; tek hesapta yalnızca "Tüm hesaplar" (aynısı olurdu) */
const cokHesap = computed(() => (ozet.value?.hesaplar.length ?? 0) > 1)

function oran(deger: number, toplam: number): string {
  return toplam > 0
    ? `%${((deger / toplam) * 100).toLocaleString('tr-TR', { maximumFractionDigits: 0 })}`
    : '—'
}

/** Hesap çipleri: önce bütün hesaplar, sonra her hesap (halkadaki hesap rengiyle) */
const cipler = computed(() => {
  const o = ozet.value
  if (!o) return []

  return [
    { id: null as number | null, ad: 'Tümü', deger: o.toplam.deger, renk: '--p-surface-400' },
    ...o.hesaplar.map((h) => ({
      id: h.id as number | null,
      ad: h.ad,
      deger: h.deger,
      renk: hesapRengi(h),
    })),
  ]
})

/**
 * Üst karttaki küçük halka: bütün hesaplarda (birden çok hesap varsa) hesapların payı, tek hesapta / seçili hesapta
 * varlıkların dağılımı. Yalnızca veri değişince yeniden kurulur (PrimeVue Chart her yeni dizide baştan çizer).
 */
const halka = computed(() => {
  const o = ozet.value
  const h = secilenHesapOzeti.value
  let dilimler: HalkaDilimi[] = []
  let merkezUst = ''
  let merkezAlt = ''
  if (o && !h) {
    dilimler = cokHesap.value ? hesapDilimleri(o.hesaplar) : varlikDilimleri(o.varliklar).dilimler
    merkezUst = cokHesap.value ? 'Hesap' : 'Varlık'
    merkezAlt = String(cokHesap.value ? o.hesaplar.length : o.varliklar.length)
  } else if (o && h) {
    dilimler = varlikDilimleri(o.pozisyonlar.filter((p) => p.hesap_id === h.id && p.dahil)).dilimler
    merkezUst = 'Payı'
    merkezAlt = oran(h.deger, o.toplam.deger)
  }
  const toplam = dilimler.reduce((t, d) => t + d.deger, 0) || 1

  return {
    etiketler: dilimler.map((d) => d.etiket),
    seriler: [
      { ad: 'Dağılım', veri: dilimler.map((d) => d.deger), renk: dilimler.map((d) => d.renk) },
    ],
    aciklama: `Dağılım: ${dilimler.map((d) => `${d.etiket} %${Math.round((d.deger / toplam) * 100)}`).join(', ') || 'boş'}`,
    merkezUst,
    merkezAlt,
  }
})

const secilenHesapOzeti = computed<HesapOzeti | null>(
  () => ozet.value?.hesaplar.find((h) => h.id === secilenHesap.value) ?? null,
)

/** Alttaki detay: bütün hesaplar ya da seçilen hesap (pozisyon şeritleri halkadaki renkte) */
const secili = computed(() => {
  const o = ozet.value
  if (!o) return null
  const h = secilenHesapOzeti.value
  if (!h) {
    // Tek hesapta satırlar hesaba bağlı gelir (dahil düğmesi çalışsın); birden çok hesapta varlıklar toplanmıştır
    const tekHesap = !cokHesap.value
    const liste = tekHesap ? o.pozisyonlar : o.varliklar

    return {
      baslik: 'Tüm hesaplar',
      toplam: o.toplam,
      pozisyonlar: liste,
      dagilim: o.dagilim,
      renkler: tekHesap ? varlikDilimleri(liste.filter((p) => p.dahil)).renkler : undefined,
      degistirilebilir: tekHesap,
    }
  }
  const pozisyonlar = o.pozisyonlar.filter((p) => p.hesap_id === h.id)

  return {
    baslik: h.ad,
    toplam: h as Toplam,
    pozisyonlar,
    dagilim: h.dagilim ?? [],
    renkler: varlikDilimleri(pozisyonlar.filter((p) => p.dahil)).renkler,
    degistirilebilir: true,
  }
})

/** Birden çok hesapta toplu listede görünmeyen, genel toplama dahil olmayan satırlar (geri eklenebilsin) */
const haricSatirlar = computed(() =>
  cokHesap.value && secilenHesap.value === null
    ? (ozet.value?.pozisyonlar.filter((p) => !p.dahil) ?? [])
    : [],
)
const dahilDegistir = usePozisyonDahil(() => yukle())
const { aktarAcik, aktarPozisyon, aktarAc } = usePozisyonAktar()

function islemKaydedildi(): void {
  yenile.value++
  void yukle()
}

const yenileniyor = ref(false)
async function elleYenile(): Promise<void> {
  yenileniyor.value = true
  yenile.value++
  try {
    await yukle()
  } finally {
    yenileniyor.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <UygulamaKurKarti ipucu />

    <!-- Temettü şeridi: ödeme gününe 3 gün kala ya da günü geçip kaydedilmeyen planlı temettü -->
    <TemettuSeridi @degisti="yukle" />

    <!-- Üst kart: seçilenin (bütün hesaplar ya da bir hesap) değeri, kâr / zarar, bugün ve küçük dağılım halkası -->
    <div v-if="!ozet" class="kart flex items-center gap-4 p-4">
      <div class="flex flex-1 flex-col gap-2">
        <Skeleton width="40%" />
        <Skeleton width="70%" height="2rem" />
        <Skeleton width="55%" />
      </div>
      <Skeleton shape="circle" size="5rem" />
    </div>
    <section v-else-if="secili" class="kart p-4 sm:p-5" data-test="portfoy-ozeti">
      <div class="flex items-start gap-4">
        <div class="min-w-0 flex-1">
          <div class="flex flex-wrap items-baseline gap-x-2">
            <h2 data-test="secili-baslik" class="truncate text-sm font-semibold text-surface-500">
              {{ secilenHesapOzeti ? secilenHesapOzeti.ad : 'Toplam varlık' }}
            </h2>
            <RouterLink
              v-if="secilenHesapOzeti"
              :to="{ name: 'hesap', params: { id: secilenHesapOzeti.id } }"
              class="text-sm font-semibold text-primary-600"
              data-test="hesabi-ac"
              >Hesabı aç ›</RouterLink
            >
          </div>
          <p
            class="text-3xl font-extrabold tracking-tight text-surface-900 tabular-nums"
            data-test="secili-deger"
          >
            {{ tl(secili.toplam.deger) }}
          </p>
          <p class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-sm text-surface-500">
            <DegisimEtiketi :tutar="secili.toplam.kz" :yuzde="secili.toplam.kz_yuzde" />
            <span class="inline-flex items-center gap-1"
              >Bugün
              <DegisimEtiketi
                :tutar="secili.toplam.gunluk_degisim"
                :yuzde="secili.toplam.gunluk_yuzde"
            /></span>
          </p>
        </div>
        <div v-if="halka.seriler[0]!.veri.length" class="relative size-20 shrink-0 sm:size-24">
          <VeriGrafigi
            tur="doughnut"
            :etiketler="halka.etiketler"
            :seriler="halka.seriler"
            :aciklama="halka.aciklama"
            :gosterge="false"
            :ipucu="false"
            kesim="70%"
            yukseklik="100%"
          />
          <span
            class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center leading-tight"
          >
            <span class="text-xs text-surface-500">{{ halka.merkezUst }}</span>
            <span class="text-sm font-bold text-surface-900 tabular-nums">{{
              halka.merkezAlt
            }}</span>
          </span>
        </div>
      </div>

      <!-- Ayrıntılar tek satırda -->
      <dl
        class="mt-3 flex flex-wrap gap-x-4 gap-y-1 border-t border-surface-100 pt-3 text-sm"
        data-test="secili-ayrinti"
      >
        <div class="flex gap-1.5">
          <dt class="text-surface-500">Maliyet</dt>
          <dd class="font-semibold text-surface-900 tabular-nums">
            {{ tl(secili.toplam.maliyet) }}
          </dd>
        </div>
        <div class="flex gap-1.5">
          <dt class="text-surface-500">Gerçekleşen</dt>
          <dd><DegisimEtiketi :tutar="secili.toplam.gerceklesen_kz" /></dd>
        </div>
        <div v-if="(secili.toplam.temettu ?? 0) > 0" class="flex gap-1.5" data-test="ozet-temettu">
          <dt class="text-surface-500">Temettü</dt>
          <dd><DegisimEtiketi :tutar="secili.toplam.temettu" /></dd>
        </div>
      </dl>
      <p
        v-if="secili.toplam.maliyeti_eksik"
        class="mt-2 flex items-start gap-1.5 text-xs text-amber-800"
        data-test="maliyet-eksik"
      >
        <i class="pi pi-info-circle mt-0.5 text-xs" />
        Maliyeti bilinmeyen varlıklar var; kâr / zarar yalnız maliyeti bilinen kısım için.
      </p>
      <p
        v-if="secili.toplam.fiyatsiz > 0"
        class="mt-1 flex items-start gap-1.5 text-xs text-amber-800"
      >
        <i class="pi pi-exclamation-triangle mt-0.5 text-xs" />
        {{ secili.toplam.fiyatsiz }} varlığın fiyatı yok; toplam değere katılmadı.
      </p>

      <div class="mt-3 flex items-center justify-between gap-2">
        <p class="flex min-w-0 items-center text-xs text-surface-400">
          <span class="truncate"
            >Fiyatlar {{ neZaman(ozet.son_guncelleme) }} · BIST ~15 dk gecikmeli</span
          >
          <!-- Ana ekrandan açılınca tarayıcının yenile düğmesi yok -->
          <Button
            icon="pi pi-refresh"
            text
            rounded
            size="small"
            severity="secondary"
            class="!h-10 !w-10 shrink-0 sm:!h-8 sm:!w-8"
            aria-label="Yenile"
            title="Yenile"
            :loading="yenileniyor"
            @click="elleYenile"
          />
        </p>
        <Button
          label="İşlem ekle"
          icon="pi pi-plus"
          size="small"
          class="shrink-0"
          @click="islemAcik = true"
        />
      </div>
    </section>

    <!-- Hesap çipleri: dokununca üst kart o hesabı gösterir -->
    <div
      v-if="ozet && cokHesap"
      class="-mx-4 -mt-1 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
      role="group"
      aria-label="Hesap seçimi"
      data-test="hesap-cipleri"
    >
      <button
        v-for="c in cipler"
        :key="c.id ?? 'tumu'"
        type="button"
        class="flex shrink-0 items-center gap-2 rounded-2xl border bg-surface-0 py-2 pr-3.5 pl-2.5 text-left transition-colors"
        :class="
          c.id === secilenHesap
            ? 'border-primary-500 ring-1 ring-primary-500'
            : 'border-surface-200 hover:border-surface-300'
        "
        :aria-pressed="c.id === secilenHesap"
        data-test="hesap-cipi"
        @click="secilenHesap = c.id"
      >
        <span class="size-2.5 shrink-0 rounded-full" :style="{ background: `var(${c.renk})` }" />
        <span class="min-w-0">
          <span class="block max-w-36 truncate text-xs font-semibold text-surface-600">{{
            c.ad
          }}</span>
          <span class="block text-sm font-bold text-surface-900 tabular-nums">{{
            tl(c.deger)
          }}</span>
        </span>
      </button>
    </div>

    <!-- Dağılım (türlere göre), sonra türlere ayrılmış varlıklar, en altta performans -->
    <DagilimKarti
      v-if="secili && secili.dagilim.length > 0"
      :dagilim="secili.dagilim"
      :toplam-deger="secili.toplam.deger"
      data-test="ozet-dagilim"
    />

    <!-- Varlıklar -->
    <section class="kart p-4 sm:p-5">
      <h2 class="bolum-etiketi mb-2">
        {{ secilenHesap === null ? 'Varlıklarım (bütün hesaplar)' : 'Bu hesaptaki varlıklar' }}
      </h2>
      <div v-if="!ozet || !secili" class="flex flex-col gap-3">
        <Skeleton v-for="n in 4" :key="n" height="3rem" />
      </div>
      <BosDurum
        v-else-if="secili.pozisyonlar.length === 0"
        kucuk
        baslik="Henüz varlık yok"
        aciklama="Önce bir hesap ekleyin, sonra alış ya da elde olan varlığınızı girin."
        ikon="pi pi-wallet"
      >
        <Button
          v-if="ozet.hesaplar.length === 0"
          label="Hesap ekle"
          icon="pi pi-plus"
          size="small"
          @click="$router.push({ name: 'hesaplar' })"
        />
        <Button
          v-else
          label="İşlem ekle"
          icon="pi pi-plus"
          size="small"
          @click="islemAcik = true"
        />
      </BosDurum>
      <VarlikAkordiyonu
        v-else
        :pozisyonlar="secili.pozisyonlar"
        :renkler="secili.renkler"
        :degistirilebilir="secili.degistirilebilir"
        @dahil-degistir="dahilDegistir"
        @aktar="aktarAc"
      />
      <div v-if="haricSatirlar.length" class="mt-4" data-test="haric-bolumu">
        <h3 class="bolum-etiketi mb-1">Genel toplama dahil olmayanlar</h3>
        <PozisyonListesi
          :pozisyonlar="haricSatirlar"
          :hesap-adi="(p) => ozet?.hesaplar.find((h) => h.id === p.hesap_id)?.ad"
          degistirilebilir
          @dahil-degistir="dahilDegistir"
          @aktar="aktarAc"
        />
      </div>
    </section>

    <!-- Performans -->
    <section v-if="ozet && ozet.hesaplar.length > 0" class="kart p-4 sm:p-5">
      <h2 class="bolum-etiketi mb-2">
        {{ secilenHesap === null ? 'Performans (bütün hesaplar)' : 'Performans' }}
      </h2>
      <PerformansGrafigi :hesap-id="secilenHesap" :yenile="yenile" />
    </section>

    <IslemFormu
      v-model:visible="islemAcik"
      :varsayilan="secilenHesap ? { hesap_id: secilenHesap } : undefined"
      @kaydedildi="islemKaydedildi"
    />
    <PozisyonAktarDialog v-model:visible="aktarAcik" :pozisyon="aktarPozisyon" @aktarildi="yukle" />
  </div>
</template>
