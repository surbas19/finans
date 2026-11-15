<script setup lang="ts">
import { ref, watch } from 'vue'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import ToggleSwitch from 'primevue/toggleswitch'
import FormAlani from '@/components/FormAlani.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import {
  aileApi,
  type ZekatAyarYaniti,
  type ZekatBorcKaynagi,
  type ZekatElleBorcu,
  type ZekatTurKurali,
} from '@/api/aile'
import { useFormGonder } from '@/composables/useFormGonder'
import { tarihMetni, tl } from '@/utils/bicim'

/**
 * Zekât kuralları: nisab ölçütü (ön ayarlar yalnızca alanları doldurur), oran, zekât günü (hicrî / miladî), tür kuralları,
 * borç düşme, hesap bazlı dahil etme, elle borçlar. Varsayılanlar başlangıç değeridir, hüküm değildir.
 */
const props = defineProps<{ veri: ZekatAyarYaniti }>()
const gorunur = defineModel<boolean>('visible', { default: false })
const emit = defineEmits<{ kaydedildi: [yanit: ZekatAyarYaniti] }>()

const HICRI_AYLAR = [
  'Muharrem',
  'Safer',
  'Rebiülevvel',
  'Rebiülahir',
  'Cemaziyelevvel',
  'Cemaziyelahir',
  'Recep',
  'Şaban',
  'Ramazan',
  'Şevval',
  'Zilkade',
  'Zilhicce',
]
const MILADI_AYLAR = [
  'Ocak',
  'Şubat',
  'Mart',
  'Nisan',
  'Mayıs',
  'Haziran',
  'Temmuz',
  'Ağustos',
  'Eylül',
  'Ekim',
  'Kasım',
  'Aralık',
]
const ayliste = (adlar: string[]) => adlar.map((ad, i) => ({ ad, deger: i + 1 }))
const BORC_KAYNAKLARI: { anahtar: ZekatBorcKaynagi; etiket: string }[] = [
  { anahtar: 'kart', etiket: 'Ödenmemiş kredi kartı ekstresi borcu' },
  { anahtar: 'fatura', etiket: 'Vadesi gelmiş ödenmemiş fatura' },
  { anahtar: 'elle', etiket: 'Elle girilen borçlar' },
]

const { hatalar, gonderiliyor, gonder } = useFormGonder()
const form = ref(kopya())
const oranYuzde = ref<number | null>(2.5)
const elleGun = ref<Date | null>(null)
const hesapDurumu = ref<Record<number, boolean>>({})
const borclar = ref<ZekatElleBorcu[]>([])
const yeniBorc = ref<{ ad: string; tutar: number | null }>({ ad: '', tutar: null })
const borcHatasi = ref('')

function kopya() {
  const a = props.veri.ayar

  return {
    ...a,
    tur_kurallari: Object.fromEntries(
      Object.entries(a.tur_kurallari).map(([k, v]) => [k, { ...v }]),
    ) as Record<string, ZekatTurKurali>,
    borc_kaynaklari: { ...a.borc_kaynaklari },
  }
}

watch(gorunur, async (acik) => {
  if (!acik) return
  hatalar.value = {}
  borcHatasi.value = ''
  form.value = kopya()
  oranYuzde.value = props.veri.ayar.oran_binde / 10
  elleGun.value = null
  hesapDurumu.value = Object.fromEntries(props.veri.hesaplar.map((h) => [h.id, h.zekata_dahil]))
  borclar.value = await aileApi.zekatBorclari().catch(() => [])
})

/** Ön ayar: yalnızca ilgili alanları doldurur, kaydetmez */
function onAyarAltin(): void {
  form.value.nisab_olcutu = 'altin'
  form.value.nisab_altin_gram = props.veri.varsayilanlar.nisab_altin_gram
}
function onAyarGumus(): void {
  form.value.nisab_olcutu = 'gumus'
  form.value.nisab_gumus_gram = props.veri.varsayilanlar.nisab_gumus_gram
}

async function kaydet(): Promise<void> {
  const f = form.value
  const sonuc = await gonder(
    () =>
      aileApi.zekatAyarKaydet({
        nisab_olcutu: f.nisab_olcutu,
        nisab_altin_gram: f.nisab_altin_gram,
        nisab_gumus_gram: f.nisab_gumus_gram,
        oran_binde: Math.round((oranYuzde.value ?? 2.5) * 10),
        tur_kurallari: f.tur_kurallari,
        borc_dus: f.borc_dus,
        borc_kaynaklari: f.borc_kaynaklari,
        zekat_gunu_turu: f.zekat_gunu_turu,
        hicri_ay: f.hicri_ay,
        hicri_gun: f.hicri_gun,
        miladi_ay: f.miladi_ay,
        miladi_gun: f.miladi_gun,
        hatirlatma_gun: f.hatirlatma_gun,
        ...(elleGun.value ? { sonraki_zekat_gunu: tarihMetni(elleGun.value) } : {}),
        hesaplar: Object.entries(hesapDurumu.value).map(([id, zekata_dahil]) => ({
          id: Number(id),
          zekata_dahil,
        })),
      }),
    'Zekât ayarları kaydedildi',
  )
  if (sonuc) {
    gorunur.value = false
    emit('kaydedildi', sonuc)
  }
}

async function borcEkle(): Promise<void> {
  borcHatasi.value = ''
  if (!yeniBorc.value.ad.trim() || !yeniBorc.value.tutar || yeniBorc.value.tutar <= 0) {
    borcHatasi.value = 'Borç adı ve sıfırdan büyük tutar gerekir.'

    return
  }
  try {
    const borc = await aileApi.zekatBorcuEkle({
      ad: yeniBorc.value.ad.trim(),
      tutar: yeniBorc.value.tutar,
    })
    borclar.value.push(borc)
    yeniBorc.value = { ad: '', tutar: null }
  } catch {
    borcHatasi.value = 'Borç eklenemedi.'
  }
}

async function borcSil(b: ZekatElleBorcu): Promise<void> {
  await aileApi.zekatBorcuSil(b.id)
  borclar.value = borclar.value.filter((x) => x.id !== b.id)
}
</script>

<template>
  <UygulamaPenceresi v-model:visible="gorunur" baslik="Zekât ayarları" :genislik="40">
    <form class="flex flex-col gap-5" @submit.prevent="kaydet">
      <p class="rounded-2xl bg-surface-50 p-3 text-xs text-surface-600">
        Buradaki değerler başlangıç değeridir, dini hüküm değildir. İsterseniz değiştirin; din
        görevlisine danışın.
      </p>

      <fieldset class="flex flex-col gap-3">
        <legend class="bolum-etiketi mb-1">Nisab</legend>
        <div class="flex flex-wrap gap-2">
          <Button
            type="button"
            label="Altın nisabı (80,18 gr)"
            size="small"
            severity="secondary"
            outlined
            data-test="on-ayar-altin"
            @click="onAyarAltin"
          />
          <Button
            type="button"
            label="Hanefî gümüş nisabı (561 gr)"
            size="small"
            severity="secondary"
            outlined
            data-test="on-ayar-gumus"
            @click="onAyarGumus"
          />
        </div>
        <FormAlani etiket="Nisab ölçütü" for="za-olcut" :hata="hatalar.nisab_olcutu">
          <Select
            id="za-olcut"
            v-model="form.nisab_olcutu"
            :options="[
              { deger: 'altin', etiket: 'Altın' },
              { deger: 'gumus', etiket: 'Gümüş' },
            ]"
            option-label="etiket"
            option-value="deger"
          />
        </FormAlani>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <FormAlani etiket="Altın nisabı (gram)" for="za-altin" :hata="hatalar.nisab_altin_gram">
            <InputNumber
              v-model="form.nisab_altin_gram"
              input-id="za-altin"
              locale="tr-TR"
              :min-fraction-digits="0"
              :max-fraction-digits="3"
              :min="1"
              inputmode="decimal"
            />
          </FormAlani>
          <FormAlani etiket="Gümüş nisabı (gram)" for="za-gumus" :hata="hatalar.nisab_gumus_gram">
            <InputNumber
              v-model="form.nisab_gumus_gram"
              input-id="za-gumus"
              locale="tr-TR"
              :min-fraction-digits="0"
              :max-fraction-digits="3"
              :min="1"
              inputmode="decimal"
            />
          </FormAlani>
        </div>
        <FormAlani
          etiket="Zekât oranı (%)"
          for="za-oran"
          :hata="hatalar.oran_binde"
          ipucu="Varsayılan %2,5 (kırkta bir)."
        >
          <InputNumber
            v-model="oranYuzde"
            input-id="za-oran"
            locale="tr-TR"
            :min-fraction-digits="0"
            :max-fraction-digits="1"
            :min="0.1"
            :max="100"
            suffix="%"
            inputmode="decimal"
          />
        </FormAlani>
        <p class="text-xs text-surface-500">
          Basitleştirilmiş hesap: nisab yalnızca zekât gününde denetlenir; yıl boyunca nisabın
          altına inip inmediğiniz izlenmez.
        </p>
      </fieldset>

      <fieldset class="flex flex-col gap-3">
        <legend class="bolum-etiketi mb-1">Zekât günü</legend>
        <FormAlani etiket="Takvim" for="za-takvim" :hata="hatalar.zekat_gunu_turu">
          <Select
            id="za-takvim"
            v-model="form.zekat_gunu_turu"
            :options="[
              { deger: 'hicri', etiket: 'Hicrî (Ramazan başı gibi)' },
              { deger: 'miladi', etiket: 'Miladî (her yıl aynı gün)' },
            ]"
            option-label="etiket"
            option-value="deger"
          />
        </FormAlani>
        <div v-if="form.zekat_gunu_turu === 'hicri'" class="grid grid-cols-2 gap-3">
          <FormAlani etiket="Hicrî ay" for="za-hay" :hata="hatalar.hicri_ay">
            <Select
              id="za-hay"
              v-model="form.hicri_ay"
              :options="ayliste(HICRI_AYLAR)"
              option-label="ad"
              option-value="deger"
            />
          </FormAlani>
          <FormAlani etiket="Gün" for="za-hgun" :hata="hatalar.hicri_gun">
            <InputNumber
              v-model="form.hicri_gun"
              input-id="za-hgun"
              :min="1"
              :max="30"
              inputmode="numeric"
            />
          </FormAlani>
          <p class="col-span-2 text-xs text-surface-500">
            Hicrî gün tahminîdir (takvim hesabı 1-2 gün sapabilir); gerekirse aşağıdan tarihi elle
            girin.
          </p>
        </div>
        <div v-else class="grid grid-cols-2 gap-3">
          <FormAlani etiket="Ay" for="za-may" :hata="hatalar.miladi_ay">
            <Select
              id="za-may"
              v-model="form.miladi_ay"
              :options="ayliste(MILADI_AYLAR)"
              option-label="ad"
              option-value="deger"
            />
          </FormAlani>
          <FormAlani etiket="Gün" for="za-mgun" :hata="hatalar.miladi_gun">
            <InputNumber
              v-model="form.miladi_gun"
              input-id="za-mgun"
              :min="1"
              :max="31"
              inputmode="numeric"
            />
          </FormAlani>
        </div>
        <FormAlani
          etiket="Sıradaki zekât gününü elle ayarla"
          for="za-elle"
          :hata="hatalar.sonraki_zekat_gunu"
          :ipucu="`Şu an: ${veri.zekat_gunu.tarih}. Boş bırakırsanız takvimden hesaplanır.`"
        >
          <DatePicker
            v-model="elleGun"
            input-id="za-elle"
            date-format="dd.mm.yy"
            show-icon
            icon-display="input"
            show-button-bar
          />
        </FormAlani>
        <FormAlani etiket="Hatırlatma (gün önce)" for="za-hat" :hata="hatalar.hatirlatma_gun">
          <InputNumber
            v-model="form.hatirlatma_gun"
            input-id="za-hat"
            :min="0"
            :max="365"
            inputmode="numeric"
          />
        </FormAlani>
      </fieldset>

      <fieldset class="flex flex-col gap-2">
        <legend class="bolum-etiketi mb-1">Varlık türleri</legend>
        <p class="text-xs text-surface-500">
          Türün zekâta girip girmediği ve değerinin yüzde kaçının sayılacağı. Bireysel emeklilik
          varsayılan olarak tam tutarla sayılır.
        </p>
        <div
          v-for="t in veri.turler"
          :key="t.deger"
          class="flex items-center gap-3 rounded-xl bg-surface-50 px-3 py-2"
        >
          <ToggleSwitch
            v-model="form.tur_kurallari[t.deger]!.dahil"
            :input-id="`za-tur-${t.deger}`"
          />
          <label :for="`za-tur-${t.deger}`" class="min-w-0 flex-1 text-sm">{{ t.etiket }}</label>
          <InputNumber
            v-model="form.tur_kurallari[t.deger]!.yuzde"
            :disabled="!form.tur_kurallari[t.deger]!.dahil"
            :min="0"
            :max="100"
            suffix="%"
            :aria-label="`${t.etiket} kapsam yüzdesi`"
            input-class="w-20 text-right"
            inputmode="numeric"
          />
        </div>
      </fieldset>

      <fieldset class="flex flex-col gap-3">
        <legend class="bolum-etiketi mb-1">Hesaplar</legend>
        <p class="text-xs text-surface-500">
          Eşinizin hesapları dahil hane toplamı hesaplanır; zekâta tabi olmayan hesabı kapatın.
        </p>
        <label
          v-for="h in veri.hesaplar"
          :key="h.id"
          class="flex items-center gap-3 rounded-xl bg-surface-50 px-3 py-2 text-sm"
        >
          <ToggleSwitch v-model="hesapDurumu[h.id]" />
          <span class="min-w-0 flex-1 truncate"
            >{{ h.ad
            }}<span v-if="h.sahip_ad" class="text-xs text-surface-500">
              · {{ h.sahip_ad }}</span
            ></span
          >
        </label>
      </fieldset>

      <fieldset class="flex flex-col gap-3">
        <legend class="bolum-etiketi mb-1">Borç</legend>
        <label class="flex items-center gap-3 text-sm">
          <ToggleSwitch v-model="form.borc_dus" data-test="borc-dus" />
          Borçları zekât matrahından düş (varsayılan kapalı)
        </label>
        <template v-if="form.borc_dus">
          <label
            v-for="k in BORC_KAYNAKLARI"
            :key="k.anahtar"
            class="flex items-center gap-3 pl-2 text-sm"
          >
            <ToggleSwitch v-model="form.borc_kaynaklari[k.anahtar]" />
            {{ k.etiket }}
          </label>
        </template>
        <div class="rounded-2xl bg-surface-50 p-3">
          <p class="mb-2 text-sm font-semibold">Elle borçlar</p>
          <ul v-if="borclar.length" class="mb-2 divide-y divide-surface-100 text-sm">
            <li v-for="b in borclar" :key="b.id" class="flex items-center gap-2 py-1.5">
              <span class="min-w-0 flex-1 truncate">{{ b.ad }}</span>
              <span class="whitespace-nowrap tabular-nums">{{ tl(b.tutar) }}</span>
              <Button
                type="button"
                icon="pi pi-trash"
                severity="danger"
                text
                rounded
                size="small"
                :aria-label="`${b.ad} borcunu sil`"
                @click="borcSil(b)"
              />
            </li>
          </ul>
          <div class="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_9rem_auto]">
            <InputText
              v-model="yeniBorc.ad"
              placeholder="Borç adı"
              maxlength="120"
              aria-label="Borç adı"
            />
            <InputNumber
              v-model="yeniBorc.tutar"
              locale="tr-TR"
              :max-fraction-digits="2"
              :min="0"
              prefix="₺"
              placeholder="Tutar"
              aria-label="Borç tutarı"
              inputmode="decimal"
            />
            <Button type="button" label="Ekle" severity="secondary" outlined @click="borcEkle" />
          </div>
          <small v-if="borcHatasi" class="text-red-600">{{ borcHatasi }}</small>
        </div>
      </fieldset>

      <div class="flex justify-end gap-2">
        <Button type="button" label="Vazgeç" severity="secondary" text @click="gorunur = false" />
        <Button type="submit" label="Kaydet" icon="pi pi-check" :loading="gonderiliyor" />
      </div>
    </form>
  </UygulamaPenceresi>
</template>
