<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import BosDurum from '@/components/BosDurum.vue'
import DagilimKarti from '@/components/DagilimKarti.vue'
import DegisimEtiketi from '@/components/DegisimEtiketi.vue'
import IslemFormu from '@/components/IslemFormu.vue'
import PerformansGrafigi from '@/components/PerformansGrafigi.vue'
import PortfoyKaydirici, { type PortfoySlayti } from '@/components/PortfoyKaydirici.vue'
import PozisyonListesi from '@/components/PozisyonListesi.vue'
import UygulamaKurKarti from '@/components/UygulamaKurKarti.vue'
import { finansApi, HESAP_TURLERI, type HesapOzeti, type Ozet, type Toplam } from '@/api/finans'
import { useSonIstek } from '@/composables/useSonIstek'
import { neZaman, tl } from '@/utils/bicim'
import { hesapDilimleri, hesapRengi, varlikDilimleri } from '@/utils/halka'

/**
 * Ana sayfa: üstte yana kaydırmalı halkalar (önce bütün hesaplar, sonra her hesap), altta seçilenin özeti,
 * performansı, varlıkları ve dağılımı. Hepsi tek özet isteğinden gelir (her hesabın toplamı, pozisyonları ve
 * dağılımı içinde); kaydırınca yalnızca performans grafiği kendi verisini ister. Seçilen hesap adreste
 * (`?hesap=`): varlık sayfasından geri dönünce aynı slayt açılır.
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

const slaytlar = computed<PortfoySlayti[]>(() => {
  const o = ozet.value
  if (!o) return []
  const tumu: PortfoySlayti = {
    id: null,
    ad: 'Tüm hesaplar',
    altBaslik: `${o.varliklar.length} varlık`,
    deger: o.toplam.deger,
    kz: o.toplam.kz,
    kz_yuzde: o.toplam.kz_yuzde,
    gunluk_degisim: o.toplam.gunluk_degisim,
    dilimler: cokHesap.value ? hesapDilimleri(o.hesaplar) : varlikDilimleri(o.varliklar).dilimler,
    merkez: { ust: 'Hesap', alt: String(o.hesaplar.length) },
  }
  if (!cokHesap.value) return [tumu]

  return [
    tumu,
    ...o.hesaplar.map((h) => ({
      id: h.id,
      ad: h.ad,
      altBaslik: `${h.varlik_sayisi} varlık`,
      deger: h.deger,
      kz: h.kz,
      kz_yuzde: h.kz_yuzde,
      gunluk_degisim: h.gunluk_degisim,
      dilimler: varlikDilimleri(o.pozisyonlar.filter((p) => p.hesap_id === h.id)).dilimler,
      merkez: { ust: 'Payı', alt: oran(h.deger, o.toplam.deger) },
    })),
  ]
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
    return {
      baslik: 'Tüm hesaplar',
      toplam: o.toplam,
      pozisyonlar: o.varliklar,
      dagilim: o.dagilim,
      renkler: cokHesap.value ? undefined : varlikDilimleri(o.varliklar).renkler,
    }
  }
  const pozisyonlar = o.pozisyonlar.filter((p) => p.hesap_id === h.id)

  return {
    baslik: h.ad,
    toplam: h as Toplam,
    pozisyonlar,
    dagilim: h.dagilim ?? [],
    renkler: varlikDilimleri(pozisyonlar).renkler,
  }
})

/** "Hesaplar" listesinden: o hesabın slaytı seçilir, sayfa kaydırıcıya çıkar (tek hesapta hesap sayfası açılır) */
function hesabaGec(id: number): void {
  if (!cokHesap.value) {
    void router.push({ name: 'hesap', params: { id } })
    return
  }
  secilenHesap.value = id
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

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

    <!-- Yana kaydırmalı halkalar -->
    <div v-if="!ozet" class="kart flex items-center gap-4 p-4">
      <Skeleton shape="circle" size="7rem" />
      <div class="flex flex-1 flex-col gap-2">
        <Skeleton width="50%" />
        <Skeleton width="80%" height="1.5rem" />
        <Skeleton width="40%" />
      </div>
    </div>
    <PortfoyKaydirici v-else v-model:secilen="secilenHesap" :slaytlar="slaytlar" />

    <!-- Seçilenin özeti -->
    <section v-if="ozet && secili" class="kart p-4 sm:p-5">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
          <h2 data-test="secili-baslik" class="truncate text-lg font-bold text-surface-900">
            {{ secili.baslik }}
          </h2>
          <RouterLink
            v-if="secilenHesapOzeti"
            :to="{ name: 'hesap', params: { id: secilenHesapOzeti.id } }"
            class="inline-flex items-center gap-1 text-sm font-semibold text-primary-600"
          >
            Hesabı aç <i class="pi pi-arrow-right text-xs" />
          </RouterLink>
          <p v-else class="text-sm text-surface-500">
            {{ ozet.hesaplar.length }} hesap · {{ ozet.varliklar.length }} varlık
          </p>
        </div>
        <Button label="İşlem ekle" icon="pi pi-plus" @click="islemAcik = true" />
      </div>
      <div class="mt-4 grid grid-cols-2 gap-3">
        <div class="rounded-2xl bg-surface-50 p-3">
          <p class="text-xs text-surface-500">Maliyet</p>
          <p class="text-sm font-semibold text-surface-900 tabular-nums">
            {{ tl(secili.toplam.maliyet) }}
          </p>
        </div>
        <div class="rounded-2xl bg-surface-50 p-3">
          <p class="text-xs text-surface-500">Gerçekleşen (satışlar)</p>
          <DegisimEtiketi :tutar="secili.toplam.gerceklesen_kz" />
        </div>
      </div>
      <div class="mt-3 flex flex-col gap-2">
        <Message v-if="secili.toplam.maliyeti_eksik" severity="info" size="small">
          Maliyeti bilinmeyen varlıklar var: kâr / zarar yalnızca maliyeti bilinen kısım için
          hesaplanır.
        </Message>
        <Message v-if="secili.toplam.fiyatsiz > 0" severity="warn" size="small">
          {{ secili.toplam.fiyatsiz }} varlığın fiyatı yok; toplam değere katılmadı.
        </Message>
        <p class="flex items-center gap-1 text-xs text-surface-400">
          <span>
            Fiyatlar {{ neZaman(ozet.son_guncelleme) }} güncellendi · BIST ~15 dk gecikmeli
          </span>
          <!-- Ana ekrandan açılınca tarayıcının yenile düğmesi yok -->
          <Button
            icon="pi pi-refresh"
            text
            rounded
            size="small"
            severity="secondary"
            class="!h-10 !w-10 shrink-0 sm:!h-7 sm:!w-7"
            aria-label="Yenile"
            title="Yenile"
            :loading="yenileniyor"
            @click="elleYenile"
          />
        </p>
      </div>
    </section>

    <!-- Performans -->
    <section v-if="ozet && ozet.hesaplar.length > 0" class="kart p-4 sm:p-5">
      <h2 class="bolum-etiketi mb-2">
        {{ secilenHesap === null ? 'Performans (bütün hesaplar)' : 'Performans' }}
      </h2>
      <PerformansGrafigi :hesap-id="secilenHesap" :yenile="yenile" />
    </section>

    <!-- Hesaplar (bütün hesaplarda; halkadaki renkler) -->
    <section v-if="ozet && secilenHesap === null && ozet.hesaplar.length > 0">
      <div class="mb-2 flex items-center justify-between">
        <h2 class="bolum-etiketi">Hesaplar</h2>
        <RouterLink :to="{ name: 'hesaplar' }" class="text-sm font-semibold text-primary-600"
          >Tümü</RouterLink
        >
      </div>
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <button
          v-for="h in ozet.hesaplar"
          :key="h.id"
          type="button"
          data-test="ozet-hesap"
          class="kart etkilesimli-kart flex items-center gap-3 p-4 text-left"
          @click="hesabaGec(h.id)"
        >
          <span
            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl text-white"
            :style="{ background: `var(${hesapRengi(h)})` }"
          >
            <i :class="HESAP_TURLERI[h.tur].ikon" />
          </span>
          <span class="min-w-0 flex-1">
            <span class="block truncate font-semibold text-surface-900">{{ h.ad }}</span>
            <span class="block text-sm font-semibold text-surface-700 tabular-nums">
              {{ tl(h.deger) }}
              <span class="ml-1 text-xs font-medium text-surface-400">{{
                oran(h.deger, ozet.toplam.deger)
              }}</span>
            </span>
          </span>
          <DegisimEtiketi :tutar="h.kz" :yuzde="h.kz_yuzde" kucuk class="flex-col !items-end" />
        </button>
      </div>
    </section>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-5">
      <!-- Varlıklar -->
      <section class="kart p-4 sm:p-5 lg:col-span-3">
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
        <PozisyonListesi v-else :pozisyonlar="secili.pozisyonlar" :renkler="secili.renkler" />
      </section>

      <!-- Dağılım (türlere göre) -->
      <DagilimKarti
        v-if="secili && secili.dagilim.length > 0"
        :dagilim="secili.dagilim"
        :toplam-deger="secili.toplam.deger"
        class="lg:col-span-2"
      />
    </div>

    <IslemFormu
      v-model:visible="islemAcik"
      :varsayilan="secilenHesap ? { hesap_id: secilenHesap } : undefined"
      @kaydedildi="islemKaydedildi"
    />
  </div>
</template>
