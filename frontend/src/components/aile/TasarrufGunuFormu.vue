<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Select from 'primevue/select'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import FormAlani from '@/components/FormAlani.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import VarlikAdi from '@/components/VarlikAdi.vue'
import { aileApi, type TasarrufGunu, type TasarrufGunuGirdisi } from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { finansApi, VARLIK_TURLERI, type HesapOzeti, type VarlikTuru } from '@/api/finans'
import { useFormGonder } from '@/composables/useFormGonder'
import { useKatalogStore } from '@/stores/katalog'
import { tarihMetni } from '@/utils/bicim'

/**
 * Altın / döviz günü: her ay belli bir günde, seçilen portföy hesabına sabit miktarda alım yapılır (örn. her ayın
 * 10'unda 5 gram altın). Gün yalnızca şablondur; ay gelince Bütçe Özet'ten "Bu ayın alımını yap" ile tasarruf
 * olarak kaydedilir, kime verildiği o alımın açıklamasına yazılır.
 */
const props = defineProps<{ gun?: TasarrufGunu | null }>()
const gorunur = defineModel<boolean>('visible', { default: false })
const emit = defineEmits<{ kaydedildi: [] }>()

const katalog = useKatalogStore()
const confirm = useConfirm()
const toast = useToast()
const { hatalar, gonderiliyor, gonder } = useFormGonder()
const hesaplar = ref<HesapOzeti[]>([])
const yukleniyor = ref(false)
const baslangicAyi = ref<Date>(new Date())

function bos(): TasarrufGunuGirdisi {
  return {
    ad: '',
    hesap_id: null,
    varlik_id: null,
    miktar: null,
    ay_gunu: 10,
    baslangic: '',
    ay_sayisi: null,
  }
}
const form = ref<TasarrufGunuGirdisi>(bos())

watch(gorunur, async (acik) => {
  if (!acik) return
  hatalar.value = {}
  const g = props.gun
  form.value = g
    ? {
        ad: g.ad,
        hesap_id: g.hesap_id,
        varlik_id: g.varlik_id,
        miktar: Number(g.miktar),
        ay_gunu: g.ay_gunu,
        baslangic: g.baslangic,
        ay_sayisi: g.ay_sayisi,
      }
    : bos()
  baslangicAyi.value = g ? new Date(`${g.baslangic}T00:00:00`) : new Date()
  yukleniyor.value = true
  try {
    await Promise.all([
      katalog.yukle(),
      finansApi.hesaplar().then((v) => {
        hesaplar.value = v.hesaplar
        if (!g && v.hesaplar.length === 1) form.value.hesap_id = v.hesaplar[0]!.id
      }),
    ])
  } finally {
    yukleniyor.value = false
  }
})

/** Bireysel emeklilik gün olarak tanımlanmaz */
const varlikGruplari = computed(() =>
  (Object.keys(VARLIK_TURLERI) as VarlikTuru[])
    .filter((tur) => tur !== 'bes')
    .map((tur) => ({
      etiket: VARLIK_TURLERI[tur].etiket,
      ogeler: katalog.varliklar
        .filter((v) => v.tur === tur)
        .map((v) => ({ deger: v.id, etiket: `${v.kod} ${v.ad}`, kod: v.kod, ad: v.ad })),
    }))
    .filter((g) => g.ogeler.length > 0),
)
const secilenVarlik = computed(() => katalog.varliklar.find((v) => v.id === form.value.varlik_id))

async function kaydet(): Promise<void> {
  const ay = baslangicAyi.value
  const veri: TasarrufGunuGirdisi = {
    ...form.value,
    baslangic: tarihMetni(new Date(ay.getFullYear(), ay.getMonth(), 1)),
  }
  const g = props.gun
  const sonuc = await gonder(
    () => (g ? aileApi.gunGuncelle(g.id, veri) : aileApi.gunEkle(veri)),
    g ? 'Gün kaydedildi' : 'Gün eklendi',
  )
  if (sonuc) {
    gorunur.value = false
    emit('kaydedildi')
  }
}

function sil(): void {
  const g = props.gun
  if (!g) return
  confirm.require({
    header: 'Günü sil',
    message: `"${g.ad}" günü silinsin mi? Yapılmış alımlar tasarruf olarak kalır.`,
    acceptLabel: 'Sil',
    rejectLabel: 'Vazgeç',
    acceptProps: { severity: 'danger' },
    accept: async () => {
      try {
        await aileApi.gunSil(g.id)
        toast.add({ severity: 'success', summary: 'Gün silindi', life: 3000 })
        gorunur.value = false
        emit('kaydedildi')
      } catch (e) {
        toast.add({ severity: 'error', summary: hataMesaji(e), life: 5000 })
      }
    },
  })
}
</script>

<template>
  <UygulamaPenceresi
    v-model:visible="gorunur"
    :baslik="gun ? 'Günü düzenle' : 'Altın / döviz günü ekle'"
    :genislik="34"
  >
    <Message v-if="gorunur && !yukleniyor && hesaplar.length === 0" severity="warn" class="mb-3">
      Önce bir portföy hesabı ekleyin (Portföy → Hesaplar → Hesap ekle).
    </Message>
    <form class="flex flex-col gap-4" @submit.prevent="kaydet">
      <p class="text-xs text-surface-500">
        Her ay belli günde aynı miktarda alım yapılır. Ay gelince Bütçe Özet'te hatırlatılır; alımı
        onaylayınca tasarruf olarak kaydedilir. Kime verildiğini alımın açıklamasına yazarsınız.
      </p>
      <FormAlani etiket="Günün adı" for="gun-ad" :hata="hatalar.ad" zorunlu>
        <InputText
          id="gun-ad"
          v-model="form.ad"
          maxlength="80"
          placeholder="Örn. Mahalle altın günü"
        />
      </FormAlani>
      <FormAlani etiket="Hangi hesaba" for="gun-hesap" :hata="hatalar.hesap_id" zorunlu>
        <Select
          id="gun-hesap"
          v-model="form.hesap_id"
          :options="hesaplar"
          option-label="ad"
          option-value="id"
          placeholder="Seçin"
        />
      </FormAlani>
      <FormAlani etiket="Ne alınıyor" for="gun-varlik" :hata="hatalar.varlik_id" zorunlu>
        <Select
          id="gun-varlik"
          v-model="form.varlik_id"
          :options="varlikGruplari"
          option-group-label="etiket"
          option-group-children="ogeler"
          option-label="etiket"
          option-value="deger"
          filter
          filter-placeholder="Ara: gram, USD…"
          placeholder="Seçin"
        >
          <template #value="{ placeholder }">
            <VarlikAdi v-if="secilenVarlik" :kod="secilenVarlik.kod" :ad="secilenVarlik.ad" />
            <span v-else>{{ placeholder }}</span>
          </template>
          <template #option="{ option }">
            <VarlikAdi :kod="option.kod" :ad="option.ad" />
          </template>
        </Select>
      </FormAlani>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormAlani
          :etiket="`Her ay miktar${secilenVarlik ? ` (${secilenVarlik.birim})` : ''}`"
          for="gun-miktar"
          :hata="hatalar.miktar"
          zorunlu
        >
          <InputNumber
            v-model="form.miktar"
            input-id="gun-miktar"
            locale="tr-TR"
            :min-fraction-digits="0"
            :max-fraction-digits="8"
            :min="0"
            inputmode="decimal"
            fluid
          />
        </FormAlani>
        <FormAlani etiket="Ayın kaçı" for="gun-gun" :hata="hatalar.ay_gunu" zorunlu>
          <InputNumber
            v-model="form.ay_gunu"
            input-id="gun-gun"
            :min="1"
            :max="28"
            :use-grouping="false"
            inputmode="numeric"
            fluid
          />
        </FormAlani>
        <FormAlani etiket="İlk alım ayı" for="gun-baslangic" :hata="hatalar.baslangic" zorunlu>
          <DatePicker
            v-model="baslangicAyi"
            input-id="gun-baslangic"
            view="month"
            date-format="MM yy"
            show-icon
            icon-display="input"
          />
        </FormAlani>
        <FormAlani etiket="Kaç ay (boşsa süresiz)" for="gun-ay" :hata="hatalar.ay_sayisi">
          <InputNumber
            v-model="form.ay_sayisi"
            input-id="gun-ay"
            :min="1"
            :max="240"
            :use-grouping="false"
            inputmode="numeric"
            fluid
          />
        </FormAlani>
      </div>
      <div class="flex items-center justify-between gap-2">
        <Button
          v-if="gun"
          type="button"
          label="Sil"
          icon="pi pi-trash"
          severity="danger"
          text
          @click="sil"
        />
        <span v-else />
        <span class="flex gap-2">
          <Button label="Vazgeç" severity="secondary" text @click="gorunur = false" />
          <Button
            type="submit"
            :label="gun ? 'Kaydet' : 'Günü ekle'"
            icon="pi pi-check"
            :loading="gonderiliyor"
            :disabled="hesaplar.length === 0"
            data-test="gun-kaydet"
          />
        </span>
      </div>
    </form>
  </UygulamaPenceresi>
</template>
