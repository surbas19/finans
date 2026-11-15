<script setup lang="ts">
import { ref, watch } from 'vue'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import FormAlani from '@/components/FormAlani.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import { aileApi, type ZekatOdemeGirdisi } from '@/api/aile'
import { useFormGonder } from '@/composables/useFormGonder'
import { tarihMetni, tl } from '@/utils/bicim'

/**
 * Verilen zekât kaydı. Kesinleşmiş bir zekât yılına bağlanır; tutar > 0, tarih bugünden ileri olamaz.
 * Alıcı serbest ad: IBAN ya da hesap numarası yazılmaz.
 */
const props = defineProps<{ yilId: number | null; kalan?: number | null }>()
const gorunur = defineModel<boolean>('visible', { default: false })
const emit = defineEmits<{ kaydedildi: [] }>()

const { hatalar, gonderiliyor, gonder } = useFormGonder()
const tarihSecimi = ref<Date>(new Date())
const form = ref<ZekatOdemeGirdisi>(bos())

function bos(): ZekatOdemeGirdisi {
  return {
    zekat_yili_id: props.yilId,
    tarih: tarihMetni(new Date()),
    tutar: null,
    alici: null,
    not: null,
  }
}

watch(gorunur, (acik) => {
  if (!acik) return
  hatalar.value = {}
  form.value = { ...bos(), tutar: props.kalan && props.kalan > 0 ? props.kalan : null }
  tarihSecimi.value = new Date()
})

/** İstemci tarafı doğrulama; sunucu da aynı kuralları denetler */
function dogrula(): Record<string, string> {
  const e: Record<string, string> = {}
  if (!form.value.tutar || form.value.tutar <= 0) e.tutar = 'Tutar sıfırdan büyük olmalı.'
  const secilen = tarihMetni(tarihSecimi.value)
  if (secilen > tarihMetni(new Date())) e.tarih = 'İleri tarihli ödeme girilemez.'

  return e
}

async function kaydet(): Promise<void> {
  const e = dogrula()
  if (Object.keys(e).length > 0) {
    hatalar.value = e

    return
  }
  form.value.tarih = tarihMetni(tarihSecimi.value)
  const veri: ZekatOdemeGirdisi = { ...form.value, alici: form.value.alici?.trim() || null }
  const sonuc = await gonder(() => aileApi.zekatOdemeEkle(veri), 'Verilen zekât kaydedildi')
  if (sonuc) {
    gorunur.value = false
    emit('kaydedildi')
  }
}
</script>

<template>
  <UygulamaPenceresi v-model:visible="gorunur" baslik="Verilen zekât" :genislik="30">
    <form class="flex flex-col gap-4" novalidate @submit.prevent="kaydet">
      <p v-if="kalan && kalan > 0" class="text-xs text-surface-500">
        Bu yıl için kalan zekât: <strong>{{ tl(kalan) }}</strong>
      </p>
      <FormAlani etiket="Tutar (TL)" for="zo-tutar" :hata="hatalar.tutar" zorunlu>
        <InputNumber
          v-model="form.tutar"
          input-id="zo-tutar"
          locale="tr-TR"
          :min-fraction-digits="0"
          :max-fraction-digits="2"
          :min="0"
          prefix="₺"
          inputmode="decimal"
        />
      </FormAlani>
      <FormAlani etiket="Tarih" for="zo-tarih" :hata="hatalar.tarih" zorunlu>
        <DatePicker
          v-model="tarihSecimi"
          input-id="zo-tarih"
          date-format="dd.mm.yy"
          :max-date="new Date()"
          show-icon
          icon-display="input"
        />
      </FormAlani>
      <FormAlani
        etiket="Kime verildi"
        for="zo-alici"
        :hata="hatalar.alici"
        ipucu="Serbest ad (kişi, vakıf, dernek). IBAN ya da hesap numarası yazmayın."
      >
        <InputText id="zo-alici" v-model="form.alici" maxlength="120" />
      </FormAlani>
      <FormAlani etiket="Not" for="zo-not" :hata="hatalar.not">
        <InputText id="zo-not" v-model="form.not" maxlength="255" />
      </FormAlani>
      <p class="text-xs text-surface-500">
        Verilen zekât Bütçe'de yalnızca bilgi satırıdır; gider ve kalan hesabını değiştirmez.
      </p>
      <div class="flex justify-end gap-2">
        <Button label="Vazgeç" severity="secondary" text @click="gorunur = false" />
        <Button type="submit" label="Kaydet" icon="pi pi-check" :loading="gonderiliyor" />
      </div>
    </form>
  </UygulamaPenceresi>
</template>
