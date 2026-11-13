<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Select from 'primevue/select'
import { useToast } from 'primevue/usetoast'
import FormAlani from '@/components/FormAlani.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import { aileApi } from '@/api/aile'
import { useFormGonder } from '@/composables/useFormGonder'
import { useAileStore } from '@/stores/aile'

/**
 * Ekstre PDF'i yükleme: kart seçilir, dosya sunucuda doğrulanır ve okunur; satırlar taslak olarak inceleme
 * sayfasında açılır (onaylanmadan hiçbir rapora girmez).
 */
const props = defineProps<{ kartId?: number | null }>()
const gorunur = defineModel<boolean>('visible', { default: false })

const aile = useAileStore()
const router = useRouter()
const toast = useToast()
const { hatalar, gonderiliyor, gonder } = useFormGonder()
const secilenKart = ref<number | null>(null)
const dosya = ref<File | null>(null)
const girdi = ref<HTMLInputElement | null>(null)

watch(gorunur, async (acik) => {
  if (!acik) return
  hatalar.value = {}
  dosya.value = null
  await aile.yukle()
  secilenKart.value = props.kartId ?? (aile.kartlar.length === 1 ? aile.kartlar[0]!.id : null)
})

function secildi(olay: Event): void {
  dosya.value = (olay.target as HTMLInputElement).files?.[0] ?? null
}

async function yukle(): Promise<void> {
  if (!secilenKart.value || !dosya.value) return
  const sonuc = await gonder(() => aileApi.ekstreYukle(secilenKart.value!, dosya.value!))
  if (sonuc) {
    toast.add({ severity: 'success', summary: sonuc.message, life: 4000 })
    gorunur.value = false
    await router.push({ name: 'aile-ekstre', params: { id: sonuc.ekstre.id } })
  }
}
</script>

<template>
  <UygulamaPenceresi v-model:visible="gorunur" baslik="Ekstre yükle" :genislik="30">
    <form class="flex flex-col gap-4" @submit.prevent="yukle">
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
      <FormAlani
        etiket="Ekstre (PDF, en çok 10 MB)"
        for="ekstre-dosya"
        :hata="hatalar.dosya"
        zorunlu
      >
        <input
          id="ekstre-dosya"
          ref="girdi"
          type="file"
          accept="application/pdf,.pdf"
          class="block w-full text-sm text-surface-700 file:mr-3 file:rounded-full file:border-0 file:bg-primary-50 file:px-4 file:py-2 file:font-semibold file:text-primary-700"
          @change="secildi"
        />
      </FormAlani>
      <p class="text-xs text-surface-500">
        PDF yalnızca hanenize açık, özel bir klasörde saklanır; ekstreyi silince silinir. Okunan
        satırları onaylamadan önce PDF ile karşılaştırıp düzeltebilirsiniz. Şifreli PDF'i şifresiz
        kaydedip yükleyin. Taranmış (metinsiz) PDF okunamaz; OCR için belge dışarı gönderilmez.
      </p>
      <div class="flex justify-end gap-2">
        <Button label="Vazgeç" severity="secondary" text @click="gorunur = false" />
        <Button
          type="submit"
          label="Yükle ve oku"
          icon="pi pi-upload"
          :loading="gonderiliyor"
          :disabled="!secilenKart || !dosya"
        />
      </div>
    </form>
  </UygulamaPenceresi>
</template>
