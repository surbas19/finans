<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import Dialog from 'primevue/dialog'
import InputNumber from 'primevue/inputnumber'
import Message from 'primevue/message'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import Textarea from 'primevue/textarea'
import FormAlani from '@/components/FormAlani.vue'
import VarlikEkleDialog from '@/components/VarlikEkleDialog.vue'
import {
  finansApi,
  ISLEM_TURLERI,
  VARLIK_TURLERI,
  type HesapOzeti,
  type Islem,
  type IslemGirdisi,
  type IslemTuru,
  type Varlik,
  type VarlikTuru,
} from '@/api/finans'
import { useFormGonder } from '@/composables/useFormGonder'
import { useKatalogStore } from '@/stores/katalog'
import { tarihMetni, tl } from '@/utils/bicim'

/**
 * Alış / satış / elde olan. Tutarlar TL. Alışta fiyat zorunlu; fiyatını bilmediğiniz eski varlık için "Elde olan"
 * (maliyet bilinmiyor, kâr / zarar hesaplanmaz). Satış o tarihte hesaptakinden fazla olamaz (sunucu denetler).
 */
const props = defineProps<{
  islem?: Islem | null
  varsayilan?: { hesap_id?: number; varlik_id?: number; tur?: IslemTuru }
}>()
const gorunur = defineModel<boolean>('visible', { default: false })
const emit = defineEmits<{ kaydedildi: [] }>()

const katalog = useKatalogStore()
const hesaplar = ref<HesapOzeti[]>([])
const tarihSecimi = ref<Date>(new Date())
const form = ref<IslemGirdisi>(bosForm())
const varlikEkleAcik = ref(false)
const { hatalar, gonderiliyor, gonder } = useFormGonder()

const TURLER = (Object.keys(ISLEM_TURLERI) as IslemTuru[]).map((t) => ({
  deger: t,
  etiket: ISLEM_TURLERI[t],
}))

function bosForm(): IslemGirdisi {
  return {
    hesap_id: null,
    varlik_id: null,
    tur: 'alis',
    miktar: null,
    birim_fiyat: null,
    komisyon: null,
    tarih: tarihMetni(new Date()),
    not: null,
  }
}

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
        tarih: i.tarih,
        not: i.not,
      }
    : { ...bosForm(), ...props.varsayilan }
  tarihSecimi.value = new Date(`${form.value.tarih}T00:00:00`)
  await Promise.all([
    katalog.yukle(),
    finansApi.hesaplar().then((v) => {
      hesaplar.value = v.hesaplar
      if (!form.value.hesap_id && v.hesaplar.length === 1) form.value.hesap_id = v.hesaplar[0]!.id
    }),
  ])
})

const varlikGruplari = computed(() =>
  (Object.keys(VARLIK_TURLERI) as VarlikTuru[])
    .map((tur) => ({
      etiket: VARLIK_TURLERI[tur].etiket,
      ogeler: katalog.varliklar
        .filter((v) => v.tur === tur)
        .map((v) => ({ deger: v.id, etiket: `${v.ad} (${v.kod})` })),
    }))
    .filter((g) => g.ogeler.length > 0),
)

const secilenVarlik = computed<Varlik | undefined>(() =>
  katalog.varliklar.find((v) => v.id === form.value.varlik_id),
)

/** Alışta piyasanın satış, satışta alış fiyatı önerilir (yalnızca TL fiyatlı varlıkta). */
function fiyatOner(): void {
  const v = secilenVarlik.value
  if (
    !v?.fiyat ||
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

async function kaydet(): Promise<void> {
  form.value.tarih = tarihMetni(tarihSecimi.value)
  const sonuc = await gonder(
    () =>
      props.islem
        ? finansApi.islemGuncelle(props.islem.id, form.value)
        : finansApi.islemEkle(form.value),
    props.islem ? 'İşlem güncellendi' : 'İşlem kaydedildi',
  )
  if (sonuc) {
    gorunur.value = false
    emit('kaydedildi')
  }
}
</script>

<template>
  <Dialog
    v-model:visible="gorunur"
    modal
    :header="islem ? 'İşlemi düzenle' : 'İşlem ekle'"
    class="w-[min(34rem,calc(100vw-1rem))]"
  >
    <Message v-if="gorunur && hesaplar.length === 0" severity="warn" class="mb-3">
      Önce bir hesap ekleyin (Hesaplar → Hesap ekle).
    </Message>
    <form class="flex flex-col gap-4" @submit.prevent="kaydet">
      <SelectButton
        v-model="form.tur"
        :options="TURLER"
        option-label="etiket"
        option-value="deger"
        :allow-empty="false"
        aria-label="İşlem türü"
      />
      <p v-if="form.tur === 'mevcut'" class="-mt-2 text-xs text-surface-500">
        Elinizde zaten olan varlık (ör. kasadaki altın). Alış fiyatını bilmiyorsanız boş bırakın; o
        zaman kâr / zarar hesaplanmaz, yalnızca bugünkü değeri görünür.
      </p>

      <div class="grid gap-4 sm:grid-cols-2">
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
          />
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

      <div class="grid gap-4 sm:grid-cols-2">
        <FormAlani
          :etiket="`Miktar${secilenVarlik ? ` (${secilenVarlik.birim})` : ''}`"
          for="islem-miktar"
          :hata="hatalar.miktar"
          zorunlu
        >
          <InputNumber
            v-model="form.miktar"
            input-id="islem-miktar"
            locale="tr-TR"
            :min-fraction-digits="0"
            :max-fraction-digits="8"
            :min="0"
            inputmode="decimal"
          />
        </FormAlani>
        <FormAlani
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

      <div class="grid gap-4 sm:grid-cols-2">
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
        <Button label="Vazgeç" severity="secondary" text @click="gorunur = false" />
        <Button type="submit" label="Kaydet" icon="pi pi-check" :loading="gonderiliyor" />
      </div>
    </form>
    <VarlikEkleDialog v-model:visible="varlikEkleAcik" @eklendi="varlikEklendi" />
  </Dialog>
</template>
