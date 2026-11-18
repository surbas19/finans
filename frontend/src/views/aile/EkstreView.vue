<script setup lang="ts">
import KartEtiketi from '@/components/aile/KartEtiketi.vue'
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
import AiAnalizPaneli from '@/components/aile/AiAnalizPaneli.vue'
import AiDenetimPaneli from '@/components/aile/AiDenetimPaneli.vue'
import EkstreSatiri from '@/components/aile/EkstreSatiri.vue'
import EkstreSatirFormu from '@/components/aile/EkstreSatirFormu.vue'
import {
  aileApi,
  BANKA_PROFILLERI,
  type AiCalisma,
  type Ekstre,
  type Kart,
  type KartIslemi,
} from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { useFormGonder } from '@/composables/useFormGonder'
import { useAileStore } from '@/stores/aile'
import { kartRengiCss } from '@/utils/kartRengi'
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
const aiAktarim = ref(false)
const aiCalisma = ref<AiCalisma | null>(null)
/** Yapay zekâ analizi sürüyor: satırlar düzenlenmez, onay yok */
const aiSuruyor = ref(false)
const onaylaniyor = ref(false)
const duzeltilen = ref<KartIslemi | null>(null)
const duzeltAcik = ref(false)
const goster = ref<'hepsi' | 'inceleme' | 'sorunlu' | 'fatura'>('hepsi')

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
    aiAktarim.value = veri.ai_aktarim ?? false
    aiCalisma.value = veri.ai_calisma ?? null
    aiSuruyor.value = veri.ai_calisma?.etkin ?? false
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
/** Fatura önerisi olan satırlar (sağlayıcı bulundu ya da aday var): sayfanın en üstünde, işaretliler ve güvenilirler önce */
const GUVEN_SIRASI: Record<string, number> = {
  kullanici: 0,
  yuksek: 1,
  orta: 2,
  belirsiz: 3,
  dusuk: 4,
}
const faturaOnerileri = computed(() =>
  dahil.value
    .filter(
      (s) =>
        s.fatura_onerisi &&
        (s.fatura_onerisi.saglayici_id !== null || s.fatura_onerisi.adaylar.length > 0),
    )
    .sort(
      (a, b) =>
        Number(b.fatura_onerisi!.secildi) - Number(a.fatura_onerisi!.secildi) ||
        (GUVEN_SIRASI[a.fatura_onerisi!.guven] ?? 9) - (GUVEN_SIRASI[b.fatura_onerisi!.guven] ?? 9),
    ),
)
const faturaOneriIdleri = computed(() => new Set(faturaOnerileri.value.map((s) => s.id)))
/** Dosyasız (yalnızca tutarla girilen) ekstrede satır incelemesi olmadığından onay kaldırılamaz */
const onayKaldirilabilir = computed(
  () => ekstre.value?.durum === 'onaylandi' && ekstre.value.banka_profili !== 'elle',
)
const onayKaldiriliyor = ref(false)
/** Yapay zekâ satırı şüpheli ve henüz "Kontrol ettim" denmedi */
const incelemeBekleyen = computed(() =>
  dahil.value.filter((s) => s.okuma?.inceleme && !s.okuma.incelendi),
)
/** Yapay zekâ taslağı ve aktarım kapalı: yalnızca önizleme */
const yalnizOnizleme = computed(() => ekstre.value?.banka_profili === 'ai' && !aiAktarim.value)
/** Üstteki ve alttaki Onayla düğmesinin ortak kapalı koşulu */
const onayEngelli = computed(() => aiSuruyor.value || yalnizOnizleme.value)
const gorunenSatirlar = computed(() =>
  goster.value === 'inceleme'
    ? satirlar.value.filter((s) => s.okuma?.inceleme)
    : goster.value === 'sorunlu'
      ? satirlar.value.filter(
          (s) => s.okuma?.alanlar?.length || s.okuma?.uyarilar?.length || s.tekrar,
        )
      : goster.value === 'fatura'
        ? satirlar.value.filter((s) => s.fatura_onerisi)
        : // Hepsi: fatura önerileri üstte ayrı bölümde gösterilir, burada tekrarlanmaz
          satirlar.value.filter((s) => !faturaOneriIdleri.value.has(s.id)),
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
    const kesimDegisti = sonuc.ekstre.kesim_tarihi !== ekstre.value?.kesim_tarihi
    ekstre.value = sonuc.ekstre
    alanlariDoldur(sonuc.ekstre)
    // Faturaların hizmet dönemi (seçilmediyse) ekstrenin ayını izler: satırlar yeniden okunur
    if (kesimDegisti) await yukle()
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

function onayiKaldir(): void {
  confirm.require({
    header: 'Onayı kaldır',
    message:
      'Ekstre incelemeye döner: satırları düzeltebilir, fatura bağlayabilir, sonra yeniden onaylarsınız. Onaylı olmadığı sürece bu ekstre Bütçe giderlerinde sayılmaz; bağlı faturalar çözülür (ödenmemiş görünür), yeniden onayda aynı fatura kararları uygulanır. Devam edilsin mi?',
    acceptProps: { label: 'Onayı kaldır' },
    rejectProps: { label: 'Vazgeç', severity: 'secondary', text: true },
    accept: async () => {
      onayKaldiriliyor.value = true
      try {
        const sonuc = await aileApi.ekstreOnayiKaldir(props.id)
        toast.add({
          severity: 'success',
          summary: 'Onay kaldırıldı',
          detail: sonuc.message,
          life: 8000,
        })
        await yukle()
      } catch (e) {
        toast.add({ severity: 'error', summary: 'Yapılamadı', detail: hataMesaji(e), life: 8000 })
      } finally {
        onayKaldiriliyor.value = false
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
      <!-- Fatura önerileri: sayfanın en üstünde (işaretliler ve güvenilirler önce) -->
      <section
        v-if="faturaOnerileri.length && goster === 'hepsi'"
        class="kart border-amber-200 bg-amber-50/40 p-3"
        data-test="fatura-onerileri"
      >
        <h2 class="bolum-etiketi mb-2 !text-amber-800">
          Fatura önerileri ({{ faturaOnerileri.length }})
        </h2>
        <ul class="flex flex-col gap-2">
          <EkstreSatiri
            v-for="s in faturaOnerileri"
            :key="s.id"
            :satir="s"
            :ekstre-id="id"
            :duzenlenebilir="taslak && !aiSuruyor"
            @degisti="satirDegisti"
            @duzelt="duzelt"
          />
        </ul>
      </section>

      <section
        class="kart border-t-4 p-4 sm:p-5"
        :style="{ borderTopColor: kartRengiCss(kart ?? { id: ekstre.kart_id }) }"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <h2 class="flex items-center gap-1 truncate text-lg font-bold text-surface-900">
              <KartEtiketi :kart-id="ekstre.kart_id" :ad="kart?.ad" /> ·
              {{ ekstre.donem_etiketi ?? 'Dönem okunamadı' }}
            </h2>
            <p class="text-xs text-surface-500">
              {{ ekstre.dosya_adi
              }}<template v-if="ekstre.sayfa_sayisi"> · {{ ekstre.sayfa_sayisi }} sayfa</template>
            </p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <!-- Üstte de Onayla: uzun satır listesini kaydırmadan onaylanabilir (aynı onay penceresi) -->
            <Button
              v-if="taslak"
              label="Onayla"
              icon="pi pi-check"
              size="small"
              :loading="onaylaniyor"
              :disabled="onayEngelli"
              data-test="onayla-ust"
              @click="onayla"
            />
            <Tag
              :value="taslak ? 'İnceleme bekliyor' : 'Onaylandı'"
              :severity="taslak ? 'warn' : 'success'"
            />
            <Button
              v-if="onayKaldirilabilir"
              label="Onayı kaldır"
              icon="pi pi-undo"
              size="small"
              severity="secondary"
              outlined
              :loading="onayKaldiriliyor"
              data-test="onayi-kaldir"
              @click="onayiKaldir"
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
              class="mt-0.5 truncate font-mono text-xs"
            >
              {{ o }}
            </p>
          </template>
          <p class="mt-2">
            Satırları kartın sayfasından elle ekleyebilirsiniz; bu bankanın düzeni için bu bilgiyi
            geliştiriciye iletin.
          </p>
        </div>

        <!-- Yapay zekâ: başlat / ilerleme / iptal (yalnızca anahtar tanımlıysa, her seferinde onayla) -->
        <AiAnalizPaneli
          :ekstre-id="ekstre.id"
          :ai-acik="aiAcik && ekstre.dosya_var"
          :taslak="taslak"
          :ilk-calisma="aiCalisma"
          :onceki-analiz-var="!!ekstre.denetim?.ai"
          @surme="(s: boolean) => (aiSuruyor = s)"
          @bitti="yukle"
        />
        <AiDenetimPaneli :ekstre="ekstre" />

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
          <span v-if="incelemeBekleyen.length" class="font-semibold text-amber-700"
            >{{ incelemeBekleyen.length }} inceleme bekliyor</span
          >
          <div class="ml-auto flex flex-wrap gap-1">
            <Button
              v-for="g in [
                { d: 'hepsi', e: 'Hepsi' },
                { d: 'inceleme', e: 'İnceleme' },
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
            :duzenlenebilir="taslak && !aiSuruyor"
            @degisti="satirDegisti"
            @duzelt="duzelt"
          />
        </ul>
      </section>

      <!-- Telefonda alt çubuğun üstünde sabit onay; masaüstünde akışta -->
      <div
        class="fixed inset-x-0 bottom-[calc(4.25rem+env(safe-area-inset-bottom))] z-10 flex justify-center gap-2 border-t border-surface-200 bg-surface-0/95 p-3 backdrop-blur md:static md:border-0 md:bg-transparent md:p-0"
      >
        <Button
          v-if="taslak"
          :label="`Onayla (${dahil.length} satır)`"
          icon="pi pi-check"
          :loading="onaylaniyor"
          :disabled="onayEngelli"
          data-test="onayla-alt"
          @click="onayla"
        />
        <Button label="Ekstreyi sil" icon="pi pi-trash" severity="danger" text @click="sil" />
      </div>
      <p v-if="!taslak" class="text-center text-xs text-surface-500">
        Onaylı ekstrenin satırları İşlemler sayfasından düzeltilir. Fatura bağlamak ya da satırları
        değiştirmek için "Onayı kaldır"ı kullanın.
      </p>
      <p
        v-else-if="yalnizOnizleme"
        class="text-center text-xs text-surface-500"
        data-test="onizleme"
      >
        Yapay zekâ taslağının Bütçe'ye aktarımı henüz açılmadı: satırları önizleyebilirsiniz.
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
