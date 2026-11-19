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
import EvNakdiHareketFormu from '@/components/aile/EvNakdiHareketFormu.vue'
import { aileApi, type OdemeKaynaklari } from '@/api/aile'
import { useFormGonder } from '@/composables/useFormGonder'
import { useAileStore } from '@/stores/aile'
import { tarihMetni, tl } from '@/utils/bicim'
import { tarihtenDonem } from '@/utils/donem'
import { kategoriNoktasi } from '@/utils/kategoriRengi'

/**
 * Nakit harcama (hızlı işlemler): kartsız gideri tek ekranda girer. Tutar, isteğe bağlı açıklama, en çok kullanılan
 * kategori çipleri ve tarih (bugün). Düzenli ödeme / dönem / not alanları yoktur: dönem tarihin ayıdır. Sunucuda
 * `duzenli_giderler` kaydı olur, Bütçe'de o ayın kartsız gideri sayılır. Kartla ödenen harcama ekstreden gelir.
 * Ödeme yeri Ev ise tutar ev nakdi kasasından, Banka ise seçilen portföy hesabının Nakit (TL) bakiyesinden düşer;
 * bakiye yetmiyorsa kaydedilemez ve "Para ekle" kısayolu çıkar.
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
/** Ev nakdi bakiyesi ve hesap nakitleri (form açılırken yüklenir) */
const kaynaklar = ref<OdemeKaynaklari | null>(null)
const hesapId = ref<number | null>(null)
const evParaAcik = ref(false)
const SON_HESAP = 'finans-nakit-hesap'

async function kaynaklariYukle(): Promise<void> {
  try {
    kaynaklar.value = await aileApi.odemeKaynaklari()
  } catch {
    kaynaklar.value = null
  }
  const hesaplar = kaynaklar.value?.hesaplar ?? []
  let son: number | null = null
  try {
    son = Number(localStorage.getItem(SON_HESAP)) || null
  } catch {
    // Tarayıcı depolaması yoksa ilk hesap seçilir
  }
  hesapId.value = hesaplar.find((h) => h.id === son)?.id ?? hesaplar[0]?.id ?? null
}

/** Seçili kaynağın bakiyesi (null = bilinmiyor, denetim sunucuda) */
const bakiye = computed<number | null>(() => {
  if (!kaynaklar.value) return null
  if (odemeYeri.value === 'ev') return kaynaklar.value.ev.bakiye

  return kaynaklar.value.hesaplar.find((h) => h.id === hesapId.value)?.nakit ?? null
})
const yetmiyor = computed(
  () => bakiye.value !== null && tutar.value !== null && tutar.value > bakiye.value + 1e-9,
)
const eksik = computed(() =>
  yetmiyor.value && bakiye.value !== null && tutar.value !== null ? tutar.value - bakiye.value : 0,
)
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
  void kaynaklariYukle()
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
  if (tutar.value === null || tutar.value <= 0 || yetmiyor.value) return
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
      hesap_id: odemeYeri.value === 'banka' ? hesapId.value : null,
      donem: tarihtenDonem(tarih.value),
      not: null,
    }),
  )
  if (sonuc) {
    if (odemeYeri.value === 'banka' && hesapId.value !== null) {
      try {
        localStorage.setItem(SON_HESAP, String(hesapId.value))
      } catch {
        // Hatırlanamazsa bir sonraki açılışta ilk hesap seçilir
      }
    }
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
        <p class="mt-1 text-center text-xs text-surface-500" data-test="nakit-kaynak-bilgi">
          <template v-if="odemeYeri === 'ev'"
            >Ev nakdinden düşer<template v-if="bakiye !== null">
              · bakiye {{ tl(bakiye) }}</template
            ></template
          >
          <template v-else
            >Hesabın nakdinden düşer<template v-if="bakiye !== null">
              · nakit {{ tl(bakiye) }}</template
            ></template
          >
        </p>
        <Select
          v-if="odemeYeri === 'banka'"
          v-model="hesapId"
          :options="kaynaklar?.hesaplar ?? []"
          option-label="ad"
          option-value="id"
          placeholder="Hesap seçin"
          class="mt-2 w-full"
          aria-label="Düşülecek hesap"
          data-test="nakit-hesap"
        >
          <template #option="{ option }"
            ><span class="flex w-full items-center justify-between gap-3"
              ><span class="truncate">{{ option.ad }}</span
              ><span class="text-xs text-surface-500 tabular-nums">{{
                tl(option.nakit)
              }}</span></span
            ></template
          >
        </Select>
        <p
          v-if="odemeYeri === 'banka' && kaynaklar && !kaynaklar.hesaplar.length"
          class="mt-1 text-center text-xs text-red-600"
        >
          Önce Portföy'de bir hesap açıp nakit ekleyin.
        </p>
        <div
          v-if="yetmiyor"
          class="mt-2 flex items-center justify-between gap-2 rounded-2xl bg-red-50 p-3 text-xs text-red-800"
          role="alert"
          data-test="nakit-yetmiyor"
        >
          <span
            >{{ tl(eksik) }} eksik.
            {{
              odemeYeri === 'ev'
                ? 'Önce ev nakdine para ekleyin.'
                : 'Hesaba nakit ekleyin ya da Ev seçin.'
            }}</span
          >
          <Button
            v-if="odemeYeri === 'ev'"
            type="button"
            label="Para ekle"
            size="small"
            severity="danger"
            outlined
            data-test="nakit-para-ekle"
            @click="evParaAcik = true"
          />
        </div>
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
          :disabled="
            tutar === null || tutar <= 0 || yetmiyor || (odemeYeri === 'banka' && hesapId === null)
          "
          data-test="nakit-kaydet"
        />
      </div>
    </form>
    <EvNakdiHareketFormu v-model:visible="evParaAcik" tur="ekleme" @kaydedildi="kaynaklariYukle" />
  </UygulamaPenceresi>
</template>
