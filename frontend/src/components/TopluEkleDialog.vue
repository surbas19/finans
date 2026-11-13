<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import Message from 'primevue/message'
import Select from 'primevue/select'
import Textarea from 'primevue/textarea'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import FormAlani from '@/components/FormAlani.vue'
import { useToast } from 'primevue/usetoast'
import { finansApi, type HesapOzeti } from '@/api/finans'
import { hataMesaji } from '@/api/http'
import { useFormGonder } from '@/composables/useFormGonder'
import { useKatalogStore } from '@/stores/katalog'
import { bugun, fiyat, miktar, tarih, tarihMetni } from '@/utils/bicim'
import { EN_COK_SATIR, YENI_KOD_PARCASI, satirlariCoz } from '@/utils/topluIslem'

/**
 * Elde olan varlıkları toplu ekleme: her satır `kod;miktar;birim fiyat;tarih;not` (yalnızca ilk ikisi zorunlu).
 * Yazdıkça önizleme gösterilir; hatalı satır varken kaydedilmez. Katalogda olmayan kodlar "Ekle"de önce Borsa
 * İstanbul'da, sonra kriptoda aranıp kataloğa eklenir; hepsi bulununca kayıt tek istekte, hepsi ya da hiçbiri.
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
/** Kaynakta aranıp bulunamayan yeni kodlar (o satırlar hatalı gösterilir) */
const bulunamayan = ref<ReadonlySet<string>>(new Set())
const kodlarAraniyor = ref(false)
const { hatalar, gonderiliyor, gonder } = useFormGonder()

const ORNEK = 'GRAM;25\nCEYREK;4;3850\nUSD;1500;32,40;01.03.2025\nTHYAO;100;245,5'

/** Hesaplar ve katalog gelene kadar: "hesap yok" uyarısı ve "katalogda yok" hataları gösterilmez */
const yukleniyor = ref(false)

watch(gorunur, async (acik) => {
  if (!acik) return
  hatalar.value = {}
  bulunamayan.value = new Set()
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
  satirlariCoz(
    metin.value,
    katalog.varliklar,
    tarihMetni(tarihSecimi.value),
    bugun(),
    bulunamayan.value,
  ),
)
/** Önizleme yalnızca kataloğu bekler (ikinci açılışta katalog hazırdır, hesaplar yeniden gelirken de gösterilir) */
const katalogBekleniyor = computed(() => yukleniyor.value && katalog.varliklar.length === 0)
const hatali = computed(() => satirlar.value.filter((s) => s.hata).length)
/** Eklerken kaynakta aranacak kodlar (tekil) */
const yeniKodlar = computed(() => [
  ...new Set(
    satirlar.value.flatMap((s) =>
      s.yeniKod && !bulunamayan.value.has(s.yeniKod) ? [s.yeniKod] : [],
    ),
  ),
])
const fazla = computed(() => satirlar.value.length > EN_COK_SATIR)
const kaydedilebilir = computed(
  () =>
    !yukleniyor.value &&
    !kodlarAraniyor.value &&
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

/**
 * Katalogda olmayan kodları kaynakta arar; bulunanlar kataloğa eklenir (önizleme adlarıyla güncellenir).
 * Hepsi bulunduysa bulunan sayısı, bulunamayan ya da ulaşılamayan varsa `null` (kayıt yapılmaz).
 */
async function yeniKodlariBul(): Promise<number | null> {
  const kodlar = yeniKodlar.value
  if (kodlar.length === 0) return 0
  kodlarAraniyor.value = true
  const bulunamadi = new Set(bulunamayan.value)
  let ulasilamadi = 0
  let bulunan = 0
  try {
    for (let i = 0; i < kodlar.length; i += YENI_KOD_PARCASI) {
      const sonuc = await finansApi.varliklariKodlaBul(kodlar.slice(i, i + YENI_KOD_PARCASI))
      sonuc.varliklar.forEach((v) => katalog.ekle(v))
      bulunan += sonuc.varliklar.length
      sonuc.bulunamayan.forEach((k) => bulunamadi.add(k))
      ulasilamadi += sonuc.ulasilamayan.length
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Kodlar aranamadı', detail: hataMesaji(e), life: 5000 })
    return null
  } finally {
    bulunamayan.value = bulunamadi
    kodlarAraniyor.value = false
  }
  if (ulasilamadi > 0) {
    toast.add({
      severity: 'warn',
      summary: 'Fiyat kaynağına ulaşılamadı',
      detail: `${ulasilamadi} kod aranamadı; biraz sonra yeniden deneyin.`,
      life: 6000,
    })
    return null
  }

  return kodlar.some((k) => bulunamadi.has(k)) ? null : bulunan
}

async function kaydet(): Promise<void> {
  if (!kaydedilebilir.value || !secilenHesap.value) return
  const bulunan = await yeniKodlariBul()
  // Bulunamayan kod varsa o satırlar artık hatalı; yeni kodlar katalogda olduğundan satırlar varlıklarıyla çözüldü
  if (bulunan === null || hatali.value > 0 || satirlar.value.some((s) => !s.varlik)) return
  const sayi = satirlar.value.length
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
    bulunan > 0 ? `${sayi} varlık eklendi (${bulunan} yeni kod bulundu)` : `${sayi} varlık eklendi`,
  )
  if (sonuc) {
    gorunur.value = false
    metin.value = ''
    emit('kaydedildi')
  }
}
</script>

<template>
  <UygulamaPenceresi v-model:visible="gorunur" baslik="Elde olanları toplu ekle" :genislik="40">
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
          "Gram altın"). Listede olmayan hisse ve kripto kodları (ASELS, SOL…) eklerken bulunur.
          Ondalık için virgül: <span class="font-semibold">2,5</span>. Fiyatı boş bırakırsanız
          maliyet bilinmiyor sayılır. Excel'den yapıştırabilirsiniz.
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
          <span v-if="yeniKodlar.length > 0" class="text-sky-700"
            >{{ yeniKodlar.length }} yeni kod</span
          >
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
                  : s.yeniKod
                    ? 'pi-search text-sky-600'
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
                <span v-else-if="s.yeniKod" class="font-semibold text-surface-900">{{
                  s.yeniKod
                }}</span>
                <span v-else class="truncate font-mono text-surface-600">{{ s.ham }}</span>
              </p>
              <p
                v-if="(s.varlik || s.yeniKod) && s.miktar !== null"
                class="text-xs text-surface-500 tabular-nums"
              >
                {{ miktar(s.miktar) }} {{ s.varlik?.birim }} ·
                {{
                  s.varlik?.tur === 'nakit'
                    ? 'nakit'
                    : s.birimFiyat === null
                      ? 'maliyet bilinmiyor'
                      : s.varlik
                        ? `${fiyat(s.birimFiyat)} / ${s.varlik.birim}`
                        : `birim fiyat ${fiyat(s.birimFiyat)}`
                }}
                · {{ tarih(s.tarih) }}<template v-if="s.not"> · {{ s.not }}</template>
              </p>
              <p v-if="s.yeniKod && !s.hata" class="text-xs text-sky-700">
                Yeni kod: eklerken Borsa İstanbul'da, yoksa kriptoda aranır
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
          :label="
            kodlarAraniyor
              ? 'Kodlar aranıyor…'
              : satirlar.length > 0 && hatali === 0
                ? `${satirlar.length} varlığı ekle`
                : 'Ekle'
          "
          icon="pi pi-check"
          :loading="gonderiliyor || kodlarAraniyor"
          :disabled="!kaydedilebilir"
        />
      </div>
    </form>
  </UygulamaPenceresi>
</template>
