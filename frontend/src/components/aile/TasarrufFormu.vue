<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import InputNumber from 'primevue/inputnumber'
import Message from 'primevue/message'
import Select from 'primevue/select'
import Textarea from 'primevue/textarea'
import FormAlani from '@/components/FormAlani.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import VarlikAdi from '@/components/VarlikAdi.vue'
import { aileApi, type AyGunu, type TasarrufGirdisi } from '@/api/aile'
import {
  finansApi,
  sabitFiyatli,
  VARLIK_TURLERI,
  type HesapOzeti,
  type Varlik,
  type VarlikTuru,
} from '@/api/finans'
import { useFormGonder } from '@/composables/useFormGonder'
import { useAileStore } from '@/stores/aile'
import { useKatalogStore } from '@/stores/katalog'
import { useToast } from 'primevue/usetoast'
import { tarihMetni, tl } from '@/utils/bicim'

/**
 * "Tasarruf yap": bütçeden ayrılan tutarla altın / döviz / hisse / fon / nakit alınır ve seçilen portföy hesabına
 * (ev, banka kasası, yatırım hesabı…) alış işlemi olarak yazılır; o ayın tasarrufu olarak görünür, gider sayılmaz.
 */
const gorunur = defineModel<boolean>('visible', { default: false })
/** Verilirse altın / döviz gününün bu ayki alımı hazır gelir (hesap, varlık, miktar); kime verildiği nota yazılır */
const props = defineProps<{ gun?: AyGunu | null }>()
const emit = defineEmits<{ kaydedildi: [] }>()

const aile = useAileStore()
const toast = useToast()
const katalog = useKatalogStore()
const hesaplar = ref<HesapOzeti[]>([])
const yukleniyor = ref(false)
const tarihSecimi = ref<Date>(new Date())
const { hatalar, gonderiliyor, gonder } = useFormGonder()

function bos(): TasarrufGirdisi {
  return {
    hesap_id: null,
    varlik_id: null,
    uye_id: aile.hane?.ben_uye_id ?? null,
    miktar: null,
    birim_fiyat: null,
    komisyon: null,
    tarih: tarihMetni(new Date()),
    not: null,
  }
}

const form = ref<TasarrufGirdisi>(bos())

watch(gorunur, async (acik) => {
  if (!acik) return
  hatalar.value = {}
  form.value = bos()
  tarihSecimi.value = new Date()
  yukleniyor.value = true
  try {
    await Promise.all([
      katalog.yukle(),
      finansApi.hesaplar().then((v) => {
        hesaplar.value = v.hesaplar
        if (v.hesaplar.length === 1) form.value.hesap_id = v.hesaplar[0]!.id
      }),
    ])
  } finally {
    yukleniyor.value = false
  }
  const g = props.gun
  if (g) {
    form.value.gun_id = g.gun.id
    form.value.hesap_id = g.gun.hesap_id
    form.value.varlik_id = g.gun.varlik_id
    form.value.miktar = Number(g.gun.miktar)
    const hedef = g.vadesi_geldi ? new Date(`${g.tarih}T00:00:00`) : new Date()
    tarihSecimi.value = hedef
    form.value.tarih = tarihMetni(hedef)
  }
})

/** Bireysel emeklilik bu ekrandan eklenmez (toplam tutar + kâr yüzdesiyle Portföy'de girilir) */
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

const secilenVarlik = computed<Varlik | undefined>(() =>
  katalog.varliklar.find((v) => v.id === form.value.varlik_id),
)
const sabit = computed(() => sabitFiyatli(secilenVarlik.value?.tur))

/** Gün alımında "kime verildi" yazılmışsa alım başkasınadır: hiçbir hesaba eklenmez, yalnız tasarruf toplamına girer */
const verilen = computed(() => !!props.gun && !!form.value.not?.trim())

/** Piyasanın satış fiyatı önerilir (yalnızca TL fiyatlı varlıkta) */
watch(
  () => form.value.varlik_id,
  () => {
    const v = secilenVarlik.value
    form.value.birim_fiyat = null
    if (v?.fiyat && !sabit.value && v.para_birimi === 'TRY')
      form.value.birim_fiyat = v.fiyat.satis ?? v.fiyat.fiyat
  },
)

const toplam = computed(() => {
  const { miktar, birim_fiyat, komisyon } = form.value
  if (!miktar) return null
  if (sabit.value) return miktar
  if (!birim_fiyat) return null

  return miktar * birim_fiyat + (komisyon ?? 0)
})

async function kaydet(): Promise<void> {
  form.value.tarih = tarihMetni(tarihSecimi.value)
  const veri: TasarrufGirdisi = {
    ...form.value,
    hesap_id: verilen.value ? null : form.value.hesap_id,
    birim_fiyat: sabit.value ? null : form.value.birim_fiyat,
    komisyon: sabit.value ? null : form.value.komisyon,
  }
  const sonuc = await gonder(() => aileApi.tasarrufEkle(veri), 'Tasarruf kaydedildi')
  if (sonuc) {
    if (sonuc.uyari) toast.add({ severity: 'warn', summary: sonuc.uyari, life: 6000 })
    gorunur.value = false
    emit('kaydedildi')
  }
}
</script>

<template>
  <UygulamaPenceresi
    v-model:visible="gorunur"
    :baslik="gun ? `${gun.gun.ad}: bu ayın alımı` : 'Tasarruf yap'"
    :genislik="34"
  >
    <Message v-if="gorunur && !yukleniyor && hesaplar.length === 0" severity="warn" class="mb-3">
      Önce bir portföy hesabı ekleyin (Portföy → Hesaplar → Hesap ekle): ev, banka kasası, yatırım
      hesabı…
    </Message>
    <form class="flex flex-col gap-4" @submit.prevent="kaydet">
      <p class="text-xs text-surface-500">
        Aldığınız altın, döviz, hisse ya da fon seçtiğiniz hesaba alış olarak yazılır ve bu ayın
        tasarrufu sayılır. Tasarruf gider değildir; kalan paradan düşülür.
      </p>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormAlani
          v-if="!verilen"
          etiket="Hangi hesaba"
          for="tas-hesap"
          :hata="hatalar.hesap_id"
          zorunlu
        >
          <Select
            id="tas-hesap"
            v-model="form.hesap_id"
            :options="hesaplar"
            option-label="ad"
            option-value="id"
            placeholder="Seçin"
          />
        </FormAlani>
        <FormAlani etiket="Tarih" for="tas-tarih" :hata="hatalar.tarih" zorunlu>
          <DatePicker
            v-model="tarihSecimi"
            input-id="tas-tarih"
            date-format="dd.mm.yy"
            :max-date="new Date()"
            show-icon
            icon-display="input"
          />
        </FormAlani>
      </div>

      <FormAlani etiket="Ne alındı" for="tas-varlik" :hata="hatalar.varlik_id" zorunlu>
        <Select
          id="tas-varlik"
          v-model="form.varlik_id"
          :options="varlikGruplari"
          option-group-label="etiket"
          option-group-children="ogeler"
          option-label="etiket"
          option-value="deger"
          filter
          filter-placeholder="Ara: gram, USD, THYAO…"
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
          :etiket="
            sabit ? 'Tutar (TL)' : `Miktar${secilenVarlik ? ` (${secilenVarlik.birim})` : ''}`
          "
          for="tas-miktar"
          :hata="hatalar.miktar"
          zorunlu
        >
          <InputNumber
            v-model="form.miktar"
            input-id="tas-miktar"
            locale="tr-TR"
            :min-fraction-digits="0"
            :max-fraction-digits="sabit ? 2 : 8"
            :min="0"
            :prefix="sabit ? '₺' : undefined"
            inputmode="decimal"
          />
        </FormAlani>
        <FormAlani
          v-if="!sabit"
          etiket="Birim fiyat (TL)"
          for="tas-fiyat"
          :hata="hatalar.birim_fiyat"
          zorunlu
        >
          <InputNumber
            v-model="form.birim_fiyat"
            input-id="tas-fiyat"
            locale="tr-TR"
            :min-fraction-digits="0"
            :max-fraction-digits="4"
            :min="0"
            prefix="₺"
            inputmode="decimal"
          />
        </FormAlani>
      </div>
      <FormAlani v-if="!sabit" etiket="Komisyon (TL)" for="tas-komisyon" :hata="hatalar.komisyon">
        <InputNumber
          v-model="form.komisyon"
          input-id="tas-komisyon"
          locale="tr-TR"
          :max-fraction-digits="2"
          :min="0"
          prefix="₺"
          inputmode="decimal"
        />
      </FormAlani>
      <p
        v-if="verilen"
        class="rounded-2xl bg-amber-50 p-3 text-sm text-amber-900"
        data-test="verilen-uyari"
      >
        Kime verildiği yazıldığı için bu alım <strong>hiçbir hesaba eklenmez</strong>; yalnız aylık
        tasarruf toplamına girer.
      </p>
      <p
        v-if="toplam !== null"
        class="rounded-2xl bg-emerald-50 p-3 text-sm text-emerald-900"
        data-test="tasarruf-toplam"
      >
        Bu ayın tasarrufuna <strong>{{ tl(toplam) }}</strong> yazılır.
      </p>

      <FormAlani
        :etiket="gun ? 'Kime verildi / açıklama' : 'Not'"
        for="tas-not"
        :hata="hatalar.not"
      >
        <Textarea
          id="tas-not"
          v-model="form.not"
          rows="2"
          maxlength="255"
          auto-resize
          :placeholder="gun ? 'Örn. Kasım: Ayşe teyzeye verildi' : undefined"
          data-test="tasarruf-not"
        />
      </FormAlani>
      <div class="flex justify-end gap-2">
        <Button label="Vazgeç" severity="secondary" text @click="gorunur = false" />
        <Button
          type="submit"
          label="Tasarruf et"
          icon="pi pi-check"
          :loading="gonderiliyor"
          :disabled="hesaplar.length === 0 && !verilen"
        />
      </div>
    </form>
  </UygulamaPenceresi>
</template>
