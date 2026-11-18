<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import { aileApi, type AiCalisma } from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { useAnalizTakibi } from '@/composables/useAnalizTakibi'

/**
 * Kart ekstresinin yapay zekâ analizi: onay + başlat (yeniden analizde düzeltmelerin silineceği uyarısı), arka
 * plandaki çalışmanın aşamaları ve süresi (durum birkaç saniyede bir sorulur), iptal, son hata. Bitince `bitti`.
 */
const props = defineProps<{
  ekstreId: number
  aiAcik: boolean
  taslak: boolean
  /** Sayfa açılırken bilinen son çalışma */
  ilkCalisma: AiCalisma | null
  /** Daha önce yapay zekâ sonucu uygulandı mı (yeniden analiz uyarısı) */
  oncekiAnalizVar: boolean
}>()
const emit = defineEmits<{ bitti: [calisma: AiCalisma | null]; surme: [suruyor: boolean] }>()

const confirm = useConfirm()
const toast = useToast()
const onay = ref(false)
const basliyor = ref(false)
const simdi = ref(Date.now())
let saat: ReturnType<typeof setInterval> | null = null

const { calisma, izle } = useAnalizTakibi(
  async () => (await aileApi.ekstreAnalizDurumu(props.ekstreId)).calisma,
  (son) => {
    if (son?.durum === 'basarili') {
      toast.add({
        severity: 'success',
        summary: 'Yapay zekâ analizi bitti',
        detail: `${son.kabul_satir} satır alındı${son.reddedilen_satir ? `, ${son.reddedilen_satir} satır doğrulamadan geçmedi` : ''}; inceleyip onaylayın.`,
        life: 6000,
      })
    } else if (son?.durum === 'hata') {
      toast.add({
        severity: 'error',
        summary: 'Yapay zekâ analizi',
        detail: son.mesaj ?? '',
        life: 8000,
      })
    }
    emit('bitti', son)
  },
)

const suruyor = computed(() => calisma.value?.etkin ?? false)
watch(suruyor, (s) => {
  emit('surme', s)
  if (s && !saat) saat = setInterval(() => (simdi.value = Date.now()), 1000)
  if (!s && saat) {
    clearInterval(saat)
    saat = null
  }
})
onMounted(() => izle(props.ilkCalisma))
onBeforeUnmount(() => {
  if (saat) clearInterval(saat)
})

const ASAMALAR = [
  { ad: 'sirada', etiket: 'Sırada' },
  { ad: 'gonderildi', etiket: 'PDF gönderiliyor' },
  { ad: 'okunuyor', etiket: 'Yapay zekâ okuyor' },
  { ad: 'dogrulaniyor', etiket: 'Doğrulanıyor' },
] as const
const asamaSirasi = computed(() => ASAMALAR.findIndex((a) => a.ad === calisma.value?.asama))
const gecen = computed(() => {
  const bas = calisma.value?.basladi_at ? Date.parse(calisma.value.basladi_at) : null
  if (!bas) return ''
  const sn = Math.max(0, Math.round((simdi.value - bas) / 1000))

  return sn < 60 ? `${sn} sn` : `${Math.floor(sn / 60)} dk ${sn % 60} sn`
})

async function baslat(): Promise<void> {
  basliyor.value = true
  try {
    const sonuc = await aileApi.ekstreYapayZeka(props.ekstreId)
    toast.add({ severity: 'info', summary: sonuc.message, life: 4000 })
    onay.value = false
    izle(sonuc.calisma)
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Başlatılamadı', detail: hataMesaji(e), life: 8000 })
  } finally {
    basliyor.value = false
  }
}

function baslatIste(): void {
  if (!onay.value) return
  if (!props.oncekiAnalizVar) {
    void baslat()

    return
  }
  confirm.require({
    header: 'Yeniden analiz',
    message:
      'Yapay zekâ ekstreyi yeniden okuyacak; satırlarda yaptığınız düzeltmeler ve "Kontrol ettim" işaretleri silinebilir. Sürdürülsün mü?',
    acceptProps: { label: 'Yeniden analiz et' },
    rejectProps: { label: 'Vazgeç', severity: 'secondary', text: true },
    accept: () => void baslat(),
  })
}

async function iptal(): Promise<void> {
  try {
    const sonuc = await aileApi.ekstreAnalizIptal(props.ekstreId)
    izle(sonuc.calisma)
    emit('bitti', sonuc.calisma)
  } catch (e) {
    toast.add({ severity: 'error', summary: 'İptal edilemedi', detail: hataMesaji(e), life: 5000 })
  }
}
</script>

<template>
  <!-- Sürüyor: aşamalar, süre, iptal -->
  <div
    v-if="suruyor"
    data-test="ai-ilerleme"
    class="mt-3 rounded-2xl bg-sky-50 p-3 text-xs text-sky-900"
    role="status"
    aria-live="polite"
  >
    <div class="flex items-center gap-2">
      <i class="pi pi-spin pi-spinner" />
      <p class="font-semibold">Yapay zekâ ekstreyi okuyor{{ gecen ? ` · ${gecen}` : '' }}</p>
      <Button label="İptal" size="small" severity="secondary" text class="ml-auto" @click="iptal" />
    </div>
    <ol class="mt-2 grid grid-cols-2 gap-1 sm:grid-cols-4">
      <li
        v-for="(a, i) in ASAMALAR"
        :key="a.ad"
        class="rounded-xl px-2 py-1 text-center"
        :class="
          i < asamaSirasi
            ? 'bg-sky-200 text-sky-900'
            : i === asamaSirasi
              ? 'bg-sky-600 font-semibold text-white'
              : 'bg-surface-0 text-sky-700'
        "
      >
        {{ a.etiket }}
      </li>
    </ol>
    <p class="mt-2">
      Sayfa sayısına göre birkaç dakika sürebilir; sayfadan çıkabilirsiniz, analiz arka planda
      sürer. Bitene kadar satırlar düzenlenemez ve onaylanamaz.
    </p>
  </div>

  <!-- Başlat: yalnızca anahtar tanımlıysa, incelemedeki ekstrede ve her seferinde onayla -->
  <div
    v-else-if="aiAcik && taslak"
    data-test="yapay-zeka"
    class="mt-3 rounded-2xl bg-sky-50 p-3 text-xs text-sky-900"
  >
    <p class="font-semibold">
      {{ oncekiAnalizVar ? 'Yapay zekâ ile yeniden oku' : 'Yapay zekâ ile oku' }}
    </p>
    <p
      v-if="calisma && ['hata', 'iptal'].includes(calisma.durum)"
      class="mt-1 rounded-xl bg-red-50 px-2 py-1 text-red-800"
      data-test="ai-hata"
    >
      Son analiz {{ calisma.durum === 'iptal' ? 'iptal edildi' : 'tamamlanamadı' }}:
      {{ calisma.mesaj }}
    </p>
    <label class="mt-2 flex items-start gap-2">
      <Checkbox
        v-model="onay"
        binary
        input-id="ai-onay"
        class="mt-0.5"
        data-test="yapay-zeka-onay"
      />
      <span
        >PDF dosyasının (adım, adresim ve maskeli kart numaram dahil) yapay zekâ analizi için
        OpenAI'ye gönderilmesini onaylıyorum. Dosya OpenAI'de saklanmaz; sonuç taslaktır.</span
      >
    </label>
    <Button
      class="mt-2"
      size="small"
      icon="pi pi-sparkles"
      :label="oncekiAnalizVar ? 'Yeniden analiz et' : 'Yapay zekâ ile oku'"
      :disabled="!onay"
      :loading="basliyor"
      @click="baslatIste"
    />
  </div>
</template>
