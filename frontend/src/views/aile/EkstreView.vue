<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import InputNumber from 'primevue/inputnumber'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'
import ToggleSwitch from 'primevue/toggleswitch'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import FormAlani from '@/components/FormAlani.vue'
import EkstreSatiri from '@/components/aile/EkstreSatiri.vue'
import EkstreSatirFormu from '@/components/aile/EkstreSatirFormu.vue'
import { aileApi, BANKA_PROFILLERI, type Ekstre, type Kart, type KartIslemi } from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { useFormGonder } from '@/composables/useFormGonder'
import { useAileStore } from '@/stores/aile'
import { tarihMetni, tl } from '@/utils/bicim'

/**
 * Ekstre incelemesi: PDF'den okunan dönem bilgileri (kaynağıyla) ve satırlar taslaktır. Kullanıcı PDF ile
 * karşılaştırır, düzeltir, dışarıda bırakır, fatura adaylarını onaylar; onayla hepsi ya da hiçbiri kaydedilir.
 */
const props = defineProps<{ id: number }>()
const aile = useAileStore()
const router = useRouter()
const confirm = useConfirm()
const toast = useToast()
const { hatalar, gonderiliyor, gonder } = useFormGonder()

const ekstre = ref<Ekstre | null>(null)
const kart = ref<Kart | null>(null)
const satirlar = ref<KartIslemi[]>([])
const bulunamadi = ref(false)
const aiAcik = ref(false)
const aiOnay = ref(false)
const aiCalisiyor = ref(false)
const onaylaniyor = ref(false)
const duzeltilen = ref<KartIslemi | null>(null)
const duzeltAcik = ref(false)
const goster = ref<'hepsi' | 'sorunlu' | 'fatura'>('hepsi')

/** Dönem bilgileri formu */
const alan = ref({
  kesim: null as Date | null,
  sonOdeme: null as Date | null,
  borc: null as number | null,
  asgari: null as number | null,
})

async function yukle(): Promise<void> {
  try {
    await aile.yukle()
    const veri = await aileApi.ekstre(props.id)
    ekstre.value = veri.ekstre
    kart.value = veri.kart
    aiAcik.value = veri.ai_acik ?? false
    satirlar.value = veri.satirlar
    alanlariDoldur(veri.ekstre)
  } catch {
    bulunamadi.value = true
  }
}
onMounted(yukle)

function alanlariDoldur(e: Ekstre): void {
  alan.value = {
    kesim: e.kesim_tarihi ? new Date(`${e.kesim_tarihi}T00:00:00`) : null,
    sonOdeme: e.son_odeme_tarihi ? new Date(`${e.son_odeme_tarihi}T00:00:00`) : null,
    borc: e.donem_borcu,
    asgari: e.asgari_odeme,
  }
}

const taslak = computed(() => ekstre.value?.durum === 'taslak')
const dahil = computed(() => satirlar.value.filter((s) => s.durum !== 'atlandi'))
const okunamayanSatir = computed(() =>
  satirlar.value.filter((s) => s.okuma?.alanlar?.length && s.durum !== 'atlandi'),
)
const faturaSecili = computed(() => dahil.value.filter((s) => s.fatura_onerisi?.secildi))
const gorunenSatirlar = computed(() =>
  goster.value === 'sorunlu'
    ? satirlar.value.filter(
        (s) => s.okuma?.alanlar?.length || s.okuma?.uyarilar?.length || s.tekrar,
      )
    : goster.value === 'fatura'
      ? satirlar.value.filter((s) => s.fatura_onerisi)
      : satirlar.value,
)

function kaynak(alanAdi: string): string {
  if (ekstre.value?.okunamayan.includes(alanAdi)) return 'okunamadı'

  const k = ekstre.value?.alan_kaynaklari[alanAdi]
  if (k === 'hesaplandi') {
    return `hesaplandı: dönem borcu × %${kart.value?.asgari_oran ?? 40} (kart ayarındaki oran)`
  }

  if (k === 'ai') return 'yapay zekâ okudu'

  return k === 'kullanici' ? 'siz girdiniz' : "PDF'den"
}

async function yapayZekayaOkut(): Promise<void> {
  if (!aiOnay.value) return
  aiCalisiyor.value = true
  try {
    const sonuc = await aileApi.ekstreYapayZeka(props.id)
    toast.add({ severity: 'success', summary: sonuc.message, life: 6000 })
    aiOnay.value = false
    await yukle()
  } catch (e) {
    toast.add({
      severity: 'error',
      summary: 'Yapay zekâ ile okunamadı',
      detail: hataMesaji(e),
      life: 8000,
    })
  } finally {
    aiCalisiyor.value = false
  }
}

async function alanlariKaydet(): Promise<void> {
  const sonuc = await gonder(
    () =>
      aileApi.ekstreGuncelle(props.id, {
        kesim_tarihi: alan.value.kesim ? tarihMetni(alan.value.kesim) : null,
        son_odeme_tarihi: alan.value.sonOdeme ? tarihMetni(alan.value.sonOdeme) : null,
        donem_borcu: alan.value.borc,
        asgari_odeme: alan.value.asgari,
      }),
    'Dönem bilgileri kaydedildi',
  )
  if (sonuc) {
    ekstre.value = sonuc.ekstre
    alanlariDoldur(sonuc.ekstre)
  }
}

function satirDegisti(yeni: KartIslemi): void {
  const i = satirlar.value.findIndex((s) => s.id === yeni.id)
  if (i >= 0) satirlar.value[i] = { ...satirlar.value[i], ...yeni }
}

function duzelt(s: KartIslemi): void {
  duzeltilen.value = s
  duzeltAcik.value = true
}

function onayla(): void {
  confirm.require({
    header: 'Ekstreyi onayla',
    message: `${dahil.value.length} satır kaydedilecek, ${satirlar.value.length - dahil.value.length} satır dışarıda kalacak${faturaSecili.value.length ? `, ${faturaSecili.value.length} fatura bağlanacak` : ''}. Onaylıyor musunuz?`,
    acceptProps: { label: 'Onayla' },
    rejectProps: { label: 'Vazgeç', severity: 'secondary', text: true },
    accept: async () => {
      onaylaniyor.value = true
      try {
        const sonuc = await aileApi.ekstreOnayla(props.id)
        toast.add({
          severity: 'success',
          summary: 'Ekstre onaylandı',
          detail: sonuc.message,
          life: 6000,
        })
        await yukle()
      } catch (e) {
        toast.add({ severity: 'error', summary: 'Onaylanamadı', detail: hataMesaji(e), life: 8000 })
      } finally {
        onaylaniyor.value = false
      }
    },
  })
}

async function odendi(deger: boolean): Promise<void> {
  const sonuc = await gonder(() => aileApi.ekstreGuncelle(props.id, { odendi: deger }))
  if (sonuc) ekstre.value = sonuc.ekstre
}

function sil(): void {
  confirm.require({
    header: 'Ekstreyi sil',
    message:
      'Ekstre, satırları ve PDF dosyası silinsin mi? Bağlı faturalar silinmez, ödenmemiş görünür.',
    acceptProps: { label: 'Sil', severity: 'danger' },
    rejectProps: { label: 'Vazgeç', severity: 'secondary', text: true },
    accept: async () => {
      try {
        const kartId = ekstre.value?.kart_id
        await aileApi.ekstreSil(props.id)
        toast.add({ severity: 'success', summary: 'Ekstre silindi', life: 3000 })
        await router.replace(
          kartId ? { name: 'aile-kart', params: { id: kartId } } : { name: 'aile-kartlar' },
        )
      } catch (e) {
        toast.add({ severity: 'error', summary: 'Silinemedi', detail: hataMesaji(e), life: 5000 })
      }
    },
  })
}
</script>

<template>
  <div class="flex flex-col gap-4 pb-20 md:pb-0">
    <Message v-if="bulunamadi" severity="error">Ekstre bulunamadı.</Message>
    <Skeleton v-else-if="!ekstre" height="12rem" border-radius="1.5rem" />
    <template v-else>
      <section class="kart p-4 sm:p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <h2 class="truncate text-lg font-bold text-surface-900">
              {{ kart?.ad }} · {{ ekstre.donem_etiketi ?? 'Dönem okunamadı' }}
            </h2>
            <p class="text-xs text-surface-500">
              {{ ekstre.dosya_adi
              }}<template v-if="ekstre.sayfa_sayisi"> · {{ ekstre.sayfa_sayisi }} sayfa</template>
            </p>
          </div>
          <div class="flex items-center gap-2">
            <Tag
              :value="taslak ? 'İnceleme bekliyor' : 'Onaylandı'"
              :severity="taslak ? 'warn' : 'success'"
            />
            <a
              v-if="ekstre.dosya_var"
              :href="aileApi.ekstreDosyaAdresi(ekstre.id)"
              target="_blank"
              rel="noopener"
              class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 text-sm font-semibold text-primary-600 ring-1 ring-primary-200"
              ><i class="pi pi-file-pdf" /> PDF'i aç</a
            >
          </div>
        </div>

        <!-- Banka tanıma ve mutabakat: PDF'in kendi toplamlarıyla satırlar tutuyor mu -->
        <div
          v-if="ekstre.denetim"
          data-test="mutabakat"
          class="mt-3 rounded-2xl p-3 text-xs"
          :class="
            ekstre.denetim.durum === 'tutuyor'
              ? 'bg-emerald-50 text-emerald-800'
              : ekstre.denetim.durum === 'tutmuyor'
                ? 'bg-red-50 text-red-800'
                : 'bg-surface-50 text-surface-600'
          "
        >
          <p class="font-semibold">
            {{ BANKA_PROFILLERI[ekstre.banka_profili ?? 'genel'] ?? 'Tanınmayan banka' }} ·
            <template v-if="ekstre.denetim.durum === 'tutuyor'"
              >mutabakat tutuyor (borç denklemi kuruşu kuruşuna)</template
            >
            <template v-else-if="ekstre.denetim.durum === 'tutmuyor'"
              >mutabakat tutmuyor: {{ ekstre.denetim.fark }} TL fark. Satırları PDF'le
              karşılaştırın; eksik ya da yanlış satır olabilir.</template
            >
            <template v-else>mutabakat kontrol edilemedi</template>
          </p>
          <ul v-if="ekstre.denetim.durum !== 'tutuyor'" class="mt-1 list-disc pl-4">
            <li v-for="k in ekstre.denetim.kontroller" :key="k.ad">
              {{ k.etiket }}:
              <template v-if="k.durum === 'tutmuyor'"
                >ekstre {{ k.beklenen }}, satırlar {{ k.bulunan }} (fark {{ k.fark }})</template
              >
              <template v-else-if="k.durum === 'kontrol_edilemedi'">{{ k.neden }}</template>
              <template v-else>tutuyor</template>
            </li>
          </ul>
          <p v-if="ekstre.kalan_taksit" class="mt-1">
            Kalan taksit tutarı (gelecek dönemlere): {{ tl(ekstre.kalan_taksit) }}
          </p>
        </div>

        <!-- "Neden okunamadı?": 0 satır ya da tanınmayan banka -->
        <div
          v-if="ekstre.denetim?.teshis"
          data-test="teshis"
          class="mt-3 rounded-2xl bg-amber-50 p-3 text-xs text-amber-900"
        >
          <p class="font-semibold">Okuma teşhisi</p>
          <p class="mt-1">
            {{ ekstre.denetim.teshis.sayfa_sayisi }} sayfa,
            {{ ekstre.denetim.teshis.metin_satiri }} metin satırı,
            {{ ekstre.denetim.teshis.tarihli_satir }} tarihli satır,
            {{ ekstre.denetim.teshis.okunan_satir }} işlem okundu.
          </p>
          <ul class="mt-1 list-disc pl-4">
            <li v-for="n in ekstre.denetim.teshis.nedenler" :key="n">{{ n }}</li>
          </ul>
          <template v-if="ekstre.denetim.teshis.ornekler.length">
            <p class="mt-2 font-semibold">Örnek satırlar (rakamlar gizlendi)</p>
            <p
              v-for="o in ekstre.denetim.teshis.ornekler"
              :key="o"
              class="mt-0.5 truncate font-mono text-[11px]"
            >
              {{ o }}
            </p>
          </template>
          <p class="mt-2">
            Satırları kartın sayfasından elle ekleyebilirsiniz; bu bankanın düzeni için bu bilgiyi
            geliştiriciye iletin.
          </p>
        </div>

        <!-- Yapay zekâ yedeği: yalnızca anahtar tanımlıysa, incelemedeki ekstrede ve her seferinde onayla -->
        <div
          v-if="aiAcik && taslak"
          data-test="yapay-zeka"
          class="mt-3 rounded-2xl bg-sky-50 p-3 text-xs text-sky-900"
        >
          <p class="font-semibold">Yapay zekâ ile oku (yedek)</p>
          <p class="mt-1">
            Satırlar eksik ya da yanlışsa ekstre metni yapay zekâya okutulabilir. PDF'in kendisi
            değil, ad / adres / kart / TC satırları çıkarılmış metin OpenAI'ye gönderilir. Sonuç
            satırları yenileyip yine incelemeye bırakır; onaylamadan hiçbir şey kaydedilmez.
          </p>
          <label class="mt-2 flex items-start gap-2">
            <input v-model="aiOnay" type="checkbox" class="mt-0.5" data-test="yapay-zeka-onay" />
            <span>Bu ekstrenin metninin OpenAI'ye gönderilmesini onaylıyorum.</span>
          </label>
          <Button
            class="mt-2"
            size="small"
            icon="pi pi-sparkles"
            label="Yapay zekâ ile oku"
            :disabled="!aiOnay"
            :loading="aiCalisiyor"
            @click="yapayZekayaOkut"
          />
        </div>
        <div
          v-if="ekstre.denetim?.ai"
          data-test="ai-denetimi"
          class="mt-3 rounded-2xl bg-sky-50 p-3 text-xs text-sky-900"
        >
          <p class="font-semibold">
            Yapay zekâ okudu ({{ ekstre.denetim.ai.model }}) ·
            {{ ekstre.denetim.ai.sayfa_denetimi.analiz_edilen_sayfa }} /
            {{ ekstre.denetim.ai.sayfa_denetimi.pdf_sayfa }} sayfa
            <template v-if="!ekstre.denetim.ai.sayfa_denetimi.sayfa_uyusuyor"
              >· sayfa sayısı uyuşmuyor</template
            >
          </p>
          <ul class="mt-1 list-disc pl-4">
            <li v-for="s in ekstre.denetim.ai.sayfa_denetimi.sayfalar" :key="s.sayfa">
              Sayfa {{ s.sayfa }}:
              {{
                s.islem_tablosu_var ? s.ayristirilan_islem + ' işlem alındı' : 'işlem tablosu yok'
              }}
              <template v-if="s.inceleme_gereken">
                · {{ s.inceleme_gereken }} satır inceleme gerek</template
              >
            </li>
          </ul>
          <template v-if="ekstre.denetim.ai.reddedilen.length">
            <p class="mt-2 font-semibold">
              Doğrulamadan geçmeyen {{ ekstre.denetim.ai.reddedilen.length }} satır (alınmadı)
            </p>
            <p v-for="(r, i) in ekstre.denetim.ai.reddedilen" :key="i" class="mt-0.5">
              Sayfa {{ r.sayfa }}: {{ r.neden }} <span class="font-mono">{{ r.kaynak }}</span>
            </p>
          </template>
          <p v-for="u in ekstre.denetim.ai.uyarilar" :key="u" class="mt-1">{{ u }}</p>
        </div>

        <p class="mt-3 rounded-2xl bg-surface-50 p-3 text-xs text-surface-600">
          <strong>İşlem tarihi</strong>: harcamanın yapıldığı gün (aylık özette bu aya yazılır;
          taksitler ekstrenin ayına). <strong>Kesim</strong>: ekstrenin kapandığı gün.
          <strong>Son ödeme</strong>: kart borcunun ödeneceği gün. Kart borcu ödemesi gider
          sayılmaz.
        </p>

        <form class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-4" @submit.prevent="alanlariKaydet">
          <FormAlani
            etiket="Kesim tarihi"
            for="e-kesim"
            :hata="hatalar.kesim_tarihi"
            :ipucu="kaynak('kesim_tarihi')"
          >
            <DatePicker
              v-model="alan.kesim"
              input-id="e-kesim"
              date-format="dd.mm.yy"
              :disabled="!taslak"
              :invalid="ekstre.okunamayan.includes('kesim_tarihi')"
            />
          </FormAlani>
          <FormAlani
            etiket="Son ödeme"
            for="e-odeme"
            :hata="hatalar.son_odeme_tarihi"
            :ipucu="kaynak('son_odeme_tarihi')"
          >
            <DatePicker
              v-model="alan.sonOdeme"
              input-id="e-odeme"
              date-format="dd.mm.yy"
              :invalid="ekstre.okunamayan.includes('son_odeme_tarihi')"
            />
          </FormAlani>
          <FormAlani
            etiket="Dönem borcu"
            for="e-borc"
            :hata="hatalar.donem_borcu"
            :ipucu="kaynak('donem_borcu')"
          >
            <InputNumber
              v-model="alan.borc"
              input-id="e-borc"
              locale="tr-TR"
              :max-fraction-digits="2"
              prefix="₺"
              :invalid="ekstre.okunamayan.includes('donem_borcu')"
            />
          </FormAlani>
          <FormAlani
            etiket="Asgari ödeme"
            for="e-asgari"
            :hata="hatalar.asgari_odeme"
            :ipucu="kaynak('asgari_odeme')"
          >
            <InputNumber
              v-model="alan.asgari"
              input-id="e-asgari"
              locale="tr-TR"
              :max-fraction-digits="2"
              prefix="₺"
              :invalid="ekstre.okunamayan.includes('asgari_odeme')"
            />
          </FormAlani>
          <div class="flex flex-wrap items-center justify-between gap-2 sm:col-span-4">
            <label v-if="!taslak" class="flex items-center gap-2 text-sm text-surface-700">
              <ToggleSwitch :model-value="ekstre.odendi" @update:model-value="odendi" /> Kart borcu
              ödendi
            </label>
            <span v-else />
            <Button
              type="submit"
              label="Dönem bilgilerini kaydet"
              size="small"
              severity="secondary"
              outlined
              :loading="gonderiliyor"
            />
          </div>
        </form>
        <Message v-if="ekstre.okunamayan.length" severity="warn" size="small" class="mt-3">
          PDF'de okunamayan alanlar var; PDF'e bakıp girin. Kesim tarihi girilmeden onaylanmaz.
        </Message>
      </section>

      <section class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center gap-2 text-sm">
          <span class="font-semibold text-surface-900">{{ satirlar.length }} satır</span>
          <span class="text-emerald-700">{{ dahil.length }} dahil</span>
          <span class="text-surface-500">{{ satirlar.length - dahil.length }} dışarıda</span>
          <span v-if="okunamayanSatir.length" class="text-red-600"
            >{{ okunamayanSatir.length }} okunamayan</span
          >
          <span v-if="faturaSecili.length" class="text-amber-700"
            >{{ faturaSecili.length }} fatura bağlanacak</span
          >
          <div class="ml-auto flex gap-1">
            <Button
              v-for="g in [
                { d: 'hepsi', e: 'Hepsi' },
                { d: 'sorunlu', e: 'Dikkat' },
                { d: 'fatura', e: 'Faturalar' },
              ] as const"
              :key="g.d"
              :label="g.e"
              size="small"
              :severity="goster === g.d ? undefined : 'secondary'"
              :text="goster !== g.d"
              @click="goster = g.d"
            />
          </div>
        </div>
        <p v-if="!satirlar.length" class="kart p-4 text-sm text-surface-500">
          PDF'de işlem satırı bulunamadı. Satırları kart sayfasından elle ekleyebilirsiniz.
        </p>
        <ul class="flex flex-col gap-2">
          <EkstreSatiri
            v-for="s in gorunenSatirlar"
            :key="s.id"
            :satir="s"
            :ekstre-id="id"
            :duzenlenebilir="taslak"
            @degisti="satirDegisti"
            @duzelt="duzelt"
          />
        </ul>
      </section>

      <!-- Telefonda alt çubuğun üstünde sabit onay; masaüstünde akışta -->
      <div
        class="fixed inset-x-0 bottom-[calc(4.25rem+env(safe-area-inset-bottom))] z-10 flex justify-center gap-2 border-t border-surface-200 bg-white/95 p-3 backdrop-blur md:static md:border-0 md:bg-transparent md:p-0"
      >
        <Button
          v-if="taslak"
          :label="`Onayla (${dahil.length} satır)`"
          icon="pi pi-check"
          :loading="onaylaniyor"
          @click="onayla"
        />
        <Button label="Ekstreyi sil" icon="pi pi-trash" severity="danger" text @click="sil" />
      </div>
      <p v-if="!taslak" class="text-center text-xs text-surface-500">
        Onaylı ekstrenin satırları İşlemler sayfasından düzeltilir.
      </p>
    </template>

    <EkstreSatirFormu
      v-model:visible="duzeltAcik"
      :satir="duzeltilen"
      :ekstre-id="id"
      @kaydedildi="satirDegisti"
    />
  </div>
</template>
