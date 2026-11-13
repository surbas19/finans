<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Select from 'primevue/select'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import FormAlani from '@/components/FormAlani.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import {
  aileApi,
  KART_ISLEM_TURLERI,
  type KartIslemGirdisi,
  type KartIslemi,
  type KartIslemTuru,
} from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { useFormGonder } from '@/composables/useFormGonder'
import { useAileStore } from '@/stores/aile'
import { tarihMetni } from '@/utils/bicim'

/**
 * Kart satırı: elle ekleme (ekstresi olmayan geçmiş harcama) ve düzeltme (tür, kategori, iade bağı…). Kart borcu
 * ödemesi / transfer / nakit avans gider sayılmaz; iade gideri azaltır.
 */
const props = defineProps<{ islem?: KartIslemi | null; kartId?: number | null }>()
const gorunur = defineModel<boolean>('visible', { default: false })
const emit = defineEmits<{ kaydedildi: [] }>()

const aile = useAileStore()
const confirm = useConfirm()
const toast = useToast()
const { hatalar, gonderiliyor, gonder } = useFormGonder()

const TURLER = (Object.keys(KART_ISLEM_TURLERI) as KartIslemTuru[]).map((t) => ({
  deger: t,
  etiket: KART_ISLEM_TURLERI[t].etiket,
}))

const form = ref<KartIslemGirdisi>({} as KartIslemGirdisi)
const tarihSecimi = ref<Date>(new Date())
const iadeAdaylari = ref<KartIslemi[]>([])

watch(gorunur, (acik) => {
  if (!acik) return
  hatalar.value = {}
  const i = props.islem
  form.value = {
    kart_id: i?.kart_id ?? props.kartId ?? aile.kartlar[0]?.id ?? null,
    islem_tarihi: i?.islem_tarihi ?? tarihMetni(new Date()),
    aciklama: i?.aciklama ?? '',
    tutar: i?.tutar ?? null,
    tur: i?.tur ?? 'harcama',
    taksit_no: i?.taksit_no ?? null,
    taksit_sayisi: i?.taksit_sayisi ?? null,
    taksit_toplam: i?.taksit_toplam ?? null,
    kategori_id: i?.kategori_id ?? null,
    iade_islem_id: i?.iade_islem_id ?? null,
  }
  tarihSecimi.value = new Date(`${form.value.islem_tarihi}T00:00:00`)
})

/** İade bağı için aynı kartın harcamaları (son işlemler) */
watch(
  () => [gorunur.value, form.value.tur, form.value.kart_id] as const,
  async ([acik, tur, kart]) => {
    if (!acik || tur !== 'iade' || !kart) return
    iadeAdaylari.value = (
      await aileApi.kartIslemleri({ kart_id: kart, tur: 'harcama' })
    ).islemler.slice(0, 100)
  },
)
const iadeSecenekleri = computed(() =>
  iadeAdaylari.value.map((i) => ({
    id: i.id,
    etiket: `${i.islem_tarihi ?? ''} · ${i.aciklama} · ₺${i.tutar ?? ''}`,
  })),
)

async function kaydet(): Promise<void> {
  form.value.islem_tarihi = tarihMetni(tarihSecimi.value)
  const sonuc = await gonder(
    () =>
      props.islem
        ? aileApi.kartIslemiGuncelle(props.islem.id, form.value)
        : aileApi.kartIslemiEkle(form.value),
    'İşlem kaydedildi',
  )
  if (sonuc) {
    gorunur.value = false
    emit('kaydedildi')
  }
}

function sil(): void {
  const i = props.islem
  if (!i) return
  confirm.require({
    header: i.ekstre_id ? 'Satırı dışarıda bırak' : 'İşlemi sil',
    message: i.ekstre_id
      ? 'Ekstreden gelen satır silinmez, raporlardan çıkarılır. Bağlı fatura ödenmemiş görünür.'
      : 'Bu işlem silinsin mi? Bağlı fatura ödenmemiş görünür.',
    acceptProps: { label: i.ekstre_id ? 'Dışarıda bırak' : 'Sil', severity: 'danger' },
    rejectProps: { label: 'Vazgeç', severity: 'secondary', text: true },
    accept: async () => {
      try {
        const sonuc = await aileApi.kartIslemiSil(i.id)
        toast.add({ severity: 'success', summary: sonuc.message, life: 3000 })
        gorunur.value = false
        emit('kaydedildi')
      } catch (e) {
        toast.add({ severity: 'error', summary: 'Yapılamadı', detail: hataMesaji(e), life: 5000 })
      }
    },
  })
}
</script>

<template>
  <UygulamaPenceresi
    v-model:visible="gorunur"
    :baslik="islem ? 'Kart işlemini düzenle' : 'Kart işlemi ekle'"
    :genislik="32"
  >
    <form class="flex flex-col gap-4" @submit.prevent="kaydet">
      <Message v-if="islem?.ham_metin" severity="secondary" size="small">
        PDF satırı: <span class="font-mono">{{ islem.ham_metin }}</span>
      </Message>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormAlani etiket="Kart" for="ki-kart" :hata="hatalar.kart_id" zorunlu>
          <Select
            id="ki-kart"
            v-model="form.kart_id"
            :options="aile.kartlar"
            option-label="ad"
            option-value="id"
            :disabled="!!islem?.ekstre_id"
          />
        </FormAlani>
        <FormAlani etiket="İşlem tarihi" for="ki-tarih" :hata="hatalar.islem_tarihi" zorunlu>
          <DatePicker
            v-model="tarihSecimi"
            input-id="ki-tarih"
            date-format="dd.mm.yy"
            :max-date="new Date()"
            show-icon
            icon-display="input"
          />
        </FormAlani>
      </div>
      <FormAlani etiket="Açıklama" for="ki-aciklama" :hata="hatalar.aciklama" zorunlu>
        <InputText id="ki-aciklama" v-model="form.aciklama" maxlength="255" />
      </FormAlani>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormAlani etiket="Tutar (TL)" for="ki-tutar" :hata="hatalar.tutar" zorunlu>
          <InputNumber
            v-model="form.tutar"
            input-id="ki-tutar"
            locale="tr-TR"
            :max-fraction-digits="2"
            :min="0"
            prefix="₺"
            inputmode="decimal"
          />
        </FormAlani>
        <FormAlani etiket="Tür" for="ki-tur" :hata="hatalar.tur" zorunlu>
          <Select
            id="ki-tur"
            v-model="form.tur"
            :options="TURLER"
            option-label="etiket"
            option-value="deger"
          />
        </FormAlani>
      </div>
      <p v-if="KART_ISLEM_TURLERI[form.tur].gider === 0" class="-mt-2 text-xs text-surface-500">
        Bu tür gider toplamına girmez (kart borcu ödemesi ekstredeki harcamaları ikinci kez
        saydırmaz).
      </p>
      <FormAlani
        v-if="form.tur === 'iade'"
        etiket="İade edilen harcama"
        for="ki-iade"
        :hata="hatalar.iade_islem_id"
        ipucu="Bağlanırsa iade o harcamanın kategorisinden düşer"
      >
        <Select
          id="ki-iade"
          v-model="form.iade_islem_id"
          :options="iadeSecenekleri"
          option-label="etiket"
          option-value="id"
          placeholder="Bağlama"
          filter
          show-clear
        />
      </FormAlani>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <FormAlani
          etiket="Kategori"
          for="ki-kategori"
          :hata="hatalar.kategori_id"
          class="sm:col-span-1"
        >
          <Select
            id="ki-kategori"
            v-model="form.kategori_id"
            :options="aile.kategoriler"
            option-label="ad"
            option-value="id"
            placeholder="Otomatik"
            show-clear
          />
        </FormAlani>
        <FormAlani etiket="Taksit" for="ki-taksit" :hata="hatalar.taksit_no">
          <div class="flex items-center gap-1">
            <InputNumber
              v-model="form.taksit_no"
              input-id="ki-taksit"
              :min="1"
              :max="99"
              placeholder="3"
              class="w-full"
            />
            <span class="text-surface-400">/</span>
            <InputNumber
              v-model="form.taksit_sayisi"
              :min="2"
              :max="99"
              placeholder="6"
              aria-label="Taksit sayısı"
              class="w-full"
            />
          </div>
        </FormAlani>
        <FormAlani etiket="Taksitli toplam" for="ki-toplam" :hata="hatalar.taksit_toplam">
          <InputNumber
            v-model="form.taksit_toplam"
            input-id="ki-toplam"
            locale="tr-TR"
            :max-fraction-digits="2"
            :min="0"
            prefix="₺"
          />
        </FormAlani>
      </div>
      <div class="flex justify-end gap-2">
        <Button
          v-if="islem"
          :label="islem.ekstre_id ? 'Dışarıda bırak' : 'Sil'"
          icon="pi pi-trash"
          severity="danger"
          text
          class="mr-auto"
          @click="sil"
        />
        <Button label="Vazgeç" severity="secondary" text @click="gorunur = false" />
        <Button type="submit" label="Kaydet" icon="pi pi-check" :loading="gonderiliyor" />
      </div>
    </form>
  </UygulamaPenceresi>
</template>
