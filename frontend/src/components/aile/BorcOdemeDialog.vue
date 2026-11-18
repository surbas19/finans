<script setup lang="ts">
import { ref, watch } from 'vue'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import { useToast } from 'primevue/usetoast'
import FormAlani from '@/components/FormAlani.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import { aileApi, type Borc } from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { useFormGonder } from '@/composables/useFormGonder'
import { tarih as tarihYaz, tarihMetni, tl } from '@/utils/bicim'

/** Borca ödeme / alacağa tahsilat girer, geçmiş ödemeleri listeler (silinebilir). */
const props = defineProps<{ borc: Borc | null }>()
const gorunur = defineModel<boolean>('visible', { default: false })
const emit = defineEmits<{ degisti: [] }>()

const toast = useToast()
const { hatalar, gonderiliyor, gonder } = useFormGonder()
const guncel = ref<Borc | null>(null)
const tutar = ref<number | null>(null)
const tarih = ref<Date>(new Date())
const not = ref('')

watch(gorunur, (acik) => {
  if (!acik) return
  hatalar.value = {}
  guncel.value = props.borc
  tutar.value = props.borc && props.borc.kalan > 0 ? props.borc.kalan : null
  tarih.value = new Date()
  not.value = ''
})

async function ekle(): Promise<void> {
  if (!guncel.value || tutar.value === null || tutar.value <= 0) return
  const sonuc = await gonder(() =>
    aileApi.borcOdemeEkle(guncel.value!.id, {
      tutar: tutar.value,
      tarih: tarihMetni(tarih.value),
      not: not.value.trim() || null,
    }),
  )
  if (sonuc) {
    guncel.value = sonuc.borc
    tutar.value = sonuc.borc.kalan > 0 ? sonuc.borc.kalan : null
    not.value = ''
    toast.add({ severity: 'success', summary: 'Ödeme kaydedildi', life: 2500 })
    emit('degisti')
  }
}

async function sil(odemeId: number): Promise<void> {
  if (!guncel.value) return
  try {
    guncel.value = (await aileApi.borcOdemeSil(guncel.value.id, odemeId)).borc
    tutar.value = guncel.value.kalan > 0 ? guncel.value.kalan : null
    emit('degisti')
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Silinemedi', detail: hataMesaji(e), life: 5000 })
  }
}
</script>

<template>
  <UygulamaPenceresi
    v-model:visible="gorunur"
    :baslik="guncel?.yon === 'alacak' ? 'Tahsilat' : 'Ödeme'"
    :genislik="28"
  >
    <div v-if="guncel" class="flex flex-col gap-4" data-test="borc-odeme">
      <div class="rounded-2xl bg-surface-50 p-3">
        <p class="font-semibold text-surface-900">{{ guncel.kisi }}</p>
        <p class="text-sm text-surface-600">
          Toplam {{ tl(guncel.tutar) }} · {{ guncel.yon === 'alacak' ? 'tahsil edilen' : 'ödenen' }}
          {{ tl(guncel.odenen) }} ·
          <strong data-test="borc-kalan">kalan {{ tl(guncel.kalan) }}</strong>
        </p>
      </div>
      <form
        v-if="guncel.kalan > 0"
        class="flex flex-col gap-3"
        data-test="borc-odeme-form"
        @submit.prevent="ekle"
      >
        <FormAlani
          :etiket="guncel.yon === 'alacak' ? 'Tahsil edilen (TL)' : 'Ödenen (TL)'"
          for="odeme-tutar"
          :hata="hatalar.tutar"
          zorunlu
        >
          <InputNumber
            v-model="tutar"
            input-id="odeme-tutar"
            locale="tr-TR"
            :min-fraction-digits="0"
            :max-fraction-digits="2"
            :min="0"
            :max="guncel.kalan"
            prefix="₺"
            inputmode="decimal"
            fluid
            data-test="borc-odeme-tutar"
          />
        </FormAlani>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <FormAlani etiket="Tarih" for="odeme-tarih" :hata="hatalar.tarih">
            <DatePicker
              v-model="tarih"
              input-id="odeme-tarih"
              date-format="dd.mm.yy"
              :max-date="new Date()"
              show-icon
              icon-display="input"
              fluid
            />
          </FormAlani>
          <FormAlani etiket="Not" for="odeme-not" :hata="hatalar.not">
            <InputText id="odeme-not" v-model="not" maxlength="200" />
          </FormAlani>
        </div>
        <Button
          type="submit"
          :label="guncel.yon === 'alacak' ? 'Tahsilatı kaydet' : 'Ödemeyi kaydet'"
          icon="pi pi-check"
          :loading="gonderiliyor"
          :disabled="tutar === null || tutar <= 0"
          data-test="borc-odeme-kaydet"
        />
      </form>
      <p v-else class="text-sm font-semibold text-green-700">Tamamen kapandı.</p>
      <div v-if="guncel.odemeler.length">
        <p class="bolum-etiketi mb-1">Geçmiş</p>
        <ul class="divide-y divide-surface-100">
          <li v-for="o in guncel.odemeler" :key="o.id" class="flex items-center gap-2 py-2 text-sm">
            <span class="min-w-0 flex-1 truncate"
              >{{ tarihYaz(o.tarih) }}<template v-if="o.not"> · {{ o.not }}</template></span
            >
            <span class="font-semibold tabular-nums">{{ tl(o.tutar) }}</span>
            <Button
              icon="pi pi-trash"
              severity="danger"
              text
              rounded
              size="small"
              aria-label="Ödemeyi sil"
              data-test="borc-odeme-sil"
              @click="sil(o.id)"
            />
          </li>
        </ul>
      </div>
    </div>
  </UygulamaPenceresi>
</template>
