<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import Skeleton from 'primevue/skeleton'
import BosDurum from '@/components/BosDurum.vue'
import AySecici from '@/components/aile/AySecici.vue'
import KartIslemFormu from '@/components/aile/KartIslemFormu.vue'
import KartIslemListesi from '@/components/aile/KartIslemListesi.vue'
import { aileApi, KART_ISLEM_TURLERI, type KartIslemi, type KartIslemTuru } from '@/api/aile'
import { useSonIstek } from '@/composables/useSonIstek'
import { useAileStore } from '@/stores/aile'
import { tl } from '@/utils/bicim'
import { buAy, gecerliDonem } from '@/utils/donem'

/**
 * Kart işlemleri: ay (rapor ayı / işlem tarihi / ekstre dönemi), kişi, banka, kart, kategori, tür süzgeçleri;
 * toplamlar raporun kuralıyla (kart borcu ödemesi, transfer, nakit avans gider değil). Süzgeçler adreste.
 */
const aile = useAileStore()
const route = useRoute()
const router = useRouter()
const sonIstek = useSonIstek()

const sayi = (d: unknown): number | null =>
  d !== undefined && d !== null && d !== '' && !Number.isNaN(Number(d)) ? Number(d) : null
const q = route.query
const ay = ref(gecerliDonem(String(q.ay ?? '')) ? String(q.ay) : buAy())
const donemTuru = ref<'rapor' | 'islem' | 'ekstre'>(
  ['islem', 'ekstre'].includes(String(q.donem_turu))
    ? (String(q.donem_turu) as 'islem' | 'ekstre')
    : 'rapor',
)
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

const DONEM_TURLERI = [
  { deger: 'rapor', etiket: 'Özet ayı' },
  { deger: 'islem', etiket: 'İşlem tarihi' },
  { deger: 'ekstre', etiket: 'Ekstre dönemi' },
]
const TURLER = (Object.keys(KART_ISLEM_TURLERI) as KartIslemTuru[]).map((t) => ({
  deger: t,
  etiket: KART_ISLEM_TURLERI[t].etiket,
}))
const kategoriSecenekleri = computed(() => [{ id: 'yok', ad: 'Kategorisiz' }, ...aile.kategoriler])

async function yukle(): Promise<void> {
  const veri = await sonIstek(() =>
    aileApi.kartIslemleri({
      ay: ay.value,
      donem_turu: donemTuru.value,
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

watch([ay, donemTuru, uyeId, bankaId, kartId, kategoriId, tur], () => {
  void router.replace({
    query: {
      ay: ay.value,
      donem_turu: donemTuru.value === 'rapor' ? undefined : donemTuru.value,
      uye_id: uyeId.value ?? undefined,
      banka_id: bankaId.value ?? undefined,
      kart_id: kartId.value ?? undefined,
      kategori_id: kategoriId.value ?? undefined,
      tur: tur.value ?? undefined,
    },
  })
  islemler.value = null
  void yukle()
})

function sec(i: KartIslemi | null): void {
  duzenlenen.value = i
  formAcik.value = true
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <AySecici v-model="ay" />
      <Button
        label="İşlem ekle"
        icon="pi pi-plus"
        :disabled="!aile.kartlar.length"
        @click="sec(null)"
      />
    </div>
    <SelectButton
      v-model="donemTuru"
      :options="DONEM_TURLERI"
      option-label="etiket"
      option-value="deger"
      :allow-empty="false"
      aria-label="Hangi aya göre"
      class="w-full sm:w-auto [&>.p-togglebutton]:flex-1"
    />
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
        avans gider sayılmaz (ekstredeki harcamalar zaten sayıldı).
      </p>
    </section>

    <section class="kart p-2 sm:p-4">
      <div v-if="islemler === null" class="flex flex-col gap-2 p-2">
        <Skeleton v-for="n in 5" :key="n" height="3rem" />
      </div>
      <BosDurum
        v-else-if="!islemler.length"
        kucuk
        baslik="Bu süzgeçte işlem yok"
        aciklama="Ekstre yükleyin ya da işlemi elle ekleyin."
        ikon="pi pi-credit-card"
      />
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
