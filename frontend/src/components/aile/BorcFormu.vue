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
import { aileApi, type Borc } from '@/api/aile'
import { useFormGonder } from '@/composables/useFormGonder'
import { tarihMetni } from '@/utils/bicim'

/** Borç / alacak ekleme ve düzenleme (kişi, tutar, tarih, vade, not). Bütçe toplamlarına karışmaz. */
const props = defineProps<{ borc?: Borc | null; yon?: 'borc' | 'alacak' }>()
const gorunur = defineModel<boolean>('visible', { default: false })
const emit = defineEmits<{ kaydedildi: [] }>()

const toast = useToast()
const { hatalar, gonderiliyor, gonder } = useFormGonder()

const YONLER = [
  { deger: 'borc', etiket: 'Borcum' },
  { deger: 'alacak', etiket: 'Alacağım' },
]
const secilenYon = ref<'borc' | 'alacak'>('borc')
const kisi = ref('')
const tutar = ref<number | null>(null)
const tarih = ref<Date>(new Date())
const vade = ref<Date | null>(null)
const not = ref('')

watch(gorunur, (acik) => {
  if (!acik) return
  hatalar.value = {}
  const b = props.borc
  secilenYon.value = b?.yon ?? props.yon ?? 'borc'
  kisi.value = b?.kisi ?? ''
  tutar.value = b?.tutar ?? null
  tarih.value = b ? new Date(`${b.tarih}T00:00:00`) : new Date()
  vade.value = b?.vade ? new Date(`${b.vade}T00:00:00`) : null
  not.value = b?.not ?? ''
})

async function kaydet(): Promise<void> {
  if (tutar.value === null || tutar.value <= 0 || kisi.value.trim() === '') return
  const veri = {
    yon: secilenYon.value,
    kisi: kisi.value.trim(),
    tutar: tutar.value,
    tarih: tarihMetni(tarih.value),
    vade: vade.value ? tarihMetni(vade.value) : null,
    not: not.value.trim() || null,
  }
  const sonuc = await gonder(() =>
    props.borc ? aileApi.borcGuncelle(props.borc.id, veri) : aileApi.borcEkle(veri),
  )
  if (sonuc) {
    toast.add({
      severity: 'success',
      summary: secilenYon.value === 'borc' ? 'Borç kaydedildi' : 'Alacak kaydedildi',
      life: 3000,
    })
    gorunur.value = false
    emit('kaydedildi')
  }
}
</script>

<template>
  <UygulamaPenceresi
    v-model:visible="gorunur"
    :baslik="borc ? 'Kaydı düzenle' : 'Borç / alacak ekle'"
    :genislik="28"
  >
    <form class="flex flex-col gap-4" data-test="borc-form" @submit.prevent="kaydet">
      <SelectButton
        v-model="secilenYon"
        :options="YONLER"
        option-label="etiket"
        option-value="deger"
        :allow-empty="false"
        aria-label="Yön"
        class="w-full [&>.p-togglebutton]:flex-1"
        data-test="borc-yon"
      />
      <FormAlani
        :etiket="secilenYon === 'borc' ? 'Kime borçlusunuz' : 'Kim size borçlu'"
        for="borc-kisi"
        :hata="hatalar.kisi"
        zorunlu
      >
        <InputText id="borc-kisi" v-model="kisi" maxlength="80" data-test="borc-kisi" />
      </FormAlani>
      <FormAlani etiket="Tutar (TL)" for="borc-tutar" :hata="hatalar.tutar" zorunlu>
        <InputNumber
          v-model="tutar"
          input-id="borc-tutar"
          locale="tr-TR"
          :min-fraction-digits="0"
          :max-fraction-digits="2"
          :min="0"
          prefix="₺"
          inputmode="decimal"
          fluid
          data-test="borc-tutar"
        />
      </FormAlani>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormAlani etiket="Tarih" for="borc-tarih" :hata="hatalar.tarih" zorunlu>
          <DatePicker
            v-model="tarih"
            input-id="borc-tarih"
            date-format="dd.mm.yy"
            :max-date="new Date()"
            show-icon
            icon-display="input"
            fluid
          />
        </FormAlani>
        <FormAlani etiket="Vade (isteğe bağlı)" for="borc-vade" :hata="hatalar.vade">
          <DatePicker
            v-model="vade"
            input-id="borc-vade"
            date-format="dd.mm.yy"
            :min-date="tarih"
            show-icon
            icon-display="input"
            show-button-bar
            fluid
          />
        </FormAlani>
      </div>
      <FormAlani etiket="Not" for="borc-not" :hata="hatalar.not">
        <InputText id="borc-not" v-model="not" maxlength="500" />
      </FormAlani>
      <p class="rounded-2xl bg-surface-50 p-3 text-xs text-surface-600">
        Borç / alacak defteri Bütçe gelir ve giderine, ev nakdine karışmaz; yalnızca kimden ne kadar
        kaldığını gösterir.
      </p>
      <div class="flex justify-end gap-2">
        <Button label="Vazgeç" severity="secondary" text @click="gorunur = false" />
        <Button
          type="submit"
          label="Kaydet"
          icon="pi pi-check"
          :loading="gonderiliyor"
          :disabled="tutar === null || tutar <= 0 || kisi.trim() === ''"
          data-test="borc-kaydet"
        />
      </div>
    </form>
  </UygulamaPenceresi>
</template>
