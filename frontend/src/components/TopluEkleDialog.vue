<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import Select from 'primevue/select'
import Textarea from 'primevue/textarea'
import FormAlani from '@/components/FormAlani.vue'
import { useToast } from 'primevue/usetoast'
import { finansApi, type HesapOzeti } from '@/api/finans'
import { hataMesaji } from '@/api/http'
import { useFormGonder } from '@/composables/useFormGonder'
import { useKatalogStore } from '@/stores/katalog'
import { bugun, fiyat, miktar, tarih, tarihMetni } from '@/utils/bicim'
import { EN_COK_SATIR, satirlariCoz } from '@/utils/topluIslem'

/**
 * Elde olan varlıkları toplu ekleme: her satır `kod;miktar;birim fiyat;tarih;not` (yalnızca ilk ikisi zorunlu).
 * Yazdıkça önizleme gösterilir; hatalı satır varken kaydedilmez. Kayıt tek istekte, hepsi ya da hiçbiri.
 */
const props = defineProps<{ hesapId?: number | null }>()
const gorunur = defineModel<boolean>('visible', { default: false })
const emit = defineEmits<{ kaydedildi: [] }>()

const katalog = useKatalogStore()
const toast = useToast()
const hesaplar = ref<HesapOzeti[]>([])
const secilenHesap = ref<number | null>(null)
const tarihSecimi = ref<Date>(new Date())
// Pencere kapanınca metin silinmez (yanlışlıkla kapanırsa yazılanlar kaybolmasın); kayıttan sonra temizlenir.
const metin = ref('')
const { hatalar, gonderiliyor, gonder } = useFormGonder()

const ORNEK = 'GRAM;25\nCEYREK;4;3850\nUSD;1500;32,40;01.03.2025\nTHYAO;100;245,5'

/** Hesaplar ve katalog gelene kadar: "hesap yok" uyarısı ve "katalogda yok" hataları gösterilmez */
const yukleniyor = ref(false)

watch(gorunur, async (acik) => {
  if (!acik) return
  hatalar.value = {}
  secilenHesap.value = props.hesapId ?? null
  yukleniyor.value = true
  try {
    await Promise.all([
      katalog.yukle(),
      finansApi.hesaplar().then((v) => {
        hesaplar.value = v.hesaplar
        if (!secilenHesap.value && v.hesaplar.length === 1) secilenHesap.value = v.hesaplar[0]!.id
      }),
    ])
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Yüklenemedi', detail: hataMesaji(e), life: 5000 })
  } finally {
    yukleniyor.value = false
  }
})

const satirlar = computed(() =>
  satirlariCoz(metin.value, katalog.varliklar, tarihMetni(tarihSecimi.value), bugun()),
)
/** Önizleme yalnızca kataloğu bekler (ikinci açılışta katalog hazırdır, hesaplar yeniden gelirken de gösterilir) */
const katalogBekleniyor = computed(() => yukleniyor.value && katalog.varliklar.length === 0)
const hatali = computed(() => satirlar.value.filter((s) => s.hata).length)
const fazla = computed(() => satirlar.value.length > EN_COK_SATIR)
const kaydedilebilir = computed(
  () =>
    !yukleniyor.value &&
    !!secilenHesap.value &&
    satirlar.value.length > 0 &&
    hatali.value === 0 &&
    !fazla.value,
)

// Metin değişince sunucunun satır hataları eskir
watch(metin, () => {
  if (Object.keys(hatalar.value).some((k) => k.startsWith('satirlar.'))) hatalar.value = {}
})

/** Sunucunun `satirlar.N.alan` hatası → N. önizleme satırı (yalnızca hatasız satırlar gönderildiği için sıra aynı) */
function sunucuHatasi(sira: number): string | null {
  const mesajlar = Object.entries(hatalar.value)
    .filter(([k]) => k.startsWith(`satirlar.${sira}.`))
    .map(([, m]) => m)

  return mesajlar.length > 0 ? mesajlar.join(' · ') : null
}

function ornekYaz(): void {
  metin.value = ORNEK
}

async function kaydet(): Promise<void> {
  if (!kaydedilebilir.value || !secilenHesap.value) return
  const sonuc = await gonder(
    () =>
      finansApi.topluMevcutEkle({
        hesap_id: secilenHesap.value!,
        satirlar: satirlar.value.map((s) => ({
          varlik_id: s.varlik!.id,
          miktar: s.miktar!,
          birim_fiyat: s.birimFiyat,
          tarih: s.tarih,
          not: s.not,
        })),
      }),
    `${satirlar.value.length} varlık eklendi`,
  )
  if (sonuc) {
    gorunur.value = false
    metin.value = ''
    emit('kaydedildi')
  }
}
</script>

<template>
  <Dialog
    v-model:visible="gorunur"
    modal
    header="Elde olanları toplu ekle"
    class="w-[min(40rem,calc(100vw-1rem))]"
  >
    <Message v-if="gorunur && !yukleniyor && hesaplar.length === 0" severity="warn" class="mb-3">
      Önce bir hesap ekleyin (Hesaplar → Hesap ekle).
    </Message>
    <form class="flex flex-col gap-4" @submit.prevent="kaydet">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormAlani etiket="Hesap" for="toplu-hesap" :hata="hatalar.hesap_id" zorunlu>
          <Select
            id="toplu-hesap"
            v-model="secilenHesap"
            :options="hesaplar"
            option-label="ad"
            option-value="id"
            placeholder="Seçin"
          />
        </FormAlani>
        <FormAlani etiket="Tarih" for="toplu-tarih" ipucu="Tarihi yazılmayan satırlar için">
          <DatePicker
            v-model="tarihSecimi"
            input-id="toplu-tarih"
            date-format="dd.mm.yy"
            :max-date="new Date()"
            show-icon
            icon-display="input"
          />
        </FormAlani>
      </div>

      <FormAlani etiket="Varlıklar" for="toplu-metin" :hata="hatalar.satirlar">
        <p class="text-xs text-surface-500">
          Her satıra bir varlık:
          <code class="rounded bg-surface-100 px-1 text-surface-700"
            >kod;miktar;birim fiyat;tarih;not</code
          >
          — yalnızca kod ve miktar zorunlu. Kod yerine adı da yazılabilir (GRAM, CEYREK, USD ya da
          "Gram altın"). Ondalık için virgül: <span class="font-semibold">2,5</span>. Fiyatı boş
          bırakırsanız maliyet bilinmiyor sayılır. Excel'den yapıştırabilirsiniz.
          <button
            v-if="!metin"
            type="button"
            class="font-semibold text-primary-600"
            @click="ornekYaz"
          >
            Örnek yaz
          </button>
        </p>
        <Textarea
          id="toplu-metin"
          v-model="metin"
          rows="6"
          spellcheck="false"
          autocapitalize="off"
          autocomplete="off"
          class="font-mono text-sm"
          :placeholder="ORNEK"
        />
      </FormAlani>

      <p
        v-if="katalogBekleniyor && satirlar.length > 0"
        class="flex items-center gap-2 text-sm text-surface-500"
      >
        <i class="pi pi-spin pi-spinner" /> Varlık listesi yükleniyor…
      </p>
      <section v-else-if="satirlar.length > 0" aria-label="Önizleme" class="flex flex-col gap-2">
        <p class="flex flex-wrap items-center gap-x-3 text-sm">
          <span class="font-semibold text-surface-900">Önizleme</span>
          <span class="text-emerald-700">{{ satirlar.length - hatali }} hazır</span>
          <span v-if="hatali > 0" class="text-red-600">{{ hatali }} hatalı</span>
        </p>
        <Message v-if="fazla" severity="warn" size="small">
          Tek seferde en çok {{ EN_COK_SATIR }} satır eklenebilir; listeyi bölün.
        </Message>
        <ul
          class="max-h-64 divide-y divide-surface-100 overflow-y-auto rounded-2xl border border-surface-200"
        >
          <li
            v-for="(s, sira) in satirlar"
            :key="s.satirNo"
            class="flex items-start gap-2.5 px-3 py-2 text-sm"
          >
            <i
              class="pi mt-0.5 shrink-0"
              :class="
                s.hata || sunucuHatasi(sira)
                  ? 'pi-times-circle text-red-600'
                  : s.uyari
                    ? 'pi-exclamation-triangle text-amber-600'
                    : 'pi-check-circle text-emerald-600'
              "
            />
            <div class="min-w-0 flex-1">
              <p class="flex flex-wrap items-baseline gap-x-2">
                <span class="text-xs text-surface-400 tabular-nums">{{ s.satirNo }}.</span>
                <span v-if="s.varlik" class="font-semibold text-surface-900">
                  {{ s.varlik.ad }}
                  <span class="font-normal text-surface-400">{{ s.varlik.kod }}</span>
                </span>
                <span v-else class="truncate font-mono text-surface-600">{{ s.ham }}</span>
              </p>
              <p v-if="s.varlik && s.miktar !== null" class="text-xs text-surface-500 tabular-nums">
                {{ miktar(s.miktar) }} {{ s.varlik.birim }} ·
                {{
                  s.birimFiyat !== null
                    ? `${fiyat(s.birimFiyat)} / ${s.varlik.birim}`
                    : 'maliyet bilinmiyor'
                }}
                · {{ tarih(s.tarih) }}<template v-if="s.not"> · {{ s.not }}</template>
              </p>
              <p v-if="s.hata" class="text-xs text-red-600">{{ s.hata }}</p>
              <p v-if="sunucuHatasi(sira)" class="text-xs text-red-600">{{ sunucuHatasi(sira) }}</p>
              <p v-if="s.uyari" class="text-xs text-amber-700">{{ s.uyari }}</p>
            </div>
          </li>
        </ul>
      </section>

      <div class="flex justify-end gap-2">
        <Button label="Vazgeç" severity="secondary" text @click="gorunur = false" />
        <Button
          type="submit"
          :label="satirlar.length > 0 && hatali === 0 ? `${satirlar.length} varlığı ekle` : 'Ekle'"
          icon="pi pi-check"
          :loading="gonderiliyor"
          :disabled="!kaydedilebilir"
        />
      </div>
    </form>
  </Dialog>
</template>
