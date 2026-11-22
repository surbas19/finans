<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import { useToast } from 'primevue/usetoast'
import FormAlani from '@/components/FormAlani.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import { finansApi, type TemettuPlani, type TemettuSecenegi } from '@/api/finans'
import { useFormGonder } from '@/composables/useFormGonder'
import { tarihMetni, tl } from '@/utils/bicim'

/**
 * Temettü planla (önceden giriş): hesapta elde olan hisse seçilir, ödeme tarihi ve hisse başı brüt TL girilir; lot,
 * temettü oranı, brüt, stopaj ve yatacak net canlı hesaplanır. Lot ödeme gününde hesaptaki adettir (sunucu hesaplar).
 */
const props = defineProps<{ plan?: TemettuPlani | null }>()
const gorunur = defineModel<boolean>('visible', { default: false })
const emit = defineEmits<{ kaydedildi: [] }>()

const toast = useToast()
const { hatalar, gonderiliyor, gonder } = useFormGonder()
const secenekler = ref<TemettuSecenegi[]>([])
const secim = ref<string | null>(null)
const tarih = ref<Date>(new Date())
const hisseBasi = ref<number | null>(null)
const stopaj = ref<number | null>(10)
const not = ref('')

/** Seçenek anahtarı: "hesap-varlik" */
const anahtar = (s: { hesap_id: number; varlik_id: number }): string =>
  `${s.hesap_id}-${s.varlik_id}`
const liste = computed(() =>
  secenekler.value.map((s) => ({
    ...s,
    anahtar: anahtar(s),
    etiket: `${s.kod} · ${s.lot} lot · ${s.hesap_ad}`,
  })),
)
const secili = computed(() => liste.value.find((s) => s.anahtar === secim.value) ?? null)

watch(gorunur, async (acik) => {
  if (!acik) return
  hatalar.value = {}
  const p = props.plan
  tarih.value = p ? new Date(`${p.tarih}T00:00:00`) : new Date()
  hisseBasi.value = p?.hisse_basi ?? null
  stopaj.value = p?.stopaj_yuzde ?? 10
  not.value = p?.not ?? ''
  secim.value = p ? `${p.hesap_id}-${p.varlik.id}` : null
  try {
    secenekler.value = await finansApi.temettuSecenekleri()
  } catch {
    secenekler.value = []
  }
  if (!p && secenekler.value.length === 1) secim.value = anahtar(secenekler.value[0]!)
})

/** Canlı hesap (yalnız bilgi; kesin değer sunucuda hesaplanır) */
const hesap = computed(() => {
  const s = secili.value
  const h = hisseBasi.value
  if (!s || !h || h <= 0) return null
  const brut = Math.round(s.lot * h * 100) / 100
  const durdur = Math.round(brut * ((stopaj.value ?? 0) / 100) * 100) / 100

  return {
    lot: s.lot,
    brut,
    stopaj: durdur,
    net: Math.round((brut - durdur) * 100) / 100,
    oran: s.fiyat_tl ? Math.round((h / s.fiyat_tl) * 10000) / 100 : null,
  }
})

async function kaydet(): Promise<void> {
  const s = secili.value
  if (!s || hisseBasi.value === null) return
  const veri = {
    tarih: tarihMetni(tarih.value),
    hisse_basi: hisseBasi.value,
    stopaj_yuzde: stopaj.value,
    not: not.value.trim() || null,
  }
  const sonuc = await gonder(() =>
    props.plan
      ? finansApi.temettuGuncelle(props.plan.id, veri)
      : finansApi.temettuPlanla({ ...veri, hesap_id: s.hesap_id, varlik_id: s.varlik_id }),
  )
  if (sonuc) {
    toast.add({ severity: 'success', summary: 'Temettü planlandı', life: 3000 })
    gorunur.value = false
    emit('kaydedildi')
  }
}
</script>

<template>
  <UygulamaPenceresi
    v-model:visible="gorunur"
    :baslik="plan ? 'Temettü planını düzenle' : 'Temettü planla'"
    :genislik="28"
  >
    <form class="flex flex-col gap-4" data-test="temettu-form" @submit.prevent="kaydet">
      <FormAlani
        etiket="Hisse"
        for="tp-hisse"
        :hata="hatalar.varlik_id ?? hatalar.hesap_id"
        zorunlu
      >
        <Select
          v-model="secim"
          input-id="tp-hisse"
          :options="liste"
          option-label="etiket"
          option-value="anahtar"
          placeholder="Hisse seçin"
          :disabled="!!plan"
          data-test="temettu-hisse"
        />
        <small v-if="!liste.length" class="text-surface-500"
          >Hesaplarınızda elde hisse yok; temettü elde hisse varken planlanır.</small
        >
      </FormAlani>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormAlani etiket="Ödeme tarihi" for="tp-tarih" :hata="hatalar.tarih" zorunlu>
          <DatePicker
            v-model="tarih"
            input-id="tp-tarih"
            date-format="dd.mm.yy"
            :min-date="plan ? undefined : new Date()"
            show-icon
            icon-display="input"
            fluid
          />
        </FormAlani>
        <FormAlani etiket="Stopaj %" for="tp-stopaj" :hata="hatalar.stopaj_yuzde">
          <InputNumber
            v-model="stopaj"
            input-id="tp-stopaj"
            locale="tr-TR"
            :min="0"
            :max="100"
            :max-fraction-digits="2"
            fluid
            data-test="temettu-stopaj"
          />
        </FormAlani>
      </div>
      <FormAlani
        etiket="Hisse başı brüt (TL)"
        for="tp-hisse-basi"
        :hata="hatalar.hisse_basi"
        zorunlu
      >
        <InputNumber
          v-model="hisseBasi"
          input-id="tp-hisse-basi"
          locale="tr-TR"
          :min="0"
          :min-fraction-digits="0"
          :max-fraction-digits="6"
          prefix="₺"
          inputmode="decimal"
          fluid
          data-test="temettu-hisse-basi"
        />
      </FormAlani>
      <dl
        v-if="hesap"
        class="grid grid-cols-[1fr_auto] gap-x-3 gap-y-1 rounded-2xl bg-primary-50 p-3 text-sm"
        data-test="temettu-hesap"
      >
        <dt class="text-surface-600">Lot (ödeme gününde)</dt>
        <dd class="font-semibold tabular-nums">{{ hesap.lot }}</dd>
        <template v-if="hesap.oran !== null">
          <dt class="text-surface-600">Temettü oranı</dt>
          <dd class="font-semibold tabular-nums">%{{ hesap.oran.toLocaleString('tr-TR') }}</dd>
        </template>
        <dt class="text-surface-600">Brüt toplam</dt>
        <dd class="font-semibold tabular-nums">{{ tl(hesap.brut) }}</dd>
        <dt class="text-surface-600">Stopaj</dt>
        <dd class="font-semibold tabular-nums">−{{ tl(hesap.stopaj) }}</dd>
        <dt class="col-span-1 border-t border-surface-200 pt-1.5 font-bold">Yatacak (net)</dt>
        <dd
          class="border-t border-surface-200 pt-1.5 font-extrabold text-green-700 tabular-nums"
          data-test="temettu-net-onizleme"
        >
          {{ tl(hesap.net) }}
        </dd>
      </dl>
      <FormAlani etiket="Not" for="tp-not" :hata="hatalar.not">
        <InputText id="tp-not" v-model="not" maxlength="255" placeholder="İsteğe bağlı" />
      </FormAlani>
      <p class="text-xs text-surface-500">
        Plan toplamlara ve zekâta girmez. Yatınca "Yattı" ile gerçek temettü olarak hisse satırına
        ve hesaba Nakit (TL) olarak yazılır.
      </p>
      <div class="flex justify-end gap-2">
        <Button label="Vazgeç" severity="secondary" text @click="gorunur = false" />
        <Button
          type="submit"
          label="Kaydet"
          icon="pi pi-check"
          :loading="gonderiliyor"
          :disabled="!secili || !hisseBasi"
          data-test="temettu-kaydet"
        />
      </div>
    </form>
  </UygulamaPenceresi>
</template>
