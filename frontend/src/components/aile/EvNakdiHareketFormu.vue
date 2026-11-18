<script setup lang="ts">
import { ref, watch } from 'vue'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import SelectButton from 'primevue/selectbutton'
import { useToast } from 'primevue/usetoast'
import FormAlani from '@/components/FormAlani.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import { aileApi, type EvNakdiOzeti } from '@/api/aile'
import { useFormGonder } from '@/composables/useFormGonder'
import { tarihMetni } from '@/utils/bicim'

/** Ev nakdi kasasına elle para ekleme / çıkarma (ör. bankadan çekilen para, bankaya yatırılan para). */
const props = defineProps<{ tur?: 'ekleme' | 'cikarma' }>()
const gorunur = defineModel<boolean>('visible', { default: false })
const emit = defineEmits<{ kaydedildi: [ozet: EvNakdiOzeti] }>()

const toast = useToast()
const { hatalar, gonderiliyor, gonder } = useFormGonder()

const TURLER = [
  { deger: 'ekleme', etiket: 'Para ekle' },
  { deger: 'cikarma', etiket: 'Para çıkar' },
]
const secilenTur = ref<'ekleme' | 'cikarma'>('ekleme')
const tutar = ref<number | null>(null)
const tarih = ref<Date>(new Date())
const not = ref('')

watch(gorunur, (acik) => {
  if (!acik) return
  hatalar.value = {}
  secilenTur.value = props.tur ?? 'ekleme'
  tutar.value = null
  tarih.value = new Date()
  not.value = ''
})

async function kaydet(): Promise<void> {
  if (tutar.value === null || tutar.value <= 0) return
  const sonuc = await gonder(() =>
    aileApi.evNakdiHareketEkle({
      tur: secilenTur.value,
      tutar: tutar.value,
      tarih: tarihMetni(tarih.value),
      not: not.value.trim() || null,
    }),
  )
  if (sonuc) {
    toast.add({ severity: 'success', summary: 'Ev nakdi güncellendi', life: 3000 })
    gorunur.value = false
    emit('kaydedildi', sonuc)
  }
}
</script>

<template>
  <UygulamaPenceresi v-model:visible="gorunur" baslik="Ev nakdi" :genislik="26">
    <form class="flex flex-col gap-4" data-test="ev-hareket-form" @submit.prevent="kaydet">
      <SelectButton
        v-model="secilenTur"
        :options="TURLER"
        option-label="etiket"
        option-value="deger"
        :allow-empty="false"
        aria-label="Hareket türü"
        class="w-full [&>.p-togglebutton]:flex-1"
        data-test="ev-hareket-tur"
      />
      <FormAlani etiket="Tutar (TL)" for="ev-tutar" :hata="hatalar.tutar" zorunlu>
        <InputNumber
          v-model="tutar"
          input-id="ev-tutar"
          locale="tr-TR"
          :min-fraction-digits="0"
          :max-fraction-digits="2"
          :min="0"
          prefix="₺"
          inputmode="decimal"
          fluid
          data-test="ev-hareket-tutar"
        />
      </FormAlani>
      <FormAlani etiket="Tarih" for="ev-tarih" :hata="hatalar.tarih">
        <DatePicker
          v-model="tarih"
          input-id="ev-tarih"
          date-format="dd.mm.yy"
          :max-date="new Date()"
          show-icon
          icon-display="input"
          fluid
        />
      </FormAlani>
      <FormAlani etiket="Not" for="ev-not" :hata="hatalar.not">
        <InputText
          id="ev-not"
          v-model="not"
          maxlength="200"
          placeholder="Ör. bankadan çekildi"
          data-test="ev-hareket-not"
        />
      </FormAlani>
      <div class="flex justify-end gap-2">
        <Button label="Vazgeç" severity="secondary" text @click="gorunur = false" />
        <Button
          type="submit"
          label="Kaydet"
          icon="pi pi-check"
          :loading="gonderiliyor"
          :disabled="tutar === null || tutar <= 0"
          data-test="ev-hareket-kaydet"
        />
      </div>
    </form>
  </UygulamaPenceresi>
</template>
