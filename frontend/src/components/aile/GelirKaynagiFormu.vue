<script setup lang="ts">
import { ref, watch } from 'vue'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import ToggleSwitch from 'primevue/toggleswitch'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import FormAlani from '@/components/FormAlani.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import {
  aileApi,
  GELIR_DUZENLERI,
  GELIR_SIKLIKLARI,
  GELIR_TURLERI,
  type GelirDuzeni,
  type GelirKaynagi,
  type GelirSikligi,
  type GelirTuru,
} from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { useFormGonder } from '@/composables/useFormGonder'
import { useAileStore } from '@/stores/aile'
import { tarihMetni } from '@/utils/bicim'

/**
 * Gelir kaynağı: kimin, hangi tür, düzenliyse sıklığı ve beklenen tutarı. Düzenli kaynaktan her dönem "beklenen"
 * gelir çıkar; alınana kadar gerçekleşen toplama katılmaz.
 */
const props = defineProps<{ kaynak?: GelirKaynagi | null }>()
const gorunur = defineModel<boolean>('visible', { default: false })
const emit = defineEmits<{ kaydedildi: [] }>()

const aile = useAileStore()
const confirm = useConfirm()
const toast = useToast()
const { hatalar, gonderiliyor, gonder } = useFormGonder()

const TURLER = (Object.keys(GELIR_TURLERI) as GelirTuru[]).map((t) => ({
  deger: t,
  etiket: GELIR_TURLERI[t],
}))
const DUZENLER = (Object.keys(GELIR_DUZENLERI) as GelirDuzeni[]).map((d) => ({
  deger: d,
  etiket: GELIR_DUZENLERI[d],
}))
const SIKLIKLAR = (Object.keys(GELIR_SIKLIKLARI) as GelirSikligi[]).map((s) => ({
  deger: s,
  etiket: GELIR_SIKLIKLARI[s],
}))

interface Form {
  uye_id: number | null
  tur: GelirTuru
  ad: string
  duzen: GelirDuzeni
  siklik: GelirSikligi | null
  beklenen_tutar: number | null
  gun: number | null
  aktif: boolean
  not: string | null
}

const form = ref<Form>({} as Form)
const baslangic = ref<Date | null>(null)
const bitis = ref<Date | null>(null)

watch(gorunur, (acik) => {
  if (!acik) return
  hatalar.value = {}
  const k = props.kaynak
  form.value = {
    uye_id: k?.uye_id ?? aile.hane?.ben_uye_id ?? null,
    tur: k?.tur ?? 'maas',
    ad: k?.ad ?? '',
    duzen: k?.duzen ?? 'duzenli',
    siklik: k?.siklik ?? 'aylik',
    beklenen_tutar: k?.beklenen_tutar ?? null,
    gun: k?.gun ?? null,
    aktif: k?.aktif ?? true,
    not: k?.not ?? null,
  }
  baslangic.value = k?.baslangic
    ? new Date(`${k.baslangic}T00:00:00`)
    : new Date(new Date().getFullYear(), new Date().getMonth(), 1)
  bitis.value = k?.bitis ? new Date(`${k.bitis}T00:00:00`) : null
})

async function kaydet(): Promise<void> {
  const veri = {
    ...form.value,
    siklik: form.value.duzen === 'duzenli' ? form.value.siklik : null,
    baslangic: baslangic.value ? tarihMetni(baslangic.value) : null,
    bitis: bitis.value ? tarihMetni(bitis.value) : null,
  }
  const sonuc = await gonder(
    () => (props.kaynak ? aileApi.kaynakGuncelle(props.kaynak.id, veri) : aileApi.kaynakEkle(veri)),
    'Gelir kaynağı kaydedildi',
  )
  if (sonuc) {
    gorunur.value = false
    emit('kaydedildi')
  }
}

function sil(): void {
  const k = props.kaynak
  if (!k) return
  confirm.require({
    header: 'Kaynağı sil',
    message: `"${k.ad}" silinsin mi? Girilmiş gelirler silinmez.`,
    acceptProps: { label: 'Sil', severity: 'danger' },
    rejectProps: { label: 'Vazgeç', severity: 'secondary', text: true },
    accept: async () => {
      try {
        await aileApi.kaynakSil(k.id)
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
    :baslik="kaynak ? 'Gelir kaynağını düzenle' : 'Gelir kaynağı ekle'"
    :genislik="32"
  >
    <form class="flex flex-col gap-4" @submit.prevent="kaydet">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormAlani etiket="Kimin" for="kaynak-uye" :hata="hatalar.uye_id" zorunlu>
          <Select
            id="kaynak-uye"
            v-model="form.uye_id"
            :options="aile.aktifUyeler"
            option-label="ad"
            option-value="id"
            placeholder="Seçin"
          />
        </FormAlani>
        <FormAlani etiket="Tür" for="kaynak-tur" :hata="hatalar.tur" zorunlu>
          <Select
            id="kaynak-tur"
            v-model="form.tur"
            :options="TURLER"
            option-label="etiket"
            option-value="deger"
          />
        </FormAlani>
      </div>
      <FormAlani
        etiket="Kaynak"
        for="kaynak-ad"
        :hata="hatalar.ad"
        ipucu="ör. işveren, kiradaki daire"
        zorunlu
      >
        <InputText id="kaynak-ad" v-model="form.ad" maxlength="80" />
      </FormAlani>
      <SelectButton
        v-model="form.duzen"
        :options="DUZENLER"
        option-label="etiket"
        option-value="deger"
        :allow-empty="false"
        aria-label="Gelir düzeni"
        class="w-full sm:w-auto [&>.p-togglebutton]:flex-1"
      />
      <template v-if="form.duzen === 'duzenli'">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormAlani etiket="Sıklık" for="kaynak-siklik" :hata="hatalar.siklik" zorunlu>
            <Select
              id="kaynak-siklik"
              v-model="form.siklik"
              :options="SIKLIKLAR"
              option-label="etiket"
              option-value="deger"
            />
          </FormAlani>
          <FormAlani
            etiket="Beklenen gün"
            for="kaynak-gun"
            :hata="hatalar.gun"
            ipucu="Ayın kaçında gelir"
          >
            <InputNumber
              v-model="form.gun"
              input-id="kaynak-gun"
              :min="1"
              :max="31"
              :use-grouping="false"
            />
          </FormAlani>
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormAlani etiket="Başlangıç" for="kaynak-baslangic" :hata="hatalar.baslangic">
            <DatePicker
              v-model="baslangic"
              input-id="kaynak-baslangic"
              date-format="dd.mm.yy"
              show-icon
              icon-display="input"
            />
          </FormAlani>
          <FormAlani etiket="Bitiş" for="kaynak-bitis" :hata="hatalar.bitis" ipucu="Sürüyorsa boş">
            <DatePicker
              v-model="bitis"
              input-id="kaynak-bitis"
              date-format="dd.mm.yy"
              show-icon
              icon-display="input"
              show-button-bar
            />
          </FormAlani>
        </div>
      </template>
      <FormAlani
        etiket="Beklenen tutar (TL)"
        for="kaynak-tutar"
        :hata="hatalar.beklenen_tutar"
        ipucu="Gelir girerken önerilir"
      >
        <InputNumber
          v-model="form.beklenen_tutar"
          input-id="kaynak-tutar"
          locale="tr-TR"
          :max-fraction-digits="2"
          :min="0"
          prefix="₺"
          inputmode="decimal"
        />
      </FormAlani>
      <label v-if="kaynak" class="flex items-center gap-2 text-sm text-surface-700">
        <ToggleSwitch v-model="form.aktif" /> Sürüyor (pasifse beklenmez)
      </label>
      <div class="flex justify-end gap-2">
        <Button
          v-if="kaynak"
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
