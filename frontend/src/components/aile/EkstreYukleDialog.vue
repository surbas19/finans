<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Message from 'primevue/message'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import Textarea from 'primevue/textarea'
import { useToast } from 'primevue/usetoast'
import FormAlani from '@/components/FormAlani.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import { aileApi } from '@/api/aile'
import { dogrulamaListesi } from '@/api/http'
import { useFormGonder } from '@/composables/useFormGonder'
import { useAileStore } from '@/stores/aile'

type Tur = 'kart' | 'yapistir' | 'banka'

/**
 * Ekstre ekleme. Kredi kartı: kart seçilir, PDF okuyucu satırları taslak çıkarır; yapay zekâ açıksa kullanıcının açık
 * onayıyla PDF ayrıca yapay zekâyla okunur (arka planda). Dış yapay zekâ (JSON): kullanıcı PDF'i kendi seçtiği sohbet
 * yapay zekâsına (ChatGPT / Claude / Gemini) kendisi yükler, burada kopyalanan istemle standart JSON'u ürettirir ve
 * yapıştırır; sunucuda anahtar gerekmez, PDF isteğe bağlıdır ve hiçbir dış servise gitmez. Banka hesabı: yalnızca
 * yapay zekâ analizi ve önizleme (Bütçe'ye aktarılmaz), onay zorunlu. Onaylanmadan hiçbir şey rapora girmez.
 */
const props = defineProps<{ kartId?: number | null; baslangicTuru?: Tur }>()
const gorunur = defineModel<boolean>('visible', { default: false })

const aile = useAileStore()
const router = useRouter()
const toast = useToast()
const { hatalar, gonderiliyor, gonder } = useFormGonder()
const tur = ref<Tur>('kart')
const secilenKart = ref<number | null>(null)
const dosya = ref<File | null>(null)
const aiOnay = ref(false)

// Dış yapay zekâ (JSON) sekmesi
const istem = ref<string | null>(null)
const istemGoster = ref(false)
const istemYukleniyor = ref(false)
const json = ref('')
const jsonHatalari = ref<string[]>([])

const yetenek = computed(() => aile.hane?.yetenekler)
const aiAcik = computed(() => yetenek.value?.ai_acik ?? false)
const TURLER = computed(() => [
  { deger: 'kart', etiket: 'Kredi kartı' },
  { deger: 'yapistir', etiket: 'Dış yapay zekâ (JSON)' },
  { deger: 'banka', etiket: 'Banka hesabı (önizleme)', kapali: !aiAcik.value },
])

watch(gorunur, async (acik) => {
  if (!acik) return
  hatalar.value = {}
  jsonHatalari.value = []
  dosya.value = null
  aiOnay.value = false
  json.value = ''
  istemGoster.value = false
  await aile.yukle()
  tur.value =
    props.baslangicTuru === 'banka' && !aiAcik.value ? 'kart' : (props.baslangicTuru ?? 'kart')
  secilenKart.value = props.kartId ?? (aile.kartlar.length === 1 ? aile.kartlar[0]!.id : null)
})

function secildi(olay: Event): void {
  dosya.value = (olay.target as HTMLInputElement).files?.[0] ?? null
}

const gonderilebilir = computed(() => {
  if (tur.value === 'yapistir') return !!secilenKart.value && json.value.trim() !== ''
  if (!dosya.value) return false

  return tur.value === 'kart' ? !!secilenKart.value : aiOnay.value
})

async function istemiAl(): Promise<string | null> {
  if (istem.value !== null) return istem.value
  istemYukleniyor.value = true
  try {
    istem.value = (await aileApi.ekstreIstemi()).istem

    return istem.value
  } catch {
    toast.add({ severity: 'error', summary: 'İstem alınamadı', life: 4000 })

    return null
  } finally {
    istemYukleniyor.value = false
  }
}

/** İstemi panoya kopyalar; pano kullanılamazsa metni seçilebilir gösterir */
async function istemiKopyala(): Promise<void> {
  const metin = await istemiAl()
  if (metin === null) return
  try {
    await navigator.clipboard.writeText(metin)
    toast.add({ severity: 'success', summary: 'İstem kopyalandı', life: 2500 })
  } catch {
    istemGoster.value = true
    toast.add({
      severity: 'warn',
      summary: 'Pano kullanılamadı',
      detail: 'İstemi aşağıdaki kutudan seçip kopyalayın.',
      life: 5000,
    })
  }
}

async function istemiGoster(): Promise<void> {
  if ((await istemiAl()) !== null) istemGoster.value = !istemGoster.value
}

async function panodanYapistir(): Promise<void> {
  try {
    const metin = await navigator.clipboard.readText()
    if (metin.trim() === '') {
      toast.add({ severity: 'warn', summary: 'Pano boş', life: 3000 })

      return
    }
    json.value = metin
  } catch {
    toast.add({
      severity: 'warn',
      summary: 'Panoya erişilemedi',
      detail: "JSON'u kutuya elle yapıştırın.",
      life: 5000,
    })
  }
}

async function yukle(): Promise<void> {
  if (!gonderilebilir.value) return
  if (tur.value === 'yapistir') {
    jsonHatalari.value = []
    const sonuc = await gonder(async () => {
      try {
        return await aileApi.ekstreJsonIle(secilenKart.value!, json.value, dosya.value)
      } catch (e) {
        jsonHatalari.value = dogrulamaListesi(e, 'json')
        throw e
      }
    })
    if (sonuc) {
      toast.add({ severity: 'success', summary: sonuc.message, life: 5000 })
      gorunur.value = false
      await router.push({ name: 'aile-ekstre', params: { id: sonuc.ekstre.id } })
    }

    return
  }
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
  <UygulamaPenceresi v-model:visible="gorunur" baslik="Ekstre ekle" :genislik="32">
    <form class="flex flex-col gap-4" @submit.prevent="yukle">
      <SelectButton
        v-model="tur"
        :options="TURLER"
        option-label="etiket"
        option-value="deger"
        option-disabled="kapali"
        :allow-empty="false"
        aria-label="Ekstre türü"
        class="w-full flex-wrap [&>.p-togglebutton]:flex-1"
        data-test="yukle-tur"
      />

      <!-- Dış yapay zekâ: 1) istemi kopyala -->
      <section
        v-if="tur === 'yapistir'"
        class="rounded-2xl bg-sky-50 p-3 text-xs text-sky-900"
        data-test="yapistir-istem"
      >
        <p class="font-semibold">1. İstemi kopyalayın</p>
        <p class="mt-1">
          Ekstre PDF'ini ChatGPT, Claude ya da Gemini gibi bir yapay zekâya
          <strong>siz</strong> yükleyin, bu istemi yapıştırın ve çıkan JSON'u kod bloğunun
          <strong>Kopyala</strong>
          düğmesiyle kopyalayın (metni fareyle seçmeyin; sohbet arayüzü karakterleri bozabilir).
          Farklı bankaların ekstreleri tek biçime iner; sunucumuz yapay zekâya hiçbir şey göndermez.
        </p>
        <div class="mt-2 flex flex-wrap gap-2">
          <Button
            type="button"
            label="İstemi kopyala"
            icon="pi pi-copy"
            size="small"
            :loading="istemYukleniyor"
            data-test="istem-kopyala"
            @click="istemiKopyala"
          />
          <Button
            type="button"
            :label="istemGoster ? 'İstemi gizle' : 'İstemi göster'"
            icon="pi pi-eye"
            size="small"
            severity="secondary"
            text
            data-test="istem-goster"
            @click="istemiGoster"
          />
        </div>
        <Textarea
          v-if="istemGoster && istem !== null"
          :model-value="istem"
          readonly
          rows="8"
          class="mt-2 w-full font-mono text-[11px]"
          aria-label="Yapay zekâ istemi"
          data-test="istem-metni"
          @focus="($event.target as HTMLTextAreaElement).select()"
        />
      </section>

      <template v-if="tur !== 'banka'">
        <Message v-if="!aile.kartlar.length" severity="warn" size="small"
          >Önce bir kart ekleyin.</Message
        >
        <FormAlani
          :etiket="tur === 'yapistir' ? '2. Kart' : 'Kart'"
          for="ekstre-kart"
          :hata="hatalar.kart_id"
          zorunlu
        >
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

      <!-- Dış yapay zekâ: 3) JSON'u yapıştır -->
      <FormAlani
        v-if="tur === 'yapistir'"
        etiket="3. Yapay zekânın verdiği JSON"
        for="ekstre-json"
        :hata="jsonHatalari.length ? undefined : hatalar.json"
        zorunlu
      >
        <Textarea
          id="ekstre-json"
          v-model="json"
          rows="7"
          placeholder='{ "bank_name": "...", "statement_type": "credit_card", ... }'
          class="w-full font-mono text-xs"
          spellcheck="false"
          autocomplete="off"
          data-test="json-kutusu"
        />
        <Button
          type="button"
          label="Panodan yapıştır"
          icon="pi pi-clipboard"
          size="small"
          severity="secondary"
          outlined
          class="mt-2 self-start"
          data-test="panodan-yapistir"
          @click="panodanYapistir"
        />
      </FormAlani>
      <div
        v-if="tur === 'yapistir' && jsonHatalari.length"
        class="rounded-2xl bg-red-50 p-3 text-xs text-red-800"
        role="alert"
        data-test="json-hatalari"
      >
        <p class="font-semibold">JSON kabul edilmedi, hiçbir şey kaydedilmedi:</p>
        <ul class="mt-1 list-disc pl-4 break-words">
          <li v-for="(h, i) in jsonHatalari" :key="i">{{ h }}</li>
        </ul>
        <p class="mt-1">
          Hatayı yapay zekâya aktarıp JSON'u düzelttirebilir, sonra yeniden yapıştırabilirsiniz.
        </p>
      </div>

      <FormAlani
        :etiket="
          tur === 'yapistir'
            ? `4. Ekstre PDF'i (isteğe bağlı, en çok ${yetenek?.ekstre_max_mb ?? 10} MB)`
            : `Ekstre (PDF, en çok ${yetenek?.ekstre_max_mb ?? 10} MB)`
        "
        for="ekstre-dosya"
        :hata="hatalar.dosya"
        :zorunlu="tur !== 'yapistir'"
      >
        <input
          id="ekstre-dosya"
          type="file"
          accept="application/pdf,.pdf"
          class="block w-full text-sm text-surface-700 file:mr-3 file:rounded-full file:border-0 file:bg-primary-50 file:px-4 file:py-2 file:font-semibold file:text-primary-700"
          @change="secildi"
        />
        <p v-if="tur === 'yapistir'" class="mt-1 text-xs text-surface-500">
          Eklerseniz satırların kaynakları PDF'te sunucuda doğrulanır; PDF yalnızca hanenize açık
          özel klasörde kalır, yapay zekâya gönderilmez. Eklemezseniz ekstredeki toplamlarla
          karşılaştırmanız gerekir.
        </p>
      </FormAlani>

      <!-- Yapay zekâ: her yüklemede açık onay; banka hesabında zorunlu -->
      <div
        v-if="aiAcik && tur !== 'yapistir'"
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
      <p v-if="tur === 'yapistir'" class="text-xs text-surface-500">
        JSON'a kişisel veri (ad, adres, kart / hesap numarası) yazılmaması istemde söylenir; yine de
        yapay zekânın yanıtını yapıştırmadan önce gözden geçirin. Yapıştırdığınız JSON yalnızca
        taslak ekstre olur: satırları inceleyip onaylamadan Bütçe'ye hiçbir şey yazılmaz.
      </p>
      <p v-else class="text-xs text-surface-500">
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
          :label="tur === 'yapistir' ? 'Oku' : 'Yükle ve oku'"
          :icon="tur === 'yapistir' ? 'pi pi-check' : 'pi pi-upload'"
          :loading="gonderiliyor"
          :disabled="!gonderilebilir"
          data-test="yukle-gonder"
        />
      </div>
    </form>
  </UygulamaPenceresi>
</template>
