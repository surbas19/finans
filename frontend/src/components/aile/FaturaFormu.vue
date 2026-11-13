<script setup lang="ts">
import { ref, watch } from 'vue'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import DatePicker from 'primevue/datepicker'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import Textarea from 'primevue/textarea'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import FormAlani from '@/components/FormAlani.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import { aileApi, type Fatura, type FaturaDurumu, type FaturaGirdisi } from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { useFormGonder } from '@/composables/useFormGonder'
import { useAileStore } from '@/stores/aile'
import { tarihMetni } from '@/utils/bicim'
import { donemdenTarih, tarihtenDonem } from '@/utils/donem'

/**
 * Fatura (elle; geçmiş dönem dahil). Hizmet dönemi faturanın ait olduğu ay; bilinmiyorsa boş. Kartla ödendiyse
 * kart satırına bağlanır (Faturalar sayfasında); kartsız ödenen fatura ödendiği ayın gideri olur.
 */
const props = defineProps<{ fatura?: Fatura | null; varsayilanDonem?: string | null }>()
const gorunur = defineModel<boolean>('visible', { default: false })
const emit = defineEmits<{ kaydedildi: [] }>()

const aile = useAileStore()
const confirm = useConfirm()
const toast = useToast()
const { hatalar, gonderiliyor, gonder } = useFormGonder()
const DURUMLAR = [
  { deger: 'bekliyor', etiket: 'Ödenmedi' },
  { deger: 'odendi', etiket: 'Ödendi' },
]

const form = ref<FaturaGirdisi>({} as FaturaGirdisi)
const donem = ref<Date | null>(null)
const donemBilinmiyor = ref(false)
const sonOdeme = ref<Date | null>(null)
const odeme = ref<Date | null>(null)

watch(gorunur, (acik) => {
  if (!acik) return
  hatalar.value = {}
  const f = props.fatura
  form.value = {
    saglayici_id: f?.saglayici_id ?? null,
    hizmet_donemi: f?.hizmet_donemi ?? props.varsayilanDonem ?? null,
    tutar: f?.tutar ?? null,
    son_odeme_tarihi: f?.son_odeme_tarihi ?? null,
    durum: (f?.durum ?? 'bekliyor') as FaturaDurumu,
    odeme_tarihi: f?.odeme_tarihi ?? null,
    not: f?.not ?? null,
  }
  donemBilinmiyor.value = !!f && !f.hizmet_donemi
  donem.value = form.value.hizmet_donemi ? donemdenTarih(form.value.hizmet_donemi) : null
  sonOdeme.value = form.value.son_odeme_tarihi
    ? new Date(`${form.value.son_odeme_tarihi}T00:00:00`)
    : null
  odeme.value = form.value.odeme_tarihi ? new Date(`${form.value.odeme_tarihi}T00:00:00`) : null
})

async function kaydet(): Promise<void> {
  const veri: FaturaGirdisi = {
    ...form.value,
    hizmet_donemi: donemBilinmiyor.value || !donem.value ? null : tarihtenDonem(donem.value),
    son_odeme_tarihi: sonOdeme.value ? tarihMetni(sonOdeme.value) : null,
    odeme_tarihi: form.value.durum === 'odendi' && odeme.value ? tarihMetni(odeme.value) : null,
  }
  const sonuc = await gonder(
    () => (props.fatura ? aileApi.faturaGuncelle(props.fatura.id, veri) : aileApi.faturaEkle(veri)),
    'Fatura kaydedildi',
  )
  if (sonuc) {
    gorunur.value = false
    emit('kaydedildi')
  }
}

function sil(): void {
  const f = props.fatura
  if (!f) return
  confirm.require({
    header: 'Faturayı sil',
    message: 'Fatura silinsin mi? Bağlı kart satırı giderde kalır.',
    acceptProps: { label: 'Sil', severity: 'danger' },
    rejectProps: { label: 'Vazgeç', severity: 'secondary', text: true },
    accept: async () => {
      try {
        await aileApi.faturaSil(f.id)
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
    :baslik="fatura ? 'Faturayı düzenle' : 'Fatura ekle'"
    :genislik="30"
  >
    <form class="flex flex-col gap-4" @submit.prevent="kaydet">
      <FormAlani etiket="Sağlayıcı" for="f-saglayici" :hata="hatalar.saglayici_id" zorunlu>
        <Select
          id="f-saglayici"
          v-model="form.saglayici_id"
          :options="aile.saglayicilar"
          option-label="ad"
          option-value="id"
          placeholder="Seçin (Ayarlar'dan eklenir)"
        />
      </FormAlani>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormAlani
          etiket="Hizmet dönemi"
          for="f-donem"
          :hata="hatalar.hizmet_donemi"
          ipucu="Faturanın ait olduğu ay"
        >
          <DatePicker
            v-model="donem"
            input-id="f-donem"
            view="month"
            date-format="MM yy"
            :disabled="donemBilinmiyor"
            show-icon
            icon-display="input"
          />
          <label class="flex items-center gap-2 text-xs text-surface-600">
            <Checkbox v-model="donemBilinmiyor" binary /> Dönem bilinmiyor
          </label>
        </FormAlani>
        <FormAlani etiket="Tutar (TL)" for="f-tutar" :hata="hatalar.tutar" zorunlu>
          <InputNumber
            v-model="form.tutar"
            input-id="f-tutar"
            locale="tr-TR"
            :max-fraction-digits="2"
            :min="0"
            prefix="₺"
            inputmode="decimal"
          />
        </FormAlani>
      </div>
      <FormAlani etiket="Son ödeme tarihi" for="f-son" :hata="hatalar.son_odeme_tarihi">
        <DatePicker
          v-model="sonOdeme"
          input-id="f-son"
          date-format="dd.mm.yy"
          show-icon
          icon-display="input"
          show-button-bar
        />
      </FormAlani>
      <SelectButton
        v-model="form.durum"
        :options="DURUMLAR"
        option-label="etiket"
        option-value="deger"
        :allow-empty="false"
        :disabled="!!fatura?.kart_islemi_id"
        aria-label="Ödeme durumu"
        class="w-full sm:w-auto [&>.p-togglebutton]:flex-1"
      />
      <FormAlani
        v-if="form.durum === 'odendi' && !fatura?.kart_islemi_id"
        etiket="Ödeme tarihi"
        for="f-odeme"
        :hata="hatalar.odeme_tarihi"
        ipucu="Kartsız ödendiyse bu ayın gideri sayılır"
        zorunlu
      >
        <DatePicker
          v-model="odeme"
          input-id="f-odeme"
          date-format="dd.mm.yy"
          :max-date="new Date()"
          show-icon
          icon-display="input"
        />
      </FormAlani>
      <p v-if="fatura?.kart_islemi_id" class="text-xs text-surface-500">
        Kart satırına bağlı: ödeme tarihi kart satırından gelir; ödenmedi yapmak için Faturalar'da
        bağı çözün.
      </p>
      <FormAlani etiket="Not" for="f-not" :hata="hatalar.not">
        <Textarea id="f-not" v-model="form.not" rows="2" maxlength="500" auto-resize />
      </FormAlani>
      <div class="flex justify-end gap-2">
        <Button
          v-if="fatura"
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
