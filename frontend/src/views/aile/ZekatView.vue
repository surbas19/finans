<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import ZekatAyarlari from '@/components/zekat/ZekatAyarlari.vue'
import ZekatDonemleri from '@/components/zekat/ZekatDonemleri.vue'
import ZekatOdemeFormu from '@/components/zekat/ZekatOdemeFormu.vue'
import ZekatSonucKarti from '@/components/zekat/ZekatSonucKarti.vue'
import ZekatYilListesi from '@/components/zekat/ZekatYilListesi.vue'
import {
  aileApi,
  ZEKAT_UYARISI,
  type ZekatAyarYaniti,
  type ZekatDonemi,
  type ZekatOdemesi,
  type ZekatHesabiYaniti,
  type ZekatSonucu,
  type ZekatYili,
  type ZekatYiliDetayi,
} from '@/api/aile'
import { dogrulamaHatalari, hataMesaji } from '@/api/http'
import { tarih, tl } from '@/utils/bicim'

/**
 * Zekât: portföydeki hesapların toplam değeri üzerinden hesap ve takip. Akış: ayarlar → hesapla → anlık görüntüyü
 * kaydet → kesinleştir → ödeme ekle → yıl geçmişi. Değerleme Portföy ile aynı kaynaktan gelir (ayrı hesap yok).
 */
const toast = useToast()
const confirm = useConfirm()
const ayar = ref<ZekatAyarYaniti | null>(null)
const yillar = ref<ZekatYili[]>([])
const sonuc = ref<ZekatHesabiYaniti | null>(null)
const hesapliyor = ref(false)
const hata = ref('')
const kaydediliyor = ref(false)

const ayarAcik = ref(false)
const odemeAcik = ref(false)
/** Ödeme formunun bağlı yılı (kesinleşmiş zekât yılı) ve önerilen tutar (kalan) */
const odemeYilId = ref<number | null>(null)
const odemeKalan = ref<number | null>(null)
const donemler = ref<ZekatDonemi[]>([])
const detay = ref<ZekatYiliDetayi | null>(null)
const detayAcik = ref(false)

async function yukle(): Promise<void> {
  const [a, y, d] = await Promise.all([
    aileApi.zekatAyar(),
    aileApi.zekatYillari(),
    aileApi.zekatDonemleri(),
  ])
  ayar.value = a
  yillar.value = y
  donemler.value = d
}

onMounted(() => {
  void yukle().catch((e) => {
    hata.value = hataMesaji(e)
  })
})

async function hesapla(): Promise<void> {
  hesapliyor.value = true
  hata.value = ''
  try {
    sonuc.value = await aileApi.zekatHesapla()
  } catch (e) {
    sonuc.value = null
    // Nisab fiyatı yoksa sunucu hata verir; uydurulmaz
    hata.value = Object.values(dogrulamaHatalari(e))[0] ?? hataMesaji(e)
  } finally {
    hesapliyor.value = false
  }
}

async function kaydet(): Promise<void> {
  kaydediliyor.value = true
  try {
    const yil = await aileApi.zekatYiliKaydet({})
    toast.add({ severity: 'success', summary: 'Anlık görüntü kaydedildi (taslak)', life: 3000 })
    await yukle()
    await detayAc(yil.id)
  } catch (e) {
    toast.add({
      severity: 'error',
      summary: 'Kaydedilemedi',
      detail: Object.values(dogrulamaHatalari(e))[0] ?? hataMesaji(e),
      life: 6000,
    })
  } finally {
    kaydediliyor.value = false
  }
}

async function detayAc(id: number): Promise<void> {
  detay.value = await aileApi.zekatYili(id)
  detayAcik.value = true
}

function yilSec(y: ZekatYili): void {
  void detayAc(y.id)
}

/** Kayıtlı yılın değişmez görünümü, önizlemeyle aynı kartta gösterilir */
const detaySonucu = computed<ZekatSonucu | null>(() => {
  const d = detay.value
  if (!d) return null

  return {
    brut: d.brut,
    borc: d.borc,
    net: d.net,
    nisab: {
      olcut: d.ayar.nisab_olcutu,
      gram: d.ayar.nisab_olcutu === 'gumus' ? d.ayar.nisab_gumus_gram : d.ayar.nisab_altin_gram,
      birim_fiyat: d.nisab_birim_fiyat,
      tl: d.nisab,
    },
    nisab_ustu: d.nisab_ustu,
    zekat: d.zekat,
    kalemler: d.kalemler,
    borclar: d.borclar,
    eksikler: d.eksikler,
    eksik_veri: d.eksik_veri,
    oran_binde: d.ayar.oran_binde,
    borc_dusuldu: d.ayar.borc_dus,
  }
})

async function kesinlestir(): Promise<void> {
  if (!detay.value) return
  confirm.require({
    header: 'Kesinleştir',
    message: 'Kesinleşen kayıt değiştirilemez ve silinemez. Kesinleştirilsin mi?',
    acceptProps: { label: 'Kesinleştir' },
    rejectProps: { label: 'Vazgeç', severity: 'secondary', text: true },
    accept: async () => {
      try {
        detay.value = await aileApi.zekatYiliKesinlestir(detay.value!.id)
        await yukle()
        toast.add({ severity: 'success', summary: 'Zekât yılı kesinleşti', life: 3000 })
      } catch (e) {
        toast.add({ severity: 'error', summary: 'Yapılamadı', detail: hataMesaji(e), life: 6000 })
      }
    },
  })
}

function taslakSil(): void {
  if (!detay.value) return
  const id = detay.value.id
  confirm.require({
    header: 'Taslağı sil',
    message: 'Bu taslak silinsin mi?',
    acceptProps: { label: 'Sil', severity: 'danger' },
    rejectProps: { label: 'Vazgeç', severity: 'secondary', text: true },
    accept: async () => {
      try {
        await aileApi.zekatYiliSil(id)
        detayAcik.value = false
        await yukle()
        toast.add({ severity: 'success', summary: 'Taslak silindi', life: 3000 })
      } catch (e) {
        toast.add({ severity: 'error', summary: 'Silinemedi', detail: hataMesaji(e), life: 6000 })
      }
    },
  })
}

function odemeEkle(): void {
  odemeYilId.value = detay.value?.id ?? null
  odemeKalan.value = detay.value?.kalan ?? null
  odemeAcik.value = true
}

/** Zekât yılı bölümünden: kesinleşmiş yıla bağlanır, tutar önerisi kalan; yıl yoksa tarihe göre sunucu bağlar */
function donemdeOdemeEkle(d: ZekatDonemi): void {
  odemeYilId.value = d.yil?.durum === 'kesinlesti' ? d.yil.id : null
  odemeKalan.value = d.kalan
  odemeAcik.value = true
}

function genelOdemeEkle(): void {
  odemeYilId.value = null
  odemeKalan.value = null
  odemeAcik.value = true
}

async function donemOdemeSil(o: ZekatOdemesi): Promise<void> {
  await odemeSil(o.id)
}

async function odemeSil(id: number): Promise<void> {
  try {
    await aileApi.zekatOdemeSil(id)
    if (detay.value) detay.value = await aileApi.zekatYili(detay.value.id)
    await yukle()
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Silinemedi', detail: hataMesaji(e), life: 6000 })
  }
}

async function odemeKaydedildi(): Promise<void> {
  if (detay.value) detay.value = await aileApi.zekatYili(detay.value.id)
  await yukle()
}

async function ayarKaydedildi(yanit: ZekatAyarYaniti): Promise<void> {
  ayar.value = yanit
  sonuc.value = null
}

const gunMetni = computed(() => {
  const g = ayar.value?.zekat_gunu

  return !g
    ? ''
    : g.gun_kaldi < 0
      ? `${-g.gun_kaldi} gün geçti`
      : g.gun_kaldi === 0
        ? 'bugün'
        : `${g.gun_kaldi} gün kaldı`
})
</script>

<template>
  <div class="flex flex-col gap-4">
    <p
      class="rounded-2xl bg-amber-50 p-3 text-xs font-medium text-amber-900"
      data-test="zekat-sabit-uyari"
    >
      {{ ZEKAT_UYARISI }}
    </p>

    <Skeleton v-if="!ayar && !hata" height="8rem" border-radius="1.5rem" />
    <Message v-else-if="!ayar" severity="error" :closable="false">{{ hata }}</Message>
    <template v-else>
      <section class="kart flex flex-wrap items-center justify-between gap-3 p-4 sm:p-5">
        <div>
          <p class="text-xs text-surface-500">
            Zekât günü<template v-if="ayar.zekat_gunu.tahmini"> (tahminî)</template>
          </p>
          <p class="text-lg font-bold text-surface-900" data-test="zekat-gunu">
            {{ tarih(ayar.zekat_gunu.tarih) }}
            <span class="text-sm font-medium text-surface-500">· {{ gunMetni }}</span>
          </p>
          <p class="text-xs text-surface-500">
            {{ ayar.ayar.zekat_gunu_turu === 'hicri' ? 'Hicrî' : 'Miladî' }} takvim ·
            {{
              ayar.ayar.nisab_olcutu === 'gumus'
                ? `gümüş nisabı ${ayar.ayar.nisab_gumus_gram} gr`
                : `altın nisabı ${ayar.ayar.nisab_altin_gram} gr`
            }}
            · %{{ (ayar.ayar.oran_binde / 10).toLocaleString('tr-TR') }}
          </p>
        </div>
        <div class="flex flex-wrap gap-2">
          <Button
            label="Ayarlar"
            icon="pi pi-sliders-h"
            severity="secondary"
            outlined
            @click="ayarAcik = true"
          />
          <Button
            label="Hesapla"
            icon="pi pi-calculator"
            :loading="hesapliyor"
            data-test="zekat-hesapla"
            @click="hesapla"
          />
        </div>
      </section>

      <Message v-if="hata" severity="warn" :closable="false" data-test="zekat-hata">{{
        hata
      }}</Message>

      <template v-if="sonuc">
        <ZekatSonucKarti :sonuc="sonuc" :tahmini="sonuc.tahmini" />
        <div class="flex flex-wrap justify-end gap-2">
          <Button
            label="Anlık görüntüyü kaydet"
            icon="pi pi-save"
            :loading="kaydediliyor"
            data-test="zekat-kaydet"
            @click="kaydet"
          />
        </div>
      </template>

      <section class="flex flex-col gap-2" data-test="zekat-verilen-bolumu">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 class="bolum-etiketi">Verilen zekât (Ramazan'dan Ramazan'a)</h2>
          <Button
            label="Zekât ödemesi ekle"
            icon="pi pi-heart"
            size="small"
            severity="secondary"
            outlined
            data-test="zekat-odemesi-ekle"
            @click="genelOdemeEkle"
          />
        </div>
        <p class="text-xs text-surface-500">
          Zekât bir zekât gününden bir sonrakine kadar (Ramazan'dan Ramazan'a) toplanıp verilir; her
          yılın gereken, verilen ve kalan tutarı burada görünür.
        </p>
        <ZekatDonemleri :donemler="donemler" @ekle="donemdeOdemeEkle" @sil="donemOdemeSil" />
      </section>

      <section class="flex flex-col gap-2">
        <h2 class="bolum-etiketi">Yıl geçmişi</h2>
        <ZekatYilListesi :yillar="yillar" @sec="yilSec" />
      </section>
    </template>

    <ZekatAyarlari
      v-if="ayar"
      v-model:visible="ayarAcik"
      :veri="ayar"
      @kaydedildi="ayarKaydedildi"
    />
    <ZekatOdemeFormu
      v-model:visible="odemeAcik"
      :yil-id="odemeYilId"
      :kalan="odemeKalan"
      @kaydedildi="odemeKaydedildi"
    />

    <UygulamaPenceresi
      v-model:visible="detayAcik"
      :baslik="detay ? `Zekât yılı ${tarih(detay.zekat_gunu)}` : 'Zekât yılı'"
      :genislik="44"
    >
      <div v-if="detay && detaySonucu" class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center gap-2 text-sm">
          <span
            class="rounded-full px-2 py-0.5 text-xs font-semibold"
            :class="
              detay.durum === 'kesinlesti'
                ? 'bg-emerald-100 text-emerald-800'
                : 'bg-amber-100 text-amber-800'
            "
            >{{ detay.durum === 'kesinlesti' ? 'Kesinleşti' : 'Taslak' }}</span
          >
          <span class="text-surface-500">Hesaplandı {{ tarih(detay.hesaplandi_at) }}</span>
        </div>
        <ZekatSonucKarti :sonuc="detaySonucu" />

        <section
          v-if="detay.durum === 'kesinlesti' && detay.nisab_ustu"
          class="kart p-4"
          data-test="zekat-odemeler"
        >
          <div class="mb-2 flex items-baseline justify-between gap-2">
            <h3 class="bolum-etiketi">Verilen zekât</h3>
            <span class="text-sm tabular-nums"
              >Ödenen {{ tl(detay.odenen) }} · kalan
              <strong data-test="zekat-kalan">{{ tl(detay.kalan) }}</strong></span
            >
          </div>
          <ul v-if="detay.odemeler.length" class="divide-y divide-surface-100 text-sm">
            <li v-for="o in detay.odemeler" :key="o.id" class="flex items-center gap-2 py-1.5">
              <span class="min-w-0 flex-1 truncate"
                >{{ tarih(o.tarih) }}<template v-if="o.alici"> · {{ o.alici }}</template></span
              >
              <span class="whitespace-nowrap tabular-nums">{{ tl(o.tutar) }}</span>
              <Button
                icon="pi pi-trash"
                severity="danger"
                text
                rounded
                size="small"
                aria-label="Ödemeyi sil"
                @click="odemeSil(o.id)"
              />
            </li>
          </ul>
          <p v-else class="text-sm text-surface-500">Henüz ödeme girilmedi.</p>
          <Button
            class="mt-3"
            label="Ödeme ekle"
            icon="pi pi-plus"
            size="small"
            @click="odemeEkle"
          />
        </section>

        <div v-if="detay.durum === 'taslak'" class="flex flex-wrap justify-end gap-2">
          <Button label="Taslağı sil" severity="danger" text @click="taslakSil" />
          <Button label="Kesinleştir" icon="pi pi-lock" @click="kesinlestir" />
        </div>
      </div>
    </UygulamaPenceresi>
  </div>
</template>
