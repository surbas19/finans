<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import { useToast } from 'primevue/usetoast'
import BosDurum from '@/components/BosDurum.vue'
import FaturaGrafigi from '@/components/grafik/FaturaGrafigi.vue'
import AySecici from '@/components/aile/AySecici.vue'
import DonemBilinmeyenDialog from '@/components/aile/DonemBilinmeyenDialog.vue'
import FaturaBaglaDialog from '@/components/aile/FaturaBaglaDialog.vue'
import FaturaDetayi from '@/components/aile/FaturaDetayi.vue'
import FaturaFormu from '@/components/aile/FaturaFormu.vue'
import FaturaIkonu from '@/components/aile/FaturaIkonu.vue'
import KartEtiketi from '@/components/aile/KartEtiketi.vue'
import SaglayiciGecmisiDialog from '@/components/aile/SaglayiciGecmisiDialog.vue'
import { aileApi, type Fatura, type FaturaAyOzeti } from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { useSonIstek } from '@/composables/useSonIstek'
import { useAileStore } from '@/stores/aile'
import { bugun, tl } from '@/utils/bicim'
import { buAy, donemEtiketi } from '@/utils/donem'
import { faturaGirdisi, gunAy, gunFarki, toplamTl } from '@/utils/fatura'

/**
 * Faturalar: ödeme panosu (hizmet ayına göre). Üstte ay çarkı ve özet (N / M geldi, toplam, önceki aya göre,
 * ödendi / bekliyor / gecikti çubuğu); dönemi bilinmeyen varsa uyarı şeridi (pencerede ay yazılır); tür çipleri;
 * liste durum gruplarında: Gecikmiş, Bekleyen (son ödemesi yakın önce; bu ay gelmesi beklenip gelmeyenler soluk
 * "Gelmedi" satırı), Ödenen. Satıra dokununca detay (kart bağı, ödendi, geçmiş, düzenle); grafik en altta kapalı.
 * Özet sunucuda (`FaturaOzeti`), grup toplamları burada kuruşla.
 */
const aile = useAileStore()
const toast = useToast()
const sonIstek = useSonIstek()

const donem = ref(buAy())
const ozet = ref<FaturaAyOzeti | null>(null)
const faturalar = ref<Fatura[] | null>(null)
const turId = ref<number | null>(null)

const formAcik = ref(false)
const duzenlenen = ref<Fatura | null>(null)
const formSaglayici = ref<number | null>(null)
const detayAcik = ref(false)
const secili = ref<Fatura | null>(null)
const gecmisAcik = ref(false)
const gecmisSaglayici = ref<number | null>(null)
const bilinmeyenAcik = ref(false)
const baglaAcik = ref(false)
const baglanan = ref<Fatura | null>(null)
const tumBagliIslemler = ref<number[]>([])

async function yukle(): Promise<void> {
  const veri = await sonIstek(() =>
    Promise.all([aileApi.faturaOzeti(donem.value), aileApi.faturalar({ donem: donem.value })]),
  )
  if (veri === undefined) return
  ozet.value = veri[0]
  faturalar.value = veri[1].faturalar
}

onMounted(async () => {
  await aile.yukle()
  await yukle()
})
watch(donem, () => {
  ozet.value = null
  faturalar.value = null
  turId.value = null
  void yukle()
})

/** Bu ay görünen türler (gelmeyenler dahil): çip yalnız birden çok tür varsa */
const turler = computed(() => {
  const idler = new Set([
    ...(faturalar.value ?? []).map((f) => f.fatura_turu_id),
    ...(ozet.value?.gelmeyenler ?? []).map((g) => g.fatura_turu_id),
  ])

  return aile.turler.filter((t) => idler.has(t.id))
})
const turUygun = (id: number): boolean => turId.value === null || turId.value === id

const gruplar = computed(() => {
  const liste = (faturalar.value ?? []).filter((f) => turUygun(f.fatura_turu_id))
  const sonOdeme = (f: Fatura): string => f.son_odeme_tarihi ?? '9999-12-31'
  const gecikmis = liste
    .filter((f) => f.gecikmis)
    .sort((a, b) => sonOdeme(a).localeCompare(sonOdeme(b)))
  const bekleyen = liste
    .filter((f) => f.durum === 'bekliyor' && !f.gecikmis)
    .sort((a, b) => sonOdeme(a).localeCompare(sonOdeme(b)))
  const odenen = liste
    .filter((f) => f.durum === 'odendi')
    .sort((a, b) => (b.odeme_tarihi ?? '').localeCompare(a.odeme_tarihi ?? ''))

  return {
    gecikmis,
    bekleyen,
    odenen,
    gelmeyenler: (ozet.value?.gelmeyenler ?? []).filter((g) => turUygun(g.fatura_turu_id)),
  }
})
const toplam = (liste: Fatura[]): number => toplamTl(liste.map((f) => f.tutar))
const bos = computed(
  () =>
    faturalar.value !== null &&
    !gruplar.value.gecikmis.length &&
    !gruplar.value.bekleyen.length &&
    !gruplar.value.odenen.length &&
    !gruplar.value.gelmeyenler.length,
)

/** Önceki aya göre yüzde (önceki ay faturası yoksa ya da bu ay boşsa gösterilmez) */
const fark = computed(() => {
  const o = ozet.value
  if (!o || o.onceki_toplam <= 0 || o.toplam <= 0) return null
  const yuzde = ((o.toplam - o.onceki_toplam) / o.onceki_toplam) * 100

  return { yuzde, ay: donemEtiketi(o.onceki_donem).split(' ')[0] }
})
const pay = (tutar: number): string =>
  ozet.value && ozet.value.toplam > 0 ? `${(tutar / ozet.value.toplam) * 100}%` : '0%'

function altSatir(f: Fatura): string {
  if (f.durum === 'odendi') return gunAy(f.odeme_tarihi)
  if (!f.son_odeme_tarihi) return 'son ödeme tarihi yok'
  const gun = gunFarki(f.son_odeme_tarihi)
  const ne = gun < 0 ? `${-gun} gün geçti` : gun === 0 ? 'bugün' : `${gun} gün kaldı`

  return `son ödeme ${gunAy(f.son_odeme_tarihi)} · ${ne}`
}

function detayAc(f: Fatura): void {
  secili.value = f
  detayAcik.value = true
}

function faturaEkle(saglayiciId: number | null = null): void {
  duzenlenen.value = null
  formSaglayici.value = saglayiciId
  formAcik.value = true
}

function duzenle(f: Fatura): void {
  duzenlenen.value = f
  formSaglayici.value = null
  formAcik.value = true
}

function gecmisAc(saglayiciId: number): void {
  detayAcik.value = false
  gecmisSaglayici.value = saglayiciId
  gecmisAcik.value = true
}

/** Bağlama penceresi: bir kart satırı tek faturaya bağlanır, bağlı satırlar gizlenir */
async function baglamayaAc(f: Fatura): Promise<void> {
  try {
    tumBagliIslemler.value = (await aileApi.faturalar({})).faturalar
      .map((x) => x.kart_islemi_id)
      .filter((id): id is number => id !== null)
  } catch {
    tumBagliIslemler.value = []
  }
  baglanan.value = f
  baglaAcik.value = true
}

const isaretleniyor = ref<number | null>(null)
async function odendi(f: Fatura): Promise<void> {
  isaretleniyor.value = f.id
  try {
    await aileApi.faturaGuncelle(f.id, faturaGirdisi(f, { durum: 'odendi', odeme_tarihi: bugun() }))
    toast.add({
      severity: 'success',
      summary: `${f.saglayici_ad} ödendi olarak işaretlendi`,
      life: 3500,
    })
    await yukle()
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Yapılamadı', detail: hataMesaji(e), life: 5000 })
  } finally {
    isaretleniyor.value = null
  }
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <AySecici v-model="donem" class="ay-carki-yapiskan" />

    <p v-if="!aile.saglayicilar.length" class="kart p-4 text-sm text-surface-600">
      Önce Ayarlar'dan fatura sağlayıcılarınızı (ör. Enerjisa, İSKİ, İGDAŞ) ve ekstrede görünen
      yazımlarını ekleyin; ekstre yüklenince fatura ödemeleri bunlarla bulunur.
    </p>

    <!-- Özet -->
    <section class="kart p-4" data-test="fatura-ozeti">
      <Skeleton v-if="!ozet" height="5.5rem" border-radius="1rem" />
      <template v-else>
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0">
            <span class="block text-sm font-semibold text-surface-600"
              >{{ donemEtiketi(ozet.donem).split(' ')[0] }} faturaları<template
                v-if="ozet.beklenen"
              >
                · {{ ozet.adet }} / {{ ozet.beklenen }} geldi</template
              ></span
            >
            <span class="block text-2xl font-bold tabular-nums" data-test="fatura-toplam">{{
              tl(ozet.toplam)
            }}</span>
            <span
              v-if="fark"
              class="block text-xs font-semibold"
              :class="fark.yuzde > 0 ? 'text-red-700' : 'text-emerald-700'"
              data-test="fatura-fark"
              >{{ fark.yuzde > 0 ? '▲' : '▼' }} %{{
                Math.abs(fark.yuzde).toLocaleString('tr-TR', { maximumFractionDigits: 0 })
              }}
              {{ fark.ay }}'e göre</span
            >
          </div>
          <Button
            label="Fatura"
            icon="pi pi-plus"
            size="small"
            :disabled="!aile.saglayicilar.length"
            data-test="fatura-ekle"
            @click="faturaEkle()"
          />
        </div>
        <template v-if="ozet.toplam > 0">
          <div
            class="mt-3 flex h-2 gap-0.5 overflow-hidden rounded-full bg-surface-100"
            aria-hidden="true"
          >
            <span class="bg-emerald-500" :style="{ width: pay(ozet.odendi.tutar) }" />
            <span class="bg-amber-500" :style="{ width: pay(ozet.bekliyor.tutar) }" />
            <span class="bg-red-500" :style="{ width: pay(ozet.gecikti.tutar) }" />
          </div>
          <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-surface-600">
            <span class="flex items-center gap-1"
              ><i class="size-2 rounded-sm bg-emerald-500" />Ödendi
              <b class="tabular-nums">{{ tl(ozet.odendi.tutar) }}</b></span
            >
            <span v-if="ozet.bekliyor.adet" class="flex items-center gap-1"
              ><i class="size-2 rounded-sm bg-amber-500" />Bekliyor
              <b class="tabular-nums">{{ tl(ozet.bekliyor.tutar) }}</b></span
            >
            <span v-if="ozet.gecikti.adet" class="flex items-center gap-1"
              ><i class="size-2 rounded-sm bg-red-500" />Gecikti
              <b class="tabular-nums">{{ tl(ozet.gecikti.tutar) }}</b></span
            >
          </div>
        </template>
      </template>
    </section>

    <button
      v-if="ozet?.bilinmeyen_adet"
      type="button"
      class="flex items-center gap-2 rounded-2xl bg-amber-100 px-3 py-2.5 text-left text-sm font-semibold text-amber-800"
      data-test="bilinmeyen-bant"
      @click="bilinmeyenAcik = true"
    >
      <i class="pi pi-question-circle" />
      <span class="flex-1">{{ ozet.bilinmeyen_adet }} faturanın dönemi bilinmiyor</span>
      <i class="pi pi-chevron-right text-xs" />
    </button>

    <div
      v-if="turler.length > 1"
      class="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1"
      role="group"
      aria-label="Fatura türü"
    >
      <button
        v-for="t in [{ id: null, ad: 'Tümü' }, ...turler]"
        :key="t.id ?? 'tumu'"
        type="button"
        class="rounded-full border px-3 py-1 text-sm font-semibold whitespace-nowrap"
        :class="
          turId === t.id
            ? 'border-primary-600 bg-primary-600 text-white'
            : 'border-surface-200 bg-surface-0 text-surface-600'
        "
        :aria-pressed="turId === t.id"
        data-test="tur-cipi"
        @click="turId = t.id"
      >
        {{ t.ad }}
      </button>
    </div>

    <div v-if="faturalar === null" class="flex flex-col gap-2">
      <Skeleton v-for="n in 3" :key="n" height="3.5rem" border-radius="1rem" />
    </div>
    <BosDurum
      v-else-if="bos"
      baslik="Bu ay fatura yok"
      aciklama="Ekstre yükleyince fatura ödemeleri bulunur; eski faturaları elle de girebilirsiniz."
      ikon="pi pi-bolt"
    />
    <template v-else>
      <!-- Gecikmiş -->
      <section v-if="gruplar.gecikmis.length" data-test="grup-gecikmis">
        <h2 class="flex justify-between px-1 pb-1.5 text-sm font-bold text-red-700">
          <span>Gecikmiş</span><span class="tabular-nums">{{ tl(toplam(gruplar.gecikmis)) }}</span>
        </h2>
        <ul class="kart divide-y divide-surface-100 overflow-hidden">
          <li v-for="f in gruplar.gecikmis" :key="f.id" class="flex items-center gap-3 px-3 py-2.5">
            <button
              type="button"
              class="flex min-w-0 flex-1 items-center gap-3 text-left"
              data-test="fatura"
              @click="detayAc(f)"
            >
              <FaturaIkonu :tur-id="f.fatura_turu_id" />
              <span class="min-w-0">
                <span class="block truncate text-sm font-semibold text-surface-900">{{
                  f.saglayici_ad
                }}</span>
                <span class="block truncate text-xs text-red-700">{{ altSatir(f) }}</span>
              </span>
            </button>
            <span class="flex shrink-0 flex-col items-end gap-0.5">
              <span class="text-sm font-bold tabular-nums">{{ tl(f.tutar) }}</span>
              <Button
                label="Ödendi"
                icon="pi pi-check"
                text
                size="small"
                class="!px-1 !py-0 !text-xs"
                :loading="isaretleniyor === f.id"
                data-test="odendi-isaretle"
                @click="odendi(f)"
              />
            </span>
          </li>
        </ul>
      </section>

      <!-- Bekleyen + gelmeyen -->
      <section
        v-if="gruplar.bekleyen.length || gruplar.gelmeyenler.length"
        data-test="grup-bekleyen"
      >
        <h2 class="flex justify-between px-1 pb-1.5 text-sm font-bold text-surface-600">
          <span>Bekleyen</span><span class="tabular-nums">{{ tl(toplam(gruplar.bekleyen)) }}</span>
        </h2>
        <ul class="kart divide-y divide-surface-100 overflow-hidden">
          <li v-for="f in gruplar.bekleyen" :key="f.id" class="flex items-center gap-3 px-3 py-2.5">
            <button
              type="button"
              class="flex min-w-0 flex-1 items-center gap-3 text-left"
              data-test="fatura"
              @click="detayAc(f)"
            >
              <FaturaIkonu :tur-id="f.fatura_turu_id" />
              <span class="min-w-0">
                <span class="block truncate text-sm font-semibold text-surface-900">{{
                  f.saglayici_ad
                }}</span>
                <span class="block truncate text-xs text-surface-500">{{ altSatir(f) }}</span>
              </span>
            </button>
            <span class="flex shrink-0 flex-col items-end gap-0.5">
              <span class="text-sm font-bold tabular-nums">{{ tl(f.tutar) }}</span>
              <Button
                label="Ödendi"
                icon="pi pi-check"
                text
                size="small"
                class="!px-1 !py-0 !text-xs"
                :loading="isaretleniyor === f.id"
                data-test="odendi-isaretle"
                @click="odendi(f)"
              />
            </span>
          </li>
          <li v-for="g in gruplar.gelmeyenler" :key="`g-${g.saglayici_id}`">
            <button
              type="button"
              class="flex w-full items-center gap-3 px-3 py-2.5 text-left"
              :aria-label="`${g.saglayici_ad}: bu ay henüz gelmedi, fatura ekle`"
              data-test="gelmeyen"
              @click="faturaEkle(g.saglayici_id)"
            >
              <FaturaIkonu :tur-id="g.fatura_turu_id" soluk />
              <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-semibold text-surface-500">{{
                  g.saglayici_ad
                }}</span>
                <span class="block truncate text-xs text-surface-500"
                  >henüz gelmedi · {{ g.son_donem_etiketi.split(' ')[0] }}
                  {{ tl(g.son_tutar) }}</span
                >
              </span>
              <span
                class="rounded-full bg-surface-100 px-2 py-0.5 text-xs font-semibold text-surface-600"
                >Gelmedi</span
              >
            </button>
          </li>
        </ul>
      </section>

      <!-- Ödenen -->
      <section v-if="gruplar.odenen.length" data-test="grup-odenen">
        <h2 class="flex justify-between px-1 pb-1.5 text-sm font-bold text-surface-600">
          <span>Ödenen ({{ gruplar.odenen.length }})</span
          ><span class="tabular-nums">{{ tl(toplam(gruplar.odenen)) }}</span>
        </h2>
        <ul class="kart divide-y divide-surface-100 overflow-hidden">
          <li v-for="f in gruplar.odenen" :key="f.id">
            <button
              type="button"
              class="flex w-full items-center gap-3 px-3 py-2.5 text-left"
              data-test="fatura"
              @click="detayAc(f)"
            >
              <FaturaIkonu :tur-id="f.fatura_turu_id" />
              <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-semibold text-surface-900">{{
                  f.saglayici_ad
                }}</span>
                <span class="flex items-center gap-1.5 text-xs text-surface-500">
                  <span>{{ altSatir(f) }}</span>
                  <span class="text-surface-300">·</span>
                  <KartEtiketi v-if="f.kart_islemi" :kart-id="f.kart_islemi.kart_id" />
                  <span v-else>kartsız</span>
                </span>
              </span>
              <span class="text-sm font-bold tabular-nums">{{ tl(f.tutar) }}</span>
            </button>
          </li>
        </ul>
      </section>
    </template>

    <FaturaGrafigi kapali />

    <FaturaDetayi
      v-model:visible="detayAcik"
      :fatura="secili"
      @duzenle="duzenle"
      @bagla="baglamayaAc"
      @gecmis="gecmisAc"
      @degisti="yukle"
    />
    <SaglayiciGecmisiDialog
      v-model:visible="gecmisAcik"
      :saglayici-id="gecmisSaglayici"
      @sec="detayAc"
    />
    <DonemBilinmeyenDialog v-model:visible="bilinmeyenAcik" @degisti="yukle" @sec="detayAc" />
    <FaturaFormu
      v-model:visible="formAcik"
      :fatura="duzenlenen"
      :varsayilan-donem="donem"
      :varsayilan-saglayici="formSaglayici"
      @kaydedildi="yukle"
    />
    <FaturaBaglaDialog
      v-model:visible="baglaAcik"
      :fatura="baglanan"
      :bagli-islemler="tumBagliIslemler"
      @baglandi="yukle"
    />
  </div>
</template>
