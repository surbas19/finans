<script setup lang="ts">
import { ref, watch } from 'vue'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Textarea from 'primevue/textarea'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import FormAlani from '@/components/FormAlani.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import { aileApi, type DuzenliGider, type DuzenliGiderGirdisi, type GiderKaynagi } from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { useFormGonder } from '@/composables/useFormGonder'
import { useAileStore } from '@/stores/aile'
import { tarihMetni } from '@/utils/bicim'
import { buAy, donemdenTarih, donemEtiketi, tarihtenDonem } from '@/utils/donem'

/**
 * Gerçekleşen kartsız gider (düzenli ödeme ya da tek seferlik). Dönem (ait olduğu ay) ile tarih (ödendiği gün)
 * ayrıdır. Kaynak seçilirse aynı kaynağın aynı ayı bir kez girilir.
 */
const props = defineProps<{
  gider?: DuzenliGider | null
  varsayilan?: Partial<DuzenliGiderGirdisi>
  kaynaklar: GiderKaynagi[]
}>()
const gorunur = defineModel<boolean>('visible', { default: false })
const emit = defineEmits<{ kaydedildi: [] }>()

const aile = useAileStore()
const confirm = useConfirm()
const toast = useToast()
const { hatalar, gonderiliyor, gonder } = useFormGonder()

function bos(): DuzenliGiderGirdisi {
  return {
    gider_kaynagi_id: null,
    kategori_id: null,
    aciklama: '',
    tutar: null,
    tarih: tarihMetni(new Date()),
    donem: buAy(),
    not: null,
  }
}

const form = ref<DuzenliGiderGirdisi>(bos())
const tarihSecimi = ref<Date>(new Date())
const donemSecimi = ref<Date>(new Date())

watch(gorunur, (acik) => {
  if (!acik) return
  hatalar.value = {}
  const g = props.gider
  form.value = g
    ? {
        gider_kaynagi_id: g.gider_kaynagi_id,
        kategori_id: g.kategori_id,
        aciklama: g.aciklama,
        tutar: g.tutar,
        tarih: g.tarih,
        donem: g.donem,
        not: g.not,
      }
    : { ...bos(), ...props.varsayilan }
  tarihSecimi.value = new Date(`${form.value.tarih}T00:00:00`)
  donemSecimi.value = donemdenTarih(form.value.donem)
})

/** Kaynak seçilince ad, kategori ve tutar ondan gelir */
watch(
  () => form.value.gider_kaynagi_id,
  (id) => {
    const k = props.kaynaklar.find((x) => x.id === id)
    if (!k) return
    if (!form.value.aciklama) form.value.aciklama = k.ad
    form.value.kategori_id ??= k.kategori_id
    if (form.value.tutar === null && k.beklenen_tutar !== null) form.value.tutar = k.beklenen_tutar
  },
)

async function kaydet(): Promise<void> {
  form.value.tarih = tarihMetni(tarihSecimi.value)
  form.value.donem = tarihtenDonem(donemSecimi.value)
  const sonuc = await gonder(() =>
    props.gider
      ? aileApi.duzenliGiderGuncelle(props.gider.id, form.value)
      : aileApi.duzenliGiderEkle(form.value),
  )
  if (sonuc) {
    toast.add({
      severity: 'success',
      summary: `${donemEtiketi(form.value.donem)} gideri kaydedildi`,
      life: 3000,
    })
    gorunur.value = false
    emit('kaydedildi')
  }
}

function sil(): void {
  const g = props.gider
  if (!g) return
  confirm.require({
    header: 'Gideri sil',
    message: 'Bu gider kaydı silinsin mi?',
    acceptProps: { label: 'Sil', severity: 'danger' },
    rejectProps: { label: 'Vazgeç', severity: 'secondary', text: true },
    accept: async () => {
      try {
        await aileApi.duzenliGiderSil(g.id)
        toast.add({ severity: 'success', summary: 'Gider silindi', life: 3000 })
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
    :baslik="gider ? 'Gideri düzenle' : 'Gider ekle'"
    :genislik="34"
  >
    <form class="flex flex-col gap-4" @submit.prevent="kaydet">
      <FormAlani
        etiket="Düzenli ödeme"
        for="gider-kaynak"
        :hata="hatalar.gider_kaynagi_id"
        ipucu="Tanımlı bir ödemeyse seçin (aynı ay iki kez girilmez)"
      >
        <Select
          id="gider-kaynak"
          v-model="form.gider_kaynagi_id"
          :options="kaynaklar.filter((k) => k.aktif || k.id === form.gider_kaynagi_id)"
          option-label="ad"
          option-value="id"
          placeholder="Tek seferlik"
          show-clear
        />
      </FormAlani>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormAlani etiket="Açıklama" for="gider-aciklama" :hata="hatalar.aciklama" zorunlu>
          <InputText id="gider-aciklama" v-model="form.aciklama" maxlength="120" />
        </FormAlani>
        <FormAlani etiket="Kategori" for="gider-kategori" :hata="hatalar.kategori_id">
          <Select
            id="gider-kategori"
            v-model="form.kategori_id"
            :options="aile.kategoriler"
            option-label="ad"
            option-value="id"
            placeholder="Kategorisiz"
            show-clear
          />
        </FormAlani>
      </div>
      <FormAlani etiket="Tutar (TL)" for="gider-tutar" :hata="hatalar.tutar" zorunlu>
        <InputNumber
          v-model="form.tutar"
          input-id="gider-tutar"
          locale="tr-TR"
          :min-fraction-digits="0"
          :max-fraction-digits="2"
          :min="0"
          prefix="₺"
          inputmode="decimal"
        />
      </FormAlani>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormAlani
          etiket="Ait olduğu ay"
          for="gider-donem"
          :hata="hatalar.donem"
          ipucu="Raporda bu aya yazılır"
          zorunlu
        >
          <DatePicker
            v-model="donemSecimi"
            input-id="gider-donem"
            view="month"
            date-format="MM yy"
            show-icon
            icon-display="input"
          />
        </FormAlani>
        <FormAlani etiket="Ödendiği gün" for="gider-tarih" :hata="hatalar.tarih" zorunlu>
          <DatePicker
            v-model="tarihSecimi"
            input-id="gider-tarih"
            date-format="dd.mm.yy"
            :max-date="new Date()"
            show-icon
            icon-display="input"
          />
        </FormAlani>
      </div>
      <p class="rounded-2xl bg-surface-50 p-3 text-xs text-surface-600">
        Kartla ödediyseniz bu gider kart ekstresine girer; burada tekrar girmeyin (iki kez sayılır).
      </p>
      <FormAlani etiket="Not" for="gider-not" :hata="hatalar.not">
        <Textarea id="gider-not" v-model="form.not" rows="2" maxlength="500" auto-resize />
      </FormAlani>
      <div class="flex justify-end gap-2">
        <Button
          v-if="gider"
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
