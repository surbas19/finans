<script setup lang="ts">
import { nextTick, ref } from 'vue'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import { aileApi } from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { useAileStore } from '@/stores/aile'

/**
 * Hanenin gelir türlerinden seçim; listede yoksa "Yeni tür" ile yerinde eklenir (ör. Ek ders) ve seçili gelir.
 * Türler Ayarlar → Gelir türleri'nden de yönetilir.
 */
defineProps<{ inputId: string }>()
const secim = defineModel<number | null>({ required: true })
const aile = useAileStore()

const ekleniyor = ref(false)
const yeniAd = ref('')
const hata = ref('')
const kaydediliyor = ref(false)
const girdi = ref<{ $el: HTMLInputElement } | null>(null)

async function ac(): Promise<void> {
  ekleniyor.value = true
  yeniAd.value = ''
  hata.value = ''
  await nextTick()
  girdi.value?.$el.focus()
}

async function ekle(): Promise<void> {
  const ad = yeniAd.value.trim()
  if (ad.length < 2 || kaydediliyor.value) return
  kaydediliyor.value = true
  hata.value = ''
  try {
    const tur = await aileApi.gelirTuruEkle(ad)
    aile.gelirTurleri.push(tur)
    secim.value = tur.id
    ekleniyor.value = false
  } catch (e) {
    hata.value = hataMesaji(e)
  } finally {
    kaydediliyor.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <div v-if="ekleniyor" class="flex gap-2">
      <InputText
        :id="inputId"
        ref="girdi"
        v-model="yeniAd"
        maxlength="40"
        placeholder="Yeni tür (ör. Ek ders)"
        class="min-w-0 flex-1"
        :invalid="!!hata"
        @keydown.enter.prevent="ekle"
        @keydown.esc.prevent="ekleniyor = false"
      />
      <Button
        type="button"
        icon="pi pi-check"
        aria-label="Türü ekle"
        :loading="kaydediliyor"
        :disabled="yeniAd.trim().length < 2"
        @click="ekle"
      />
      <Button
        type="button"
        icon="pi pi-times"
        severity="secondary"
        text
        aria-label="Vazgeç"
        @click="ekleniyor = false"
      />
    </div>
    <Select
      v-else
      :id="inputId"
      v-model="secim"
      :options="aile.gelirTurleri"
      option-label="ad"
      option-value="id"
      placeholder="Seçin"
    />
    <p v-if="hata" class="text-xs text-red-600">{{ hata }}</p>
    <button
      v-if="!ekleniyor"
      type="button"
      class="self-start text-xs font-semibold text-primary-600"
      @click="ac"
    >
      + Yeni tür
    </button>
  </div>
</template>
