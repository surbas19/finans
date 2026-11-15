<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Message from 'primevue/message'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import { useToast } from 'primevue/usetoast'
import FormAlani from '@/components/FormAlani.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import { aileApi } from '@/api/aile'
import { useFormGonder } from '@/composables/useFormGonder'
import { useAileStore } from '@/stores/aile'

/**
 * Ekstre PDF'i yükleme. Kredi kartı: kart seçilir, PDF okuyucu satırları taslak çıkarır; yapay zekâ açıksa
 * kullanıcının açık onayıyla PDF ayrıca yapay zekâyla okunur (arka planda). Banka hesabı: yalnızca yapay zekâ
 * analizi ve önizleme (Bütçe'ye aktarılmaz), onay zorunlu. Onaylanmadan hiçbir şey rapora girmez.
 */
const props = defineProps<{ kartId?: number | null; baslangicTuru?: 'kart' | 'banka' }>()
const gorunur = defineModel<boolean>('visible', { default: false })

const aile = useAileStore()
const router = useRouter()
const toast = useToast()
const { hatalar, gonderiliyor, gonder } = useFormGonder()
const tur = ref<'kart' | 'banka'>('kart')
const secilenKart = ref<number | null>(null)
const dosya = ref<File | null>(null)
const aiOnay = ref(false)

const yetenek = computed(() => aile.hane?.yetenekler)
const aiAcik = computed(() => yetenek.value?.ai_acik ?? false)
const TURLER = computed(() => [
  { deger: 'kart', etiket: 'Kredi kartı' },
  { deger: 'banka', etiket: 'Banka hesabı (önizleme)', kapali: !aiAcik.value },
])

watch(gorunur, async (acik) => {
  if (!acik) return
  hatalar.value = {}
  dosya.value = null
  aiOnay.value = false
  await aile.yukle()
  tur.value = props.baslangicTuru === 'banka' && aiAcik.value ? 'banka' : 'kart'
  secilenKart.value = props.kartId ?? (aile.kartlar.length === 1 ? aile.kartlar[0]!.id : null)
})

function secildi(olay: Event): void {
  dosya.value = (olay.target as HTMLInputElement).files?.[0] ?? null
}

const gonderilebilir = computed(
  () => !!dosya.value && (tur.value === 'kart' ? !!secilenKart.value : aiOnay.value),
)

async function yukle(): Promise<void> {
  if (!gonderilebilir.value) return
  if (tur.value === 'banka') {
    const sonuc = await gonder(() => aileApi.hesapEkstresiYukle(dosya.value!))
    if (sonuc) {
      toast.add({ severity: 'success', summary: sonuc.message, life: 4000 })
      gorunur.value = false
      await router.push({ name: 'aile-hesap-ekstresi', params: { id: sonuc.hesap_ekstresi.id } })
    }

    return
  }
  const sonuc = await gonder(() =>
    aileApi.ekstreYukle(secilenKart.value!, dosya.value!, aiAcik.value && aiOnay.value),
  )
  if (sonuc) {
    toast.add({ severity: 'success', summary: sonuc.message, life: 4000 })
    gorunur.value = false
    await router.push({ name: 'aile-ekstre', params: { id: sonuc.ekstre.id } })
  }
}
</script>

<template>
  <UygulamaPenceresi v-model:visible="gorunur" baslik="Ekstre yükle" :genislik="32">
    <form class="flex flex-col gap-4" @submit.prevent="yukle">
      <SelectButton
        v-model="tur"
        :options="TURLER"
        option-label="etiket"
        option-value="deger"
        option-disabled="kapali"
        :allow-empty="false"
        aria-label="Ekstre türü"
        class="w-full [&>.p-togglebutton]:flex-1"
      />
      <template v-if="tur === 'kart'">
        <Message v-if="!aile.kartlar.length" severity="warn" size="small"
          >Önce bir kart ekleyin.</Message
        >
        <FormAlani etiket="Kart" for="ekstre-kart" :hata="hatalar.kart_id" zorunlu>
          <Select
            id="ekstre-kart"
            v-model="secilenKart"
            :options="aile.kartlar"
            option-label="ad"
            option-value="id"
            placeholder="Seçin"
          />
        </FormAlani>
      </template>
      <p v-else class="rounded-2xl bg-surface-50 p-3 text-xs text-surface-600">
        Banka hesabı ekstresi (havale, maaş, kira, açılış / kapanış bakiyesi) yapay zekâyla okunur
        ve <strong>yalnızca önizlenir</strong>: işlemler ve bakiye denetimi görünür, Bütçe'ye
        aktarılmaz. PDF analizden sonra silinir.
      </p>
      <FormAlani
        :etiket="`Ekstre (PDF, en çok ${yetenek?.ekstre_max_mb ?? 10} MB)`"
        for="ekstre-dosya"
        :hata="hatalar.dosya"
        zorunlu
      >
        <input
          id="ekstre-dosya"
          type="file"
          accept="application/pdf,.pdf"
          class="block w-full text-sm text-surface-700 file:mr-3 file:rounded-full file:border-0 file:bg-primary-50 file:px-4 file:py-2 file:font-semibold file:text-primary-700"
          @change="secildi"
        />
      </FormAlani>

      <!-- Yapay zekâ: her yüklemede açık onay; banka hesabında zorunlu -->
      <div
        v-if="aiAcik"
        class="rounded-2xl bg-sky-50 p-3 text-xs text-sky-900"
        data-test="yukle-yapay-zeka"
      >
        <label class="flex items-start gap-2">
          <Checkbox v-model="aiOnay" binary input-id="ekstre-ai-onay" class="mt-0.5" />
          <span>
            <strong>{{
              tur === 'kart' ? 'Yapay zekâ ile de oku' : 'Yapay zekâ analizini onaylıyorum'
            }}</strong>
            · PDF dosyası (adınız, adresiniz ve maskeli kart / hesap numaranız dahil) analiz için
            OpenAI'ye gönderilir; dosya OpenAI'de saklanmaz. Sonuç taslaktır, onaylamadan hiçbir şey
            Bütçe'ye yazılmaz.
          </span>
        </label>
        <p v-if="hatalar.yapay_zeka || hatalar.onay" class="mt-1 text-red-700">
          {{ hatalar.yapay_zeka ?? hatalar.onay }}
        </p>
      </div>
      <p class="text-xs text-surface-500">
        <template v-if="tur === 'kart'"
          >PDF yalnızca hanenize açık, özel bir klasörde saklanır; ekstreyi silince silinir. Okunan
          satırları onaylamadan önce PDF ile karşılaştırıp düzeltebilirsiniz.</template
        >
        Şifreli PDF'i şifresiz kaydedip yükleyin.
        <template v-if="!aiAcik">Taranmış (metinsiz) PDF okunamaz: yapay zekâ kapalı.</template>
        <template v-else-if="tur === 'kart'"
          >Taranmış (metinsiz) PDF yalnızca yapay zekâ onayıyla okunur.</template
        >
      </p>
      <div class="flex justify-end gap-2">
        <Button label="Vazgeç" severity="secondary" text @click="gorunur = false" />
        <Button
          type="submit"
          label="Yükle ve oku"
          icon="pi pi-upload"
          :loading="gonderiliyor"
          :disabled="!gonderilebilir"
        />
      </div>
    </form>
  </UygulamaPenceresi>
</template>
