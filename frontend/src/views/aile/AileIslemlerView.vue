<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Accordion from 'primevue/accordion'
import AccordionContent from 'primevue/accordioncontent'
import AccordionHeader from 'primevue/accordionheader'
import AccordionPanel from 'primevue/accordionpanel'
import Button from 'primevue/button'
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Skeleton from 'primevue/skeleton'
import BosDurum from '@/components/BosDurum.vue'
import AySecici from '@/components/aile/AySecici.vue'
import KartEtiketi from '@/components/aile/KartEtiketi.vue'
import KartIslemFormu from '@/components/aile/KartIslemFormu.vue'
import KartIslemListesi from '@/components/aile/KartIslemListesi.vue'
import KategorisizBolumu from '@/components/aile/KategorisizBolumu.vue'
import KategoriSecDialog from '@/components/aile/KategoriSecDialog.vue'
import {
  aileApi,
  KART_ISLEM_TURLERI,
  type KartIslemi,
  type KartIslemToplami,
  type KartIslemTuru,
} from '@/api/aile'
import { useSonIstek } from '@/composables/useSonIstek'
import { useAileStore } from '@/stores/aile'
import { tl } from '@/utils/bicim'
import { buAy, donemEtiketi, gecerliDonem } from '@/utils/donem'

/**
 * Kart işlemleri: bütün ekstre işlemleri kart adının altında akordiyon (süzgeçsizken ilk kart açık). Süzgeç (arama,
 * kişi, banka, kart, kategori, tür; Bütçe Özet'ten gelince ay çipi) uygulanınca bütün kart grupları açık gelir.
 * En üstte ekstre dönemi çarkı (ekstrenin ayı: son ödeme ayı; varsayılan en yeni ekstre dönemi, ondan ileri yok; "Tümü" bütün dönemler).
 * Satıra basılı tutunca kategori seçilir. Süzgeçler adreste.
 */
const aile = useAileStore()
const route = useRoute()
const router = useRouter()
const sonIstek = useSonIstek()

const sayi = (d: unknown): number | null =>
  d !== undefined && d !== null && d !== '' && !Number.isNaN(Number(d)) ? Number(d) : null
const q = route.query
const ara = ref(String(q.ara ?? ''))
/** Bütçe Özet'ten gelen ay (rapor ayı) süzgeci; çip ile kaldırılır */
const ay = ref<string | null>(gecerliDonem(String(q.ay ?? '')) ? String(q.ay) : null)
/** Ekstre dönemi (son ödeme ayı) seçici: null = bütün dönemler. Varsayılan en yeni ekstre dönemi */
const donem = ref<string | null>(null)
/** Seçilebilecek en yeni ekstre dönemi (onaylı ekstrelerin ayı: son ödeme ayı) */
const enYeni = ref<string | null>(null)
let hazir = false
const uyeId = ref<number | null>(sayi(q.uye_id))
const bankaId = ref<number | null>(sayi(q.banka_id))
const kartId = ref<number | null>(sayi(q.kart_id))
const kategoriId = ref<number | 'yok' | null>(q.kategori_id === 'yok' ? 'yok' : sayi(q.kategori_id))
const tur = ref<KartIslemTuru | null>(
  q.tur && String(q.tur) in KART_ISLEM_TURLERI ? (String(q.tur) as KartIslemTuru) : null,
)

const islemler = ref<KartIslemi[] | null>(null)
const toplam = ref<KartIslemToplami | null>(null)
const formAcik = ref(false)
const duzenlenen = ref<KartIslemi | null>(null)
const kategoriAcik = ref(false)
const kategorilenen = ref<KartIslemi | null>(null)
/** Açık kart grupları (kart kimliği, metin) */
const acik = ref<string[]>([])
let acikSifirla = true

const TURLER = (Object.keys(KART_ISLEM_TURLERI) as KartIslemTuru[]).map((t) => ({
  deger: t,
  etiket: KART_ISLEM_TURLERI[t].etiket,
}))
const kategoriSecenekleri = computed(() => [{ id: 'yok', ad: 'Kategorisiz' }, ...aile.kategoriler])

/** AySecici modeli: seçim yapınca Özet ay çipi kalkar */
const donemModeli = computed({
  get: () => donem.value ?? enYeni.value ?? buAy(),
  set: (v: string) => {
    ay.value = null
    donem.value = v
  },
})

const suzgecVar = computed(
  () =>
    ara.value.trim() !== '' ||
    ay.value !== null ||
    [uyeId, bankaId, kartId, kategoriId, tur].some((r) => r.value !== null),
)

/** Kategorisiz harcamalar (listedeki süzgece göre): hızlı kategori çipleriyle ayrı bölümde */
const kategorisizler = computed(() =>
  (islemler.value ?? []).filter((i) => i.kategori_id === null && i.tur === 'harcama'),
)

/** İşlemler kart başına gruplanır (grup başlığındaki gider / dönem sunucudan) */
const gruplar = computed(() => {
  const harita = new Map<number, KartIslemi[]>()
  for (const i of islemler.value ?? []) {
    const liste = harita.get(i.kart_id) ?? []
    liste.push(i)
    harita.set(i.kart_id, liste)
  }

  return [...harita.entries()].map(([kart, liste]) => ({
    kart,
    liste,
    bilgi: toplam.value?.kartlar?.find((k) => k.kart_id === kart) ?? null,
  }))
})

async function yukle(): Promise<void> {
  const veri = await sonIstek(() =>
    aileApi.kartIslemleri({
      ay: ay.value ?? donem.value,
      donem_turu: ay.value === null && donem.value !== null ? 'ekstre' : null,
      ara: ara.value.trim() || null,
      uye_id: uyeId.value,
      banka_id: bankaId.value,
      kart_id: kartId.value,
      kategori_id: kategoriId.value,
      tur: tur.value,
    }),
  )
  if (veri === undefined) return
  islemler.value = veri.islemler
  toplam.value = veri.toplam
  // Süzgeç değişince / ilk yüklemede açık gruplar yeniden belirlenir; kategori vb. yenilemede kullanıcının seçimi korunur
  if (acikSifirla) {
    acikSifirla = false
    const idler = gruplar.value.map((g) => String(g.kart))
    acik.value = suzgecVar.value ? idler : idler.slice(0, 1)
  }
}

onMounted(async () => {
  await aile.yukle()
  try {
    const ekstreler = await aileApi.ekstreler()
    const donemler = ekstreler
      .filter((e) => e.durum === 'onaylandi' && gecerliDonem(e.donem))
      .map((e) => e.donem)
      .sort()
    enYeni.value = donemler[donemler.length - 1] ?? null
  } catch {
    enYeni.value = null
  }
  // Başlangıç: adresteki dönem ('tumu' = hepsi) > Özet ay çipi (dönem seçilmez) > en yeni ekstre dönemi
  const adresDonem = String(q.donem ?? '')
  donem.value =
    adresDonem === 'tumu'
      ? null
      : gecerliDonem(adresDonem)
        ? adresDonem
        : ay.value !== null
          ? null
          : enYeni.value
  await yukle()
  // İlk atamalar süzgeç değişikliği sayılmasın (çift istek olmasın)
  await nextTick()
  hazir = true
})

let zamanlayici: ReturnType<typeof setTimeout> | undefined
function adresVeYukle(): void {
  void router.replace({
    query: {
      ay: ay.value ?? undefined,
      donem: ay.value === null ? (donem.value ?? 'tumu') : undefined,
      ara: ara.value.trim() || undefined,
      uye_id: uyeId.value ?? undefined,
      banka_id: bankaId.value ?? undefined,
      kart_id: kartId.value ?? undefined,
      kategori_id: kategoriId.value ?? undefined,
      tur: tur.value ?? undefined,
    },
  })
  acikSifirla = true
  void yukle()
}

// Yazarken 300 ms bekler (her tuşta istek atılmaz); liste önceki sonucu tutar, arama kutusu odağını kaybetmez
watch(ara, () => {
  if (!hazir) return
  clearTimeout(zamanlayici)
  zamanlayici = setTimeout(adresVeYukle, 300)
})
watch([ay, donem, uyeId, bankaId, kartId, kategoriId, tur], () => {
  if (!hazir) return
  clearTimeout(zamanlayici)
  adresVeYukle()
})
onBeforeUnmount(() => clearTimeout(zamanlayici))

function temizle(): void {
  ara.value = ''
  ay.value = null
  donem.value = enYeni.value
  uyeId.value = bankaId.value = kartId.value = kategoriId.value = tur.value = null
}

function sec(i: KartIslemi | null): void {
  duzenlenen.value = i
  formAcik.value = true
}

function kategoriSec(i: KartIslemi): void {
  kategorilenen.value = i
  kategoriAcik.value = true
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <!-- Ekstre dönemi çarkı: "Tümü" seçiliyken "Tüm dönemler" yazar, çarkı oynatınca bir dönem seçilir -->
    <div data-test="donem-secici">
      <AySecici v-model="donemModeli" :ileri-sinir="enYeni ?? undefined" :bos="donem === null">
        <template #ek>
          <button
            type="button"
            class="ay-carki-ek"
            :aria-pressed="donem === null"
            data-test="donem-tumu"
            @click="donem === null ? (donemModeli = enYeni ?? buAy()) : (donem = null)"
          >
            Tümü
          </button>
        </template>
      </AySecici>
    </div>
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
      <IconField class="min-w-0 flex-1">
        <InputIcon class="pi pi-search" />
        <InputText
          v-model="ara"
          type="search"
          placeholder="Ara: açıklama, kategori, kart, tutar"
          aria-label="İşlem ara"
          class="w-full"
          data-test="islem-ara"
        />
      </IconField>
    </div>
    <button
      v-if="ay"
      type="button"
      class="-mt-2 inline-flex items-center gap-1.5 self-start rounded-full bg-primary-50 px-3 py-1.5 text-xs font-semibold text-primary-700 ring-1 ring-primary-200"
      data-test="ay-cipi"
      @click="ay = null"
    >
      {{ donemEtiketi(ay) }} (Bütçe Özet ayı) <i class="pi pi-times text-[10px]" />
    </button>
    <div class="grid grid-cols-2 gap-2 sm:grid-cols-5">
      <Select
        v-model="uyeId"
        :options="aile.uyeler"
        option-label="ad"
        option-value="id"
        placeholder="Kişi"
        show-clear
        aria-label="Kişi"
      />
      <Select
        v-model="bankaId"
        :options="aile.bankalar"
        option-label="ad"
        option-value="id"
        placeholder="Banka"
        show-clear
        aria-label="Banka"
      />
      <Select
        v-model="kartId"
        :options="aile.kartlar"
        option-label="ad"
        option-value="id"
        placeholder="Kart"
        show-clear
        aria-label="Kart"
      />
      <Select
        v-model="kategoriId"
        :options="kategoriSecenekleri"
        option-label="ad"
        option-value="id"
        placeholder="Kategori"
        show-clear
        aria-label="Kategori"
      />
      <Select
        v-model="tur"
        :options="TURLER"
        option-label="etiket"
        option-value="deger"
        placeholder="Tür"
        show-clear
        aria-label="Tür"
        class="col-span-2 sm:col-span-1"
      />
    </div>

    <section v-if="toplam" class="kart grid grid-cols-3 gap-3 p-4">
      <div>
        <p class="text-xs text-surface-500">Gider</p>
        <p class="font-bold text-surface-900 tabular-nums" data-test="islem-gider">
          {{ tl(toplam.gider) }}
        </p>
      </div>
      <div>
        <p class="text-xs text-surface-500">Gidere girmeyen</p>
        <p class="font-semibold text-surface-500 tabular-nums">{{ tl(toplam.gidere_girmeyen) }}</p>
      </div>
      <div>
        <p class="text-xs text-surface-500">Satır</p>
        <p class="font-semibold tabular-nums">{{ toplam.adet }}</p>
      </div>
      <p class="col-span-3 text-[11px] text-surface-500" data-test="islem-not">
        Gider: harcama ve faiz / ücret eklenir, iade düşülür. Kart borcu ödemesi, transfer ve nakit
        avans gider sayılmaz (ekstredeki harcamalar zaten sayıldı).
      </p>
    </section>
    <p class="-mt-2 text-center text-[11px] text-surface-400" data-test="uzun-basma-ipucu">
      <i class="pi pi-tag text-[10px]" /> Kategori vermek için işleme basılı tutun
    </p>

    <div v-if="islemler === null" class="kart flex flex-col gap-2 p-4">
      <Skeleton v-for="n in 5" :key="n" height="3rem" />
    </div>
    <section v-else-if="!islemler.length" class="kart p-2 sm:p-4">
      <BosDurum
        kucuk
        :baslik="suzgecVar ? 'Eşleşen işlem yok' : 'Henüz işlem yok'"
        :aciklama="suzgecVar ? undefined : 'Ekstre yükleyin; işlemler buraya gelir.'"
        ikon="pi pi-credit-card"
      >
        <Button
          v-if="suzgecVar"
          label="Süzgeçleri temizle"
          icon="pi pi-filter-slash"
          severity="secondary"
          outlined
          data-test="suzgec-temizle"
          @click="temizle"
        />
      </BosDurum>
    </section>
    <template v-else>
      <KategorisizBolumu
        v-if="kategorisizler.length"
        :islemler="kategorisizler"
        @degisti="yukle"
        @diger="kategoriSec"
      />
      <section class="kart p-2" data-test="kart-gruplari">
        <Accordion
          v-model:value="acik"
          multiple
          class="[&_.p-accordioncontent-wrapper]:min-w-0 [&_.p-accordioncontent-wrapper]:overflow-hidden"
        >
          <AccordionPanel v-for="g in gruplar" :key="g.kart" :value="String(g.kart)">
            <AccordionHeader data-test="kart-grubu-baslik">
              <span class="flex min-w-0 flex-1 items-center justify-between gap-3 pr-2">
                <span class="min-w-0">
                  <span class="block truncate font-semibold"
                    ><KartEtiketi :kart-id="g.kart"
                  /></span>
                  <span
                    v-if="g.bilgi?.ekstre_donemi"
                    class="block text-xs font-normal text-surface-500"
                    >{{ g.bilgi.ekstre_donemi }} ekstresi</span
                  >
                </span>
                <span class="shrink-0 text-right">
                  <span class="block text-sm font-semibold tabular-nums">{{
                    tl(g.bilgi?.gider ?? null)
                  }}</span>
                  <span class="block text-xs font-normal text-surface-500"
                    >{{ g.liste.length }} satır</span
                  >
                </span>
              </span>
            </AccordionHeader>
            <AccordionContent>
              <KartIslemListesi :islemler="g.liste" @sec="sec" @kategori="kategoriSec" />
            </AccordionContent>
          </AccordionPanel>
        </Accordion>
      </section>
    </template>

    <KartIslemFormu
      v-model:visible="formAcik"
      :islem="duzenlenen"
      :kart-id="kartId"
      @kaydedildi="yukle"
    />
    <KategoriSecDialog v-model:visible="kategoriAcik" :islem="kategorilenen" @degisti="yukle" />
  </div>
</template>
