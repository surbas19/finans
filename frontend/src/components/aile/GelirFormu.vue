<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import Textarea from 'primevue/textarea'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import FormAlani from '@/components/FormAlani.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import GelirTuruSecici from '@/components/aile/GelirTuruSecici.vue'
import {
  aileApi,
  GELIR_DUZENLERI,
  type Gelir,
  type GelirDuzeni,
  type GelirGirdisi,
  type GelirKaynagi,
} from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { useFormGonder } from '@/composables/useFormGonder'
import { useAileStore } from '@/stores/aile'
import { tarihMetni } from '@/utils/bicim'
import { buAy, donemdenTarih, donemEtiketi, tarihtenDonem } from '@/utils/donem'

/**
 * Gerçekleşen gelir. Dönem (ait olduğu ay) ile tarih (elde edildiği gün) ayrıdır: Mart maaşı bugün girilirse Mart'a
 * yazılır. Döviz gelirinde TL karşılığı boş bırakılırsa o günün kuruyla hesaplanır.
 */
const props = defineProps<{
  gelir?: Gelir | null
  varsayilan?: Partial<GelirGirdisi>
  kaynaklar: GelirKaynagi[]
}>()
const gorunur = defineModel<boolean>('visible', { default: false })
const emit = defineEmits<{ kaydedildi: [] }>()

const aile = useAileStore()
const confirm = useConfirm()
const toast = useToast()
const { hatalar, gonderiliyor, gonder } = useFormGonder()

const PARA_BIRIMLERI = ['TRY', 'USD', 'EUR', 'GBP', 'CHF']
const DUZENLER = (Object.keys(GELIR_DUZENLERI) as GelirDuzeni[]).map((d) => ({
  deger: d,
  etiket: GELIR_DUZENLERI[d],
}))

function bos(): GelirGirdisi {
  return {
    uye_id: aile.hane?.ben_uye_id ?? null,
    gelir_kaynagi_id: null,
    gelir_turu_id: aile.gelirTurleri[0]?.id ?? null,
    duzen: 'duzenli',
    aciklama: null,
    tutar: null,
    para_birimi: 'TRY',
    tl_tutar: null,
    tarih: tarihMetni(new Date()),
    donem: buAy(),
    not: null,
  }
}

const form = ref<GelirGirdisi>(bos())
const tarihSecimi = ref<Date>(new Date())
const donemSecimi = ref<Date>(new Date())

watch(gorunur, (acik) => {
  if (!acik) return
  hatalar.value = {}
  const g = props.gelir
  form.value = g
    ? {
        uye_id: g.uye_id,
        gelir_kaynagi_id: g.gelir_kaynagi_id,
        gelir_turu_id: g.gelir_turu_id,
        duzen: g.duzen,
        aciklama: g.aciklama,
        tutar: g.tutar,
        para_birimi: g.para_birimi,
        tl_tutar: g.para_birimi === 'TRY' ? null : g.tl_tutar,
        tarih: g.tarih,
        donem: g.donem,
        not: g.not,
      }
    : { ...bos(), ...props.varsayilan }
  tarihSecimi.value = new Date(`${form.value.tarih}T00:00:00`)
  donemSecimi.value = donemdenTarih(form.value.donem)
})

const uyeKaynaklari = computed(() =>
  props.kaynaklar.filter(
    (k) => k.uye_id === form.value.uye_id && (k.aktif || k.id === form.value.gelir_kaynagi_id),
  ),
)

/** Kaynak seçilince türü ve düzeni ondan gelir */
watch(
  () => form.value.gelir_kaynagi_id,
  (id) => {
    const k = props.kaynaklar.find((x) => x.id === id)
    if (!k) return
    form.value.gelir_turu_id = k.gelir_turu_id
    form.value.duzen = k.duzen
    form.value.para_birimi = k.para_birimi
    if (form.value.tutar === null && k.beklenen_tutar !== null) form.value.tutar = k.beklenen_tutar
  },
)
watch(
  () => form.value.uye_id,
  () => {
    if (!uyeKaynaklari.value.some((k) => k.id === form.value.gelir_kaynagi_id))
      form.value.gelir_kaynagi_id = null
  },
)

async function kaydet(): Promise<void> {
  form.value.tarih = tarihMetni(tarihSecimi.value)
  form.value.donem = tarihtenDonem(donemSecimi.value)
  if (form.value.para_birimi === 'TRY') form.value.tl_tutar = null
  const sonuc = await gonder(() =>
    props.gelir ? aileApi.gelirGuncelle(props.gelir.id, form.value) : aileApi.gelirEkle(form.value),
  )
  if (sonuc) {
    toast.add({
      severity: 'success',
      summary: `${donemEtiketi(form.value.donem)} geliri kaydedildi`,
      life: 3000,
    })
    gorunur.value = false
    emit('kaydedildi')
  }
}

function sil(): void {
  const g = props.gelir
  if (!g) return
  confirm.require({
    header: 'Geliri sil',
    message: 'Bu gelir kaydı silinsin mi?',
    acceptProps: { label: 'Sil', severity: 'danger' },
    rejectProps: { label: 'Vazgeç', severity: 'secondary', text: true },
    accept: async () => {
      try {
        await aileApi.gelirSil(g.id)
        toast.add({ severity: 'success', summary: 'Gelir silindi', life: 3000 })
        gorunur.value = false
        emit('kaydedildi')
      } catch (e) {
        toast.add({ severity: 'error', summary: 'Silinemedi', detail: hataMesaji(e), life: 5000 })
      }
    },
  })
}
</script>

<template>
  <UygulamaPenceresi
    v-model:visible="gorunur"
    :baslik="gelir ? 'Geliri düzenle' : 'Gelir ekle'"
    :genislik="34"
  >
    <form class="flex flex-col gap-4" @submit.prevent="kaydet">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormAlani etiket="Kimin" for="gelir-uye" :hata="hatalar.uye_id" zorunlu>
          <Select
            id="gelir-uye"
            v-model="form.uye_id"
            :options="aile.aktifUyeler"
            option-label="ad"
            option-value="id"
            placeholder="Seçin"
          />
        </FormAlani>
        <FormAlani
          etiket="Kaynak"
          for="gelir-kaynak"
          :hata="hatalar.gelir_kaynagi_id"
          ipucu="Düzenli gelirde kaynağı seçin (aynı ay iki kez girilmez)"
        >
          <Select
            id="gelir-kaynak"
            v-model="form.gelir_kaynagi_id"
            :options="uyeKaynaklari"
            option-label="ad"
            option-value="id"
            placeholder="Kaynaksız"
            show-clear
          />
        </FormAlani>
      </div>

      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormAlani etiket="Tür" for="gelir-tur" :hata="hatalar.gelir_turu_id" zorunlu>
          <GelirTuruSecici v-model="form.gelir_turu_id" input-id="gelir-tur" />
        </FormAlani>
        <FormAlani etiket="Açıklama" for="gelir-aciklama" :hata="hatalar.aciklama">
          <InputText
            id="gelir-aciklama"
            v-model="form.aciklama"
            maxlength="120"
            placeholder="ör. Kasım primi"
          />
        </FormAlani>
      </div>
      <SelectButton
        v-model="form.duzen"
        :options="DUZENLER"
        option-label="etiket"
        option-value="deger"
        :allow-empty="false"
        aria-label="Gelir düzeni"
        class="w-full sm:w-auto [&>.p-togglebutton]:flex-1"
      />

      <div class="grid grid-cols-[1fr_6.5rem] gap-3">
        <FormAlani etiket="Tutar" for="gelir-tutar" :hata="hatalar.tutar" zorunlu>
          <InputNumber
            v-model="form.tutar"
            input-id="gelir-tutar"
            locale="tr-TR"
            :min-fraction-digits="0"
            :max-fraction-digits="2"
            :min="0"
            inputmode="decimal"
          />
        </FormAlani>
        <FormAlani etiket="Para birimi" for="gelir-pb" :hata="hatalar.para_birimi">
          <Select id="gelir-pb" v-model="form.para_birimi" :options="PARA_BIRIMLERI" />
        </FormAlani>
      </div>
      <FormAlani
        v-if="form.para_birimi !== 'TRY'"
        etiket="TL karşılığı"
        for="gelir-tl"
        :hata="hatalar.tl_tutar"
        ipucu="Boş bırakırsanız o günün kuruyla hesaplanır"
      >
        <InputNumber
          v-model="form.tl_tutar"
          input-id="gelir-tl"
          locale="tr-TR"
          :max-fraction-digits="2"
          :min="0"
          prefix="₺"
          inputmode="decimal"
        />
      </FormAlani>

      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormAlani
          etiket="Ait olduğu ay"
          for="gelir-donem"
          :hata="hatalar.donem"
          ipucu="Raporda bu aya yazılır"
          zorunlu
        >
          <DatePicker
            v-model="donemSecimi"
            input-id="gelir-donem"
            view="month"
            date-format="MM yy"
            show-icon
            icon-display="input"
          />
        </FormAlani>
        <FormAlani etiket="Elde edildiği gün" for="gelir-tarih" :hata="hatalar.tarih" zorunlu>
          <DatePicker
            v-model="tarihSecimi"
            input-id="gelir-tarih"
            date-format="dd.mm.yy"
            :max-date="new Date()"
            show-icon
            icon-display="input"
          />
        </FormAlani>
      </div>

      <FormAlani etiket="Not" for="gelir-not" :hata="hatalar.not">
        <Textarea id="gelir-not" v-model="form.not" rows="2" maxlength="500" auto-resize />
      </FormAlani>

      <div class="flex justify-end gap-2">
        <Button
          v-if="gelir"
          label="Sil"
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
