<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import SelectButton from 'primevue/selectbutton'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import FormAlani from '@/components/FormAlani.vue'
import { finansApi, type Varlik, type VarlikTuru } from '@/api/finans'
import { useFormGonder } from '@/composables/useFormGonder'
import { useKatalogStore } from '@/stores/katalog'

/**
 * Katalogda olmayan hisse / kripto / fon: hisse ve kripto kaynaktan doğrulanır; fonun fiyatı elle girilir.
 */
const gorunur = defineModel<boolean>('visible', { default: false })
const emit = defineEmits<{ eklendi: [varlik: Varlik] }>()

const TURLER: { deger: VarlikTuru; etiket: string }[] = [
  { deger: 'hisse', etiket: 'Hisse' },
  { deger: 'kripto', etiket: 'Kripto' },
  { deger: 'fon', etiket: 'Fon' },
]
const tur = ref<VarlikTuru>('hisse')
const kod = ref('')
const ad = ref('')
const { hatalar, gonderiliyor, gonder } = useFormGonder()
const katalog = useKatalogStore()

watch(gorunur, (acik) => {
  if (acik) {
    kod.value = ''
    ad.value = ''
    hatalar.value = {}
  }
})

const ipucu = computed(
  () =>
    ({
      hisse: 'Borsa İstanbul kodu: THYAO, ASELS, BIMAS… Fiyat ~15 dakika gecikmeli gelir.',
      kripto: 'Binance TRY paritesi olan kod: BTC, ETH, SOL, AVAX…',
      fon: 'TEFAS fon kodu (ör. AFT). Fon fiyatı otomatik gelmez; yönetici elle girer.',
    })[tur.value as 'hisse' | 'kripto' | 'fon'] ?? '',
)

async function ekle(): Promise<void> {
  const sonuc = await gonder(() =>
    finansApi.varlikEkle({
      tur: tur.value,
      kod: kod.value.trim(),
      ad: tur.value === 'fon' ? ad.value : null,
    }),
  )
  if (sonuc) {
    katalog.ekle(sonuc.varlik)
    gorunur.value = false
    emit('eklendi', sonuc.varlik)
  }
}
</script>

<template>
  <UygulamaPenceresi v-model:visible="gorunur" baslik="Kodla varlık ekle" :genislik="28">
    <form class="flex flex-col gap-4" @submit.prevent="ekle">
      <SelectButton
        v-model="tur"
        :options="TURLER"
        option-label="etiket"
        option-value="deger"
        :allow-empty="false"
        aria-label="Varlık türü"
        class="w-full sm:w-auto [&>.p-togglebutton]:flex-1"
      />
      <FormAlani etiket="Kod" for="varlik-kod" :hata="hatalar.kod" :ipucu="ipucu" zorunlu>
        <InputText
          id="varlik-kod"
          v-model="kod"
          maxlength="12"
          autocapitalize="characters"
          autocomplete="off"
          class="uppercase"
          autofocus
        />
      </FormAlani>
      <FormAlani v-if="tur === 'fon'" etiket="Fonun adı" for="varlik-ad" :hata="hatalar.ad" zorunlu>
        <InputText id="varlik-ad" v-model="ad" maxlength="120" />
      </FormAlani>
      <Message v-if="hatalar.tur" severity="error" size="small">{{ hatalar.tur }}</Message>
      <div class="flex justify-end gap-2">
        <Button label="Vazgeç" severity="secondary" text @click="gorunur = false" />
        <Button
          type="submit"
          label="Ekle"
          icon="pi pi-plus"
          :loading="gonderiliyor"
          :disabled="kod.trim().length < 2"
        />
      </div>
    </form>
  </UygulamaPenceresi>
</template>
