<script setup lang="ts">
import { ref, watch } from 'vue'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Select from 'primevue/select'
import FormAlani from '@/components/FormAlani.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import { aileApi, KART_ISLEM_TURLERI, type KartIslemi, type KartIslemTuru } from '@/api/aile'
import { useFormGonder } from '@/composables/useFormGonder'
import { useAileStore } from '@/stores/aile'
import { tarihMetni, tl } from '@/utils/bicim'

/**
 * Taslak ekstre satırını düzeltme: PDF satırıyla karşılaştırarak tarih, açıklama, tutar (satırda birden çok tutar
 * varsa adaylardan biri), tür, taksit ve kategori.
 */
const props = defineProps<{ satir: KartIslemi | null; ekstreId: number }>()
const gorunur = defineModel<boolean>('visible', { default: false })
const emit = defineEmits<{ kaydedildi: [satir: KartIslemi] }>()

const aile = useAileStore()
const { hatalar, gonderiliyor, gonder } = useFormGonder()
const TURLER = (Object.keys(KART_ISLEM_TURLERI) as KartIslemTuru[]).map((t) => ({
  deger: t,
  etiket: KART_ISLEM_TURLERI[t].etiket,
}))

const form = ref({
  aciklama: '',
  tutar: null as number | null,
  tur: 'harcama' as KartIslemTuru,
  taksit_no: null as number | null,
  taksit_sayisi: null as number | null,
  kategori_id: null as number | null,
})
const tarihSecimi = ref<Date | null>(null)

watch(gorunur, (acik) => {
  const s = props.satir
  if (!acik || !s) return
  hatalar.value = {}
  form.value = {
    aciklama: s.aciklama,
    tutar: s.tutar,
    tur: s.tur,
    taksit_no: s.taksit_no,
    taksit_sayisi: s.taksit_sayisi,
    kategori_id: s.kategori_id,
  }
  tarihSecimi.value = s.islem_tarihi ? new Date(`${s.islem_tarihi}T00:00:00`) : null
})

async function kaydet(): Promise<void> {
  if (!props.satir) return
  const sonuc = await gonder(() =>
    aileApi.ekstreSatiri(props.ekstreId, props.satir!.id, {
      ...form.value,
      islem_tarihi: tarihSecimi.value ? tarihMetni(tarihSecimi.value) : null,
    }),
  )
  if (sonuc) {
    gorunur.value = false
    emit('kaydedildi', sonuc.islem)
  }
}
</script>

<template>
  <UygulamaPenceresi v-model:visible="gorunur" baslik="Satırı düzelt" :genislik="32">
    <form v-if="satir" class="flex flex-col gap-4" @submit.prevent="kaydet">
      <Message v-if="satir.ham_metin" severity="secondary" size="small">
        PDF<template v-if="satir.sayfa"> (sayfa {{ satir.sayfa }})</template>:
        <span class="font-mono break-all">{{ satir.ham_metin }}</span>
      </Message>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormAlani
          etiket="İşlem tarihi"
          for="es-tarih"
          :hata="hatalar.islem_tarihi"
          ipucu="Harcamanın yapıldığı gün"
        >
          <DatePicker
            v-model="tarihSecimi"
            input-id="es-tarih"
            date-format="dd.mm.yy"
            :max-date="new Date()"
            show-icon
            icon-display="input"
          />
        </FormAlani>
        <FormAlani etiket="Tür" for="es-tur" :hata="hatalar.tur">
          <Select
            id="es-tur"
            v-model="form.tur"
            :options="TURLER"
            option-label="etiket"
            option-value="deger"
          />
        </FormAlani>
      </div>
      <FormAlani etiket="Açıklama" for="es-aciklama" :hata="hatalar.aciklama">
        <InputText id="es-aciklama" v-model="form.aciklama" maxlength="255" />
      </FormAlani>
      <FormAlani etiket="Tutar (TL)" for="es-tutar" :hata="hatalar.tutar">
        <InputNumber
          v-model="form.tutar"
          input-id="es-tutar"
          locale="tr-TR"
          :max-fraction-digits="2"
          :min="0"
          prefix="₺"
          inputmode="decimal"
        />
        <div v-if="satir.okuma?.tutar_adaylari?.length" class="flex flex-wrap gap-1.5">
          <span class="text-xs text-surface-500">Satırdaki tutarlar:</span>
          <button
            v-for="a in satir.okuma.tutar_adaylari"
            :key="a"
            type="button"
            class="rounded-full px-2 py-0.5 text-xs font-semibold ring-1"
            :class="
              Number(a) === form.tutar
                ? 'bg-primary-600 text-white ring-primary-600'
                : 'ring-surface-300'
            "
            @click="form.tutar = Number(a)"
          >
            {{ tl(Number(a)) }}
          </button>
        </div>
      </FormAlani>
      <div class="grid grid-cols-2 gap-4">
        <FormAlani etiket="Taksit" for="es-taksit" :hata="hatalar.taksit_no">
          <div class="flex items-center gap-1">
            <InputNumber
              v-model="form.taksit_no"
              input-id="es-taksit"
              :min="1"
              :max="99"
              class="w-full"
            />
            <span class="text-surface-400">/</span>
            <InputNumber
              v-model="form.taksit_sayisi"
              :min="2"
              :max="99"
              aria-label="Taksit sayısı"
              class="w-full"
            />
          </div>
        </FormAlani>
        <FormAlani etiket="Kategori" for="es-kategori" :hata="hatalar.kategori_id">
          <Select
            id="es-kategori"
            v-model="form.kategori_id"
            :options="aile.kategoriler"
            option-label="ad"
            option-value="id"
            placeholder="Yok"
            show-clear
          />
        </FormAlani>
      </div>
      <div class="flex justify-end gap-2">
        <Button label="Vazgeç" severity="secondary" text @click="gorunur = false" />
        <Button type="submit" label="Kaydet" icon="pi pi-check" :loading="gonderiliyor" />
      </div>
    </form>
  </UygulamaPenceresi>
</template>
