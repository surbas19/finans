<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import { useToast } from 'primevue/usetoast'
import FormAlani from '@/components/FormAlani.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import { aileApi } from '@/api/aile'
import { useFormGonder } from '@/composables/useFormGonder'
import { useAileStore } from '@/stores/aile'
import { tarihMetni, tl } from '@/utils/bicim'
import { tarihtenDonem } from '@/utils/donem'
import { kategoriNoktasi } from '@/utils/kategoriRengi'

/**
 * Nakit harcama (hızlı işlemler): kartsız gideri tek ekranda girer. Tutar, isteğe bağlı açıklama, en çok kullanılan
 * kategori çipleri ve tarih (bugün). Düzenli ödeme / dönem / not alanları yoktur: dönem tarihin ayıdır. Sunucuda
 * `duzenli_giderler` kaydı olur, Bütçe'de o ayın kartsız gideri sayılır. Kartla ödenen harcama ekstreden gelir.
 */
const gorunur = defineModel<boolean>('visible', { default: false })
const emit = defineEmits<{ kaydedildi: [] }>()

const aile = useAileStore()
const toast = useToast()
const { hatalar, gonderiliyor, gonder } = useFormGonder()

const tutar = ref<number | null>(null)
const aciklama = ref('')
const kategoriId = ref<number | null>(null)
const tarih = ref<Date>(new Date())
const digerAcik = ref(false)
/** Paranın çıktığı yer: ev nakdinden düşer; banka yalnız etiket */
const odemeYeri = ref<'ev' | 'banka'>('ev')
const ODEME_YERLERI = [
  { deger: 'ev', etiket: 'Ev' },
  { deger: 'banka', etiket: 'Banka' },
]

/** Çiplerde en çok kullanılan 4; seçili kategori çiplerde yoksa (Diğer'den seçildiyse) başa eklenir */
const cipler = computed(() => {
  const sik = aile.sikKategoriler
  const secili = aile.kategori(kategoriId.value)

  return secili && !sik.some((k) => k.id === secili.id) ? [secili, ...sik.slice(0, 3)] : sik
})

watch(gorunur, async (acik) => {
  if (!acik) return
  hatalar.value = {}
  tutar.value = null
  aciklama.value = ''
  kategoriId.value = null
  tarih.value = new Date()
  digerAcik.value = false
  odemeYeri.value = 'ev'
  await nextTick()
  tutaraOdaklan()
})

/**
 * Pencere açılış animasyonuyla çizilir ve kendi odak tuzağı ilk düğmeye odaklanır; tutar alanı bulununca kısa bir
 * süre sonra (tuzaktan sonra) odaklanır, böylece telefonda klavye doğrudan tutar için açılır.
 */
function tutaraOdaklan(deneme = 0): void {
  const alan = document.getElementById('nh-tutar')
  if (alan) {
    setTimeout(() => alan.focus(), 350)

    return
  }
  if (deneme < 20) setTimeout(() => tutaraOdaklan(deneme + 1), 50)
}

function kategoriSec(id: number): void {
  kategoriId.value = kategoriId.value === id ? null : id
}

async function kaydet(): Promise<void> {
  if (tutar.value === null || tutar.value <= 0) return
  // Açıklama zorunlu: boşsa kategori adı, o da yoksa "Nakit harcama"
  const ad = aciklama.value.trim() || aile.kategori(kategoriId.value)?.ad || 'Nakit harcama'
  const sonuc = await gonder(() =>
    aileApi.duzenliGiderEkle({
      gider_kaynagi_id: null,
      kategori_id: kategoriId.value,
      aciklama: ad,
      tutar: tutar.value,
      tarih: tarihMetni(tarih.value),
      odeme_yeri: odemeYeri.value,
      donem: tarihtenDonem(tarih.value),
      not: null,
    }),
  )
  if (sonuc) {
    toast.add({
      severity: 'success',
      summary: 'Nakit harcama kaydedildi',
      detail: `${ad} · ${tl(tutar.value)}`,
      life: 3000,
    })
    gorunur.value = false
    emit('kaydedildi')
  }
}
</script>

<template>
  <UygulamaPenceresi v-model:visible="gorunur" baslik="Nakit harcama" :genislik="26">
    <form class="flex flex-col gap-4" data-test="nakit-form" @submit.prevent="kaydet">
      <FormAlani etiket="Tutar" for="nh-tutar" :hata="hatalar.tutar" zorunlu>
        <InputNumber
          v-model="tutar"
          input-id="nh-tutar"
          locale="tr-TR"
          :min-fraction-digits="0"
          :max-fraction-digits="2"
          :min="0"
          prefix="₺"
          inputmode="decimal"
          fluid
          input-class="!text-center !text-3xl !font-extrabold !py-4"
          data-test="nakit-tutar"
        />
      </FormAlani>
      <div>
        <SelectButton
          v-model="odemeYeri"
          :options="ODEME_YERLERI"
          option-label="etiket"
          option-value="deger"
          :allow-empty="false"
          aria-label="Ödeme yeri"
          class="w-full [&>.p-togglebutton]:flex-1"
          data-test="nakit-odeme-yeri"
        />
        <p class="mt-1 text-center text-xs text-surface-500">
          {{
            odemeYeri === 'ev' ? 'Ev nakdinden düşer' : 'Banka (bakiye takibi yok, yalnızca etiket)'
          }}
        </p>
      </div>
      <FormAlani etiket="Açıklama" for="nh-aciklama" :hata="hatalar.aciklama">
        <InputText
          id="nh-aciklama"
          v-model="aciklama"
          maxlength="120"
          placeholder="İsteğe bağlı (boşsa kategori adı)"
          data-test="nakit-aciklama"
        />
      </FormAlani>
      <div>
        <p class="bolum-etiketi mb-1.5">Kategori</p>
        <div class="flex flex-wrap gap-1.5" data-test="nakit-kategoriler">
          <button
            v-for="k in cipler"
            :key="k.id"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-full px-3.5 py-2 text-sm font-semibold ring-1 transition-all active:scale-95"
            :class="
              kategoriId === k.id
                ? 'bg-primary-50 text-primary-800 ring-primary-400'
                : 'bg-surface-0 text-surface-800 ring-surface-200'
            "
            :aria-pressed="kategoriId === k.id"
            data-test="nakit-kategori"
            @click="kategoriSec(k.id)"
          >
            <span class="size-2 rounded-full" :style="{ background: kategoriNoktasi(k) }" />{{
              k.ad
            }}
          </button>
          <button
            type="button"
            class="inline-flex items-center gap-1 rounded-full px-3.5 py-2 text-sm font-semibold text-primary-700 ring-1 ring-primary-200"
            data-test="nakit-diger"
            @click="digerAcik = !digerAcik"
          >
            <i class="pi pi-ellipsis-h text-xs" /> Diğer
          </button>
        </div>
        <Select
          v-if="digerAcik"
          v-model="kategoriId"
          :options="aile.kategorilerKullanima"
          option-label="ad"
          option-value="id"
          placeholder="Kategori seçin"
          show-clear
          class="mt-2 w-full"
          aria-label="Kategori"
          data-test="nakit-kategori-secici"
        />
      </div>
      <FormAlani etiket="Tarih" for="nh-tarih" :hata="hatalar.tarih">
        <DatePicker
          v-model="tarih"
          input-id="nh-tarih"
          date-format="dd.mm.yy"
          :max-date="new Date()"
          show-icon
          icon-display="input"
          fluid
        />
      </FormAlani>
      <div class="flex justify-end gap-2">
        <Button label="Vazgeç" severity="secondary" text @click="gorunur = false" />
        <Button
          type="submit"
          label="Kaydet"
          icon="pi pi-check"
          :loading="gonderiliyor"
          :disabled="tutar === null || tutar <= 0"
          data-test="nakit-kaydet"
        />
      </div>
    </form>
  </UygulamaPenceresi>
</template>
