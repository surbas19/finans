<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import InputNumber from 'primevue/inputnumber'
import Message from 'primevue/message'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import Textarea from 'primevue/textarea'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import FormAlani from '@/components/FormAlani.vue'
import VarlikAdi from '@/components/VarlikAdi.vue'
import VarlikEkleDialog from '@/components/VarlikEkleDialog.vue'
import {
  finansApi,
  islemEtiketi,
  islemTurleri,
  sabitFiyatli,
  VARLIK_TURLERI,
  type HesapOzeti,
  type Islem,
  type IslemGirdisi,
  type IslemTuru,
  type Varlik,
  type VarlikTuru,
} from '@/api/finans'
import { useFormGonder } from '@/composables/useFormGonder'
import { useIslemSil } from '@/composables/useIslemSil'
import { useKatalogStore } from '@/stores/katalog'
import { isaretliTl, tarihMetni, tl } from '@/utils/bicim'

/**
 * Alış / satış / elde olan. Tutarlar TL. Alışta fiyat zorunlu; fiyatını bilmediğiniz eski varlık için "Elde olan"
 * (maliyet bilinmiyor, kâr / zarar hesaplanmaz). Satış o tarihte hesaptakinden fazla olamaz (sunucu denetler).
 * Nakitte yalnızca tutar (yatırma / çekme / elde olan). Bireysel emeklilikte toplam tutar ve kâr yüzdesi
 * ("Güncel tutar"; her giriş öncekinin yerine geçer).
 */
const props = defineProps<{
  islem?: Islem | null
  /** `varlik_tur`: katalog yüklenince o türün ilk varlığı seçilir (ör. "Nakit" kısayolu) */
  varsayilan?: { hesap_id?: number; varlik_id?: number; varlik_tur?: VarlikTuru; tur?: IslemTuru }
}>()
const gorunur = defineModel<boolean>('visible', { default: false })
const emit = defineEmits<{ kaydedildi: [] }>()

const katalog = useKatalogStore()
const hesaplar = ref<HesapOzeti[]>([])
const tarihSecimi = ref<Date>(new Date())
const form = ref<IslemGirdisi>(bosForm())
const varlikEkleAcik = ref(false)
const { hatalar, gonderiliyor, gonder } = useFormGonder()

function bosForm(): IslemGirdisi {
  return {
    hesap_id: null,
    varlik_id: null,
    tur: 'alis',
    miktar: null,
    birim_fiyat: null,
    komisyon: null,
    kar_yuzde: null,
    tarih: tarihMetni(new Date()),
    not: null,
  }
}

/** Hesaplar gelene kadar "önce hesap ekleyin" uyarısı gösterilmez */
const yukleniyor = ref(false)

watch(gorunur, async (acik) => {
  if (!acik) return
  hatalar.value = {}
  const i = props.islem
  form.value = i
    ? {
        hesap_id: i.hesap_id,
        varlik_id: i.varlik_id,
        tur: i.tur,
        miktar: i.miktar,
        birim_fiyat: i.birim_fiyat,
        komisyon: i.komisyon || null,
        kar_yuzde: i.kar_yuzde ?? null,
        tarih: i.tarih,
        not: i.not,
      }
    : {
        ...bosForm(),
        hesap_id: props.varsayilan?.hesap_id ?? null,
        varlik_id: props.varsayilan?.varlik_id ?? null,
        tur: props.varsayilan?.tur ?? 'alis',
      }
  tarihSecimi.value = new Date(`${form.value.tarih}T00:00:00`)
  yukleniyor.value = true
  try {
    await Promise.all([
      katalog.yukle().then(() => {
        const tur = props.varsayilan?.varlik_tur
        if (!props.islem && !form.value.varlik_id && tur) {
          form.value.varlik_id = katalog.varliklar.find((v) => v.tur === tur)?.id ?? null
        }
      }),
      finansApi.hesaplar().then((v) => {
        hesaplar.value = v.hesaplar
        if (!form.value.hesap_id && v.hesaplar.length === 1) form.value.hesap_id = v.hesaplar[0]!.id
      }),
    ])
  } finally {
    yukleniyor.value = false
  }
})

const varlikGruplari = computed(() =>
  (Object.keys(VARLIK_TURLERI) as VarlikTuru[])
    .map((tur) => ({
      etiket: VARLIK_TURLERI[tur].etiket,
      ogeler: katalog.varliklar
        .filter((v) => v.tur === tur)
        // `etiket` aramada kullanılır; görünüm VarlikAdi (kod vurgulu)
        .map((v) => ({ deger: v.id, etiket: `${v.kod} ${v.ad}`, kod: v.kod, ad: v.ad })),
    }))
    .filter((g) => g.ogeler.length > 0),
)

const secilenVarlik = computed<Varlik | undefined>(() =>
  katalog.varliklar.find((v) => v.id === form.value.varlik_id),
)
/** Nakit / bireysel emeklilik: fiyat ve komisyon yok, miktar TL tutarı */
const sabit = computed(() => sabitFiyatli(secilenVarlik.value?.tur))
const bes = computed(() => secilenVarlik.value?.tur === 'bes')

/** Varlığa uyan türler (nakitte Yatırma / Çekme, BES'te yalnızca Güncel tutar) */
const turler = computed(() =>
  islemTurleri(secilenVarlik.value?.tur).map((t) => ({
    deger: t,
    etiket: islemEtiketi(t, secilenVarlik.value?.tur),
  })),
)
watch(
  () => secilenVarlik.value?.tur,
  (tur) => {
    const uygun = islemTurleri(tur)
    if (!uygun.includes(form.value.tur)) form.value.tur = uygun[0]!
  },
)

/** BES: kâr yüzdesinden ödenen tutar ve K/Z (sunucu da aynı oranı saklar) */
const besOzeti = computed(() => {
  const { miktar, kar_yuzde } = form.value
  if (!bes.value || !miktar || kar_yuzde === null || kar_yuzde === undefined || kar_yuzde <= -100)
    return null
  const odenen = miktar / (1 + kar_yuzde / 100)

  return { odenen, kz: miktar - odenen }
})

/** Alışta piyasanın satış, satışta alış fiyatı önerilir (yalnızca TL fiyatlı varlıkta). */
function fiyatOner(): void {
  const v = secilenVarlik.value
  if (
    !v?.fiyat ||
    sabit.value ||
    v.para_birimi !== 'TRY' ||
    form.value.tur === 'mevcut' ||
    form.value.birim_fiyat !== null
  )
    return
  form.value.birim_fiyat =
    form.value.tur === 'alis' ? (v.fiyat.satis ?? v.fiyat.fiyat) : (v.fiyat.alis ?? v.fiyat.fiyat)
}
watch(() => [form.value.varlik_id, form.value.tur], fiyatOner)

const tutar = computed(() => {
  const { miktar, birim_fiyat, komisyon, tur } = form.value
  if (!miktar || !birim_fiyat) return null
  const brut = miktar * birim_fiyat

  return tur === 'satis' ? brut - (komisyon ?? 0) : brut + (komisyon ?? 0)
})

function varlikEklendi(v: Varlik): void {
  form.value.varlik_id = v.id
}

const { sil } = useIslemSil()

/** Silinince pencere kapanır; üst sayfa `kaydedildi` ile listeyi yeniler */
function silVeKapat(islem: Islem): void {
  sil(islem, () => {
    gorunur.value = false
    emit('kaydedildi')
  })
}

async function kaydet(): Promise<void> {
  form.value.tarih = tarihMetni(tarihSecimi.value)
  // Nakit / BES: birim fiyatı sunucu belirler; kâr yüzdesi yalnızca BES'te
  const veri: IslemGirdisi = {
    ...form.value,
    birim_fiyat: sabit.value ? null : form.value.birim_fiyat,
    komisyon: sabit.value ? null : form.value.komisyon,
    kar_yuzde: bes.value ? (form.value.kar_yuzde ?? null) : null,
  }
  const sonuc = await gonder(
    () => (props.islem ? finansApi.islemGuncelle(props.islem.id, veri) : finansApi.islemEkle(veri)),
    props.islem ? 'İşlem güncellendi' : 'İşlem kaydedildi',
  )
  if (sonuc) {
    gorunur.value = false
    emit('kaydedildi')
  }
}
</script>

<template>
  <UygulamaPenceresi
    v-model:visible="gorunur"
    :baslik="islem ? 'İşlemi düzenle' : 'İşlem ekle'"
    :genislik="34"
  >
    <Message v-if="gorunur && !yukleniyor && hesaplar.length === 0" severity="warn" class="mb-3">
      Önce bir hesap ekleyin (Hesaplar → Hesap ekle).
    </Message>
    <form class="flex flex-col gap-4" @submit.prevent="kaydet">
      <SelectButton
        v-if="!bes"
        v-model="form.tur"
        :options="turler"
        option-label="etiket"
        option-value="deger"
        :allow-empty="false"
        aria-label="İşlem türü"
        class="w-full sm:w-auto [&>.p-togglebutton]:flex-1"
      />
      <p v-if="bes" class="text-xs text-surface-500" data-test="bes-aciklama">
        Şirket uygulamasında ya da e-Devlet'te görünen toplam birikimi ve kâr yüzdesini girin. Her
        giriş öncekinin yerine geçer.
      </p>
      <p v-else-if="sabit" class="-mt-2 text-xs text-surface-500">
        Hesaptaki TL nakit. Hisse ya da altın alıp satmak nakde dokunmaz; nakdi buradan
        güncellersiniz.
      </p>
      <p v-else-if="form.tur === 'mevcut'" class="-mt-2 text-xs text-surface-500">
        Elinizde zaten olan varlık (ör. kasadaki altın). Alış fiyatını bilmiyorsanız boş bırakın; o
        zaman kâr / zarar hesaplanmaz, yalnızca bugünkü değeri görünür.
      </p>

      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormAlani etiket="Hesap" for="islem-hesap" :hata="hatalar.hesap_id" zorunlu>
          <Select
            id="islem-hesap"
            v-model="form.hesap_id"
            :options="hesaplar"
            option-label="ad"
            option-value="id"
            placeholder="Seçin"
          />
        </FormAlani>
        <FormAlani etiket="Tarih" for="islem-tarih" :hata="hatalar.tarih" zorunlu>
          <DatePicker
            v-model="tarihSecimi"
            input-id="islem-tarih"
            date-format="dd.mm.yy"
            :max-date="new Date()"
            show-icon
            icon-display="input"
          />
        </FormAlani>
      </div>

      <FormAlani etiket="Varlık" for="islem-varlik" :hata="hatalar.varlik_id" zorunlu>
        <div class="flex gap-2">
          <Select
            id="islem-varlik"
            v-model="form.varlik_id"
            :options="varlikGruplari"
            option-group-label="etiket"
            option-group-children="ogeler"
            option-label="etiket"
            option-value="deger"
            filter
            filter-placeholder="Ara: gram, USD, THYAO…"
            placeholder="Seçin"
            class="min-w-0 flex-1"
          >
            <template #value="{ placeholder }">
              <VarlikAdi v-if="secilenVarlik" :kod="secilenVarlik.kod" :ad="secilenVarlik.ad" />
              <span v-else>{{ placeholder }}</span>
            </template>
            <template #option="{ option }">
              <VarlikAdi :kod="option.kod" :ad="option.ad" />
            </template>
          </Select>
          <Button
            icon="pi pi-plus"
            severity="secondary"
            outlined
            aria-label="Kodla varlık ekle"
            title="Listede yok mu? Kodla ekleyin"
            @click="varlikEkleAcik = true"
          />
        </div>
      </FormAlani>

      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormAlani
          :etiket="
            bes
              ? 'Toplam tutar (TL)'
              : sabit
                ? 'Tutar (TL)'
                : `Miktar${secilenVarlik ? ` (${secilenVarlik.birim})` : ''}`
          "
          for="islem-miktar"
          :hata="hatalar.miktar"
          zorunlu
        >
          <InputNumber
            v-model="form.miktar"
            input-id="islem-miktar"
            locale="tr-TR"
            :min-fraction-digits="0"
            :max-fraction-digits="sabit ? 2 : 8"
            :min="0"
            :prefix="sabit ? '₺' : undefined"
            inputmode="decimal"
          />
        </FormAlani>
        <FormAlani
          v-if="bes"
          etiket="Kâr (%)"
          for="islem-kar"
          :hata="hatalar.kar_yuzde"
          ipucu="Uygulamadaki getiri yüzdesi; bilmiyorsanız boş bırakın"
        >
          <!-- Eksi yazılabilsin diye ondalık klavye değil -->
          <InputNumber
            v-model="form.kar_yuzde"
            input-id="islem-kar"
            locale="tr-TR"
            :min-fraction-digits="0"
            :max-fraction-digits="2"
            :min="-99.99"
            prefix="%"
          />
        </FormAlani>
        <FormAlani
          v-if="!sabit"
          etiket="Birim fiyat (TL)"
          for="islem-fiyat"
          :hata="hatalar.birim_fiyat"
          :zorunlu="form.tur === 'alis'"
          :ipucu="form.tur === 'mevcut' ? 'Bilmiyorsanız boş bırakın' : undefined"
        >
          <InputNumber
            v-model="form.birim_fiyat"
            input-id="islem-fiyat"
            locale="tr-TR"
            :min-fraction-digits="0"
            :max-fraction-digits="6"
            :min="0"
            prefix="₺"
            inputmode="decimal"
          />
        </FormAlani>
      </div>

      <div
        v-if="besOzeti"
        class="flex flex-wrap gap-x-4 rounded-2xl bg-surface-50 px-4 py-2.5 text-sm"
        data-test="bes-ozeti"
      >
        <span class="text-surface-500"
          >Ödenen ≈
          <span class="font-semibold text-surface-900 tabular-nums">{{
            tl(besOzeti.odenen)
          }}</span></span
        >
        <span class="text-surface-500"
          >K/Z
          <span class="font-semibold text-surface-900 tabular-nums">{{
            isaretliTl(besOzeti.kz)
          }}</span></span
        >
      </div>

      <div v-if="!sabit" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormAlani etiket="Komisyon / masraf (TL)" for="islem-komisyon" :hata="hatalar.komisyon">
          <InputNumber
            v-model="form.komisyon"
            input-id="islem-komisyon"
            locale="tr-TR"
            :max-fraction-digits="2"
            :min="0"
            prefix="₺"
            inputmode="decimal"
          />
        </FormAlani>
        <div class="flex flex-col justify-end rounded-2xl bg-surface-50 px-4 py-2.5">
          <span class="text-xs text-surface-500">{{
            form.tur === 'satis' ? 'Ele geçen' : 'Toplam maliyet'
          }}</span>
          <span class="text-lg font-bold text-surface-900 tabular-nums">{{ tl(tutar) }}</span>
        </div>
      </div>

      <FormAlani etiket="Not" for="islem-not" :hata="hatalar.not">
        <Textarea id="islem-not" v-model="form.not" rows="2" maxlength="255" auto-resize />
      </FormAlani>

      <div class="flex justify-end gap-2">
        <!-- Düzenlerken silme (telefonda işlem satırında çöp düğmesi yok) -->
        <Button
          v-if="islem"
          label="Sil"
          icon="pi pi-trash"
          severity="danger"
          text
          class="mr-auto"
          @click="silVeKapat(islem)"
        />
        <Button label="Vazgeç" severity="secondary" text @click="gorunur = false" />
        <Button type="submit" label="Kaydet" icon="pi pi-check" :loading="gonderiliyor" />
      </div>
    </form>
    <VarlikEkleDialog v-model:visible="varlikEkleAcik" @eklendi="varlikEklendi" />
  </UygulamaPenceresi>
</template>
