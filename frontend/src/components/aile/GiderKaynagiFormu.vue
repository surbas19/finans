<script setup lang="ts">
import { ref, watch } from 'vue'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import ToggleSwitch from 'primevue/toggleswitch'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import FormAlani from '@/components/FormAlani.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import { aileApi, GELIR_SIKLIKLARI, type GelirSikligi, type GiderKaynagi } from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { useFormGonder } from '@/composables/useFormGonder'
import { useAileStore } from '@/stores/aile'
import { tarihMetni } from '@/utils/bicim'

/**
 * Düzenli ödeme (eğitim, bakıcı, aidat…): adı, sıklığı, beklenen tutarı ve günü. Her dönem "beklenen" gider
 * çıkar; ödenince girilen gider o aya yazılır. Kartla ödenenler kart ekstresinden gelir, burada tanımlanmaz.
 */
const props = defineProps<{ kaynak?: GiderKaynagi | null }>()
const gorunur = defineModel<boolean>('visible', { default: false })
const emit = defineEmits<{ kaydedildi: [] }>()

const aile = useAileStore()
const confirm = useConfirm()
const toast = useToast()
const { hatalar, gonderiliyor, gonder } = useFormGonder()

const SIKLIKLAR = (Object.keys(GELIR_SIKLIKLARI) as GelirSikligi[]).map((s) => ({
  deger: s,
  etiket: GELIR_SIKLIKLARI[s],
}))

interface Form {
  uye_id: number | null
  kategori_id: number | null
  ad: string
  siklik: GelirSikligi
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
    uye_id: k?.uye_id ?? null,
    kategori_id: k?.kategori_id ?? null,
    ad: k?.ad ?? '',
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
    baslangic: baslangic.value ? tarihMetni(baslangic.value) : null,
    bitis: bitis.value ? tarihMetni(bitis.value) : null,
  }
  const sonuc = await gonder(
    () =>
      props.kaynak
        ? aileApi.giderKaynagiGuncelle(props.kaynak.id, veri)
        : aileApi.giderKaynagiEkle(veri),
    'Düzenli ödeme kaydedildi',
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
    header: 'Düzenli ödemeyi sil',
    message: `"${k.ad}" silinsin mi? Girilmiş giderler silinmez.`,
    acceptProps: { label: 'Sil', severity: 'danger' },
    rejectProps: { label: 'Vazgeç', severity: 'secondary', text: true },
    accept: async () => {
      try {
        await aileApi.giderKaynagiSil(k.id)
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
    :baslik="kaynak ? 'Düzenli ödemeyi düzenle' : 'Düzenli ödeme tanımla'"
    :genislik="32"
  >
    <form class="flex flex-col gap-4" @submit.prevent="kaydet">
      <FormAlani
        etiket="Ödeme"
        for="gk-ad"
        :hata="hatalar.ad"
        ipucu="ör. okul taksiti, bakıcı, site aidatı"
        zorunlu
      >
        <InputText id="gk-ad" v-model="form.ad" maxlength="80" />
      </FormAlani>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormAlani etiket="Kategori" for="gk-kategori" :hata="hatalar.kategori_id">
          <Select
            id="gk-kategori"
            v-model="form.kategori_id"
            :options="aile.kategoriler"
            option-label="ad"
            option-value="id"
            placeholder="Kategorisiz"
            show-clear
          />
        </FormAlani>
        <FormAlani etiket="Kimin" for="gk-uye" :hata="hatalar.uye_id">
          <Select
            id="gk-uye"
            v-model="form.uye_id"
            :options="aile.aktifUyeler"
            option-label="ad"
            option-value="id"
            placeholder="Ortak"
            show-clear
          />
        </FormAlani>
      </div>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormAlani etiket="Sıklık" for="gk-siklik" :hata="hatalar.siklik" zorunlu>
          <Select
            id="gk-siklik"
            v-model="form.siklik"
            :options="SIKLIKLAR"
            option-label="etiket"
            option-value="deger"
          />
        </FormAlani>
        <FormAlani etiket="Ödeme günü" for="gk-gun" :hata="hatalar.gun" ipucu="Ayın kaçında ödenir">
          <InputNumber
            v-model="form.gun"
            input-id="gk-gun"
            :min="1"
            :max="31"
            :use-grouping="false"
          />
        </FormAlani>
      </div>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormAlani etiket="Başlangıç" for="gk-baslangic" :hata="hatalar.baslangic">
          <DatePicker
            v-model="baslangic"
            input-id="gk-baslangic"
            date-format="dd.mm.yy"
            show-icon
            icon-display="input"
          />
        </FormAlani>
        <FormAlani etiket="Bitiş" for="gk-bitis" :hata="hatalar.bitis" ipucu="Sürüyorsa boş">
          <DatePicker
            v-model="bitis"
            input-id="gk-bitis"
            date-format="dd.mm.yy"
            show-icon
            icon-display="input"
            show-button-bar
          />
        </FormAlani>
      </div>
      <FormAlani
        etiket="Beklenen tutar (TL)"
        for="gk-tutar"
        :hata="hatalar.beklenen_tutar"
        ipucu="Gider girerken önerilir"
      >
        <InputNumber
          v-model="form.beklenen_tutar"
          input-id="gk-tutar"
          locale="tr-TR"
          :max-fraction-digits="2"
          :min="0"
          prefix="₺"
          inputmode="decimal"
        />
      </FormAlani>
      <p class="rounded-2xl bg-surface-50 p-3 text-xs text-surface-600">
        Kartla ödenen giderler kart ekstresinden gelir; aynı ödemeyi burada tekrar girmeyin (iki kez
        sayılır).
      </p>
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
