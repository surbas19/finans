<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Skeleton from 'primevue/skeleton'
import BosDurum from '@/components/BosDurum.vue'
import KartIslemFormu from '@/components/aile/KartIslemFormu.vue'
import KartIslemListesi from '@/components/aile/KartIslemListesi.vue'
import { aileApi, KART_ISLEM_TURLERI, type KartIslemi, type KartIslemTuru } from '@/api/aile'
import { useSonIstek } from '@/composables/useSonIstek'
import { useAileStore } from '@/stores/aile'
import { tl, tarihMetni } from '@/utils/bicim'
import { donemdenTarih, gecerliDonem } from '@/utils/donem'

/**
 * Kart işlemleri: arama (açıklama, kategori, kart, tutar), tarih ya da tarih aralığı (işlem tarihine göre), kişi,
 * banka, kart, kategori, tür süzgeçleri. Toplamlar raporun kuralıyla (kart borcu ödemesi, transfer, nakit avans gider
 * değil). Süzgeçler adreste.
 */
const aile = useAileStore()
const route = useRoute()
const router = useRouter()
const sonIstek = useSonIstek()

const sayi = (d: unknown): number | null =>
  d !== undefined && d !== null && d !== '' && !Number.isNaN(Number(d)) ? Number(d) : null
const TARIH_DESENI = /^\d{4}-\d{2}-\d{2}$/
const metindenTarih = (m: unknown): Date | null => {
  if (typeof m !== 'string' || !TARIH_DESENI.test(m)) return null
  const [y, a, g] = m.split('-').map(Number) as [number, number, number]

  return new Date(y, a - 1, g)
}
/** Ayın ilk ve son günü */
function ayAraligi(ay: string): [Date, Date] {
  const ilk = donemdenTarih(ay)

  return [ilk, new Date(ilk.getFullYear(), ilk.getMonth() + 1, 0)]
}
function buAyAraligi(): [Date, Date] {
  const s = new Date()

  return [
    new Date(s.getFullYear(), s.getMonth(), 1),
    new Date(s.getFullYear(), s.getMonth() + 1, 0),
  ]
}

const q = route.query
function baslangicAraligi(): Date[] | null {
  const bas = metindenTarih(q.tarih_bas)
  const bit = metindenTarih(q.tarih_bit)
  if (bas || bit) return bit ? [bas ?? bit, bit] : [bas!]
  // Özet'ten gelen eski bağlantı (?ay=2026-11): o ayın tamamı
  if (gecerliDonem(String(q.ay ?? ''))) return ayAraligi(String(q.ay))

  return q.ara || q.kart_id ? null : buAyAraligi()
}

const ara = ref(String(q.ara ?? ''))
const aralik = ref<Date[] | null>(baslangicAraligi())
const uyeId = ref<number | null>(sayi(q.uye_id))
const bankaId = ref<number | null>(sayi(q.banka_id))
const kartId = ref<number | null>(sayi(q.kart_id))
const kategoriId = ref<number | 'yok' | null>(q.kategori_id === 'yok' ? 'yok' : sayi(q.kategori_id))
const tur = ref<KartIslemTuru | null>(
  q.tur && String(q.tur) in KART_ISLEM_TURLERI ? (String(q.tur) as KartIslemTuru) : null,
)

const islemler = ref<KartIslemi[] | null>(null)
const toplam = ref<{ gider: number; gidere_girmeyen: number; adet: number } | null>(null)
const formAcik = ref(false)
const duzenlenen = ref<KartIslemi | null>(null)

const TURLER = (Object.keys(KART_ISLEM_TURLERI) as KartIslemTuru[]).map((t) => ({
  deger: t,
  etiket: KART_ISLEM_TURLERI[t].etiket,
}))
const kategoriSecenekleri = computed(() => [{ id: 'yok', ad: 'Kategorisiz' }, ...aile.kategoriler])

/** Seçilen aralığın başı / sonu; tek tarih seçilirse (bitiş henüz yok) tek gün */
const tarihBas = computed(() => (aralik.value?.[0] ? tarihMetni(aralik.value[0]) : null))
const tarihBit = computed(() => {
  const [bas, bit] = aralik.value ?? []

  return bit ? tarihMetni(bit) : bas ? tarihMetni(bas) : null
})
const suzgecVar = computed(
  () =>
    ara.value.trim() !== '' ||
    aralik.value !== null ||
    [uyeId, bankaId, kartId, kategoriId, tur].some((r) => r.value !== null),
)

const HIZLI = [
  { etiket: 'Bu ay', sec: (): [Date, Date] => buAyAraligi() },
  {
    etiket: 'Geçen ay',
    sec: (): [Date, Date] => {
      const s = new Date()

      return [
        new Date(s.getFullYear(), s.getMonth() - 1, 1),
        new Date(s.getFullYear(), s.getMonth(), 0),
      ]
    },
  },
  {
    etiket: 'Son 30 gün',
    sec: (): [Date, Date] => {
      const s = new Date()

      return [new Date(s.getFullYear(), s.getMonth(), s.getDate() - 29), s]
    },
  },
]

async function yukle(): Promise<void> {
  const veri = await sonIstek(() =>
    aileApi.kartIslemleri({
      tarih_bas: tarihBas.value,
      tarih_bit: tarihBit.value,
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
}

onMounted(async () => {
  await aile.yukle()
  await yukle()
})

let zamanlayici: ReturnType<typeof setTimeout> | undefined
function adresVeYukle(): void {
  void router.replace({
    query: {
      tarih_bas: tarihBas.value ?? undefined,
      tarih_bit: tarihBit.value ?? undefined,
      ara: ara.value.trim() || undefined,
      uye_id: uyeId.value ?? undefined,
      banka_id: bankaId.value ?? undefined,
      kart_id: kartId.value ?? undefined,
      kategori_id: kategoriId.value ?? undefined,
      tur: tur.value ?? undefined,
    },
  })
  void yukle()
}

// Yazarken 300 ms bekler (her tuşta istek atılmaz); liste önceki sonucu tutar, arama kutusu odağını kaybetmez
watch(ara, () => {
  clearTimeout(zamanlayici)
  zamanlayici = setTimeout(adresVeYukle, 300)
})
// Tarih aralığı: ikinci tarih seçilince (ya da temizlenince) süzülür; tek tarih seçili beklerken tek gün sayılır
watch([aralik, uyeId, bankaId, kartId, kategoriId, tur], () => {
  if (aralik.value && aralik.value.length === 2 && aralik.value[1] === null) return
  clearTimeout(zamanlayici)
  adresVeYukle()
})
onBeforeUnmount(() => clearTimeout(zamanlayici))

function temizle(): void {
  ara.value = ''
  aralik.value = null
  uyeId.value = bankaId.value = kartId.value = kategoriId.value = tur.value = null
}

function sec(i: KartIslemi | null): void {
  duzenlenen.value = i
  formAcik.value = true
}
</script>

<template>
  <div class="flex flex-col gap-4">
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
      <DatePicker
        v-model="aralik"
        selection-mode="range"
        date-format="dd.mm.yy"
        placeholder="Tarih ya da aralık"
        :manual-input="false"
        show-button-bar
        show-icon
        icon-display="input"
        append-to="body"
        aria-label="Tarih ya da tarih aralığı"
        class="w-full sm:w-64"
        data-test="islem-tarih"
      />
    </div>
    <div class="flex gap-2 overflow-x-auto" data-test="hizli-tarih">
      <Button
        v-for="h in HIZLI"
        :key="h.etiket"
        :label="h.etiket"
        size="small"
        severity="secondary"
        rounded
        outlined
        class="shrink-0"
        @click="aralik = h.sec()"
      />
    </div>
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
      <p class="col-span-3 text-[11px] text-surface-500">
        Gider: harcama ve faiz / ücret eklenir, iade düşülür. Kart borcu ödemesi, transfer ve nakit
        avans gider sayılmaz (ekstredeki harcamalar zaten sayıldı). Tarih işlem tarihine göredir;
        Bütçe Özet'te kart gideri ekstre kesim ayına yazıldığından aylık toplam farklı olabilir.
      </p>
    </section>

    <section class="kart p-2 sm:p-4">
      <div v-if="islemler === null" class="flex flex-col gap-2 p-2">
        <Skeleton v-for="n in 5" :key="n" height="3rem" />
      </div>
      <BosDurum
        v-else-if="!islemler.length"
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
      <KartIslemListesi v-else :islemler="islemler" kart-goster @sec="sec" />
    </section>

    <KartIslemFormu
      v-model:visible="formAcik"
      :islem="duzenlenen"
      :kart-id="kartId"
      @kaydedildi="yukle"
    />
  </div>
</template>
