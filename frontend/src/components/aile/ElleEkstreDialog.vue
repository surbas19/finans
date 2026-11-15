<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import FormAlani from '@/components/FormAlani.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import { aileApi } from '@/api/aile'
import { useFormGonder } from '@/composables/useFormGonder'
import { useAileStore } from '@/stores/aile'
import { tarihMetni } from '@/utils/bicim'

/**
 * Dosyasız ekstre: PDF yüklemeden yalnızca kesim tarihi, son ödeme ve dönem borcu girilir. Ekstre onaylı olur ve
 * dönem borcu o ayın kart harcaması olarak tek satır yazılır (kalem dökümü yoktur). Kalemleri görmek için PDF yükleyin.
 */
const props = defineProps<{ kartId?: number | null }>()
const gorunur = defineModel<boolean>('visible', { default: false })

const aile = useAileStore()
const router = useRouter()
const { hatalar, gonderiliyor, gonder } = useFormGonder()

const kart = ref<number | null>(null)
const kesim = ref<Date>(new Date())
const sonOdeme = ref<Date | null>(null)
const borc = ref<number | null>(null)
const asgari = ref<number | null>(null)

watch(gorunur, (acik) => {
  if (!acik) return
  hatalar.value = {}
  kart.value = props.kartId ?? (aile.kartlar.length === 1 ? aile.kartlar[0]!.id : null)
  kesim.value = new Date()
  sonOdeme.value = null
  borc.value = null
  asgari.value = null
})

/** Kartın son ödeme günü biliniyorsa kesimden sonraki ilk o günü önerir */
watch([kart, kesim], () => {
  const k = aile.kartlar.find((x) => x.id === kart.value)
  if (!k?.son_odeme_gunu || sonOdeme.value) return
  const d = new Date(kesim.value.getFullYear(), kesim.value.getMonth(), k.son_odeme_gunu)
  if (d <= kesim.value) d.setMonth(d.getMonth() + 1)
  sonOdeme.value = d
})

async function kaydet(): Promise<void> {
  const sonuc = await gonder(
    () =>
      aileApi.ekstreElle({
        kart_id: kart.value,
        kesim_tarihi: tarihMetni(kesim.value),
        son_odeme_tarihi: sonOdeme.value ? tarihMetni(sonOdeme.value) : '',
        donem_borcu: borc.value,
        asgari_odeme: asgari.value,
      }),
    'Ekstre tutarla eklendi',
  )
  if (sonuc) {
    gorunur.value = false
    await router.push({ name: 'aile-ekstre', params: { id: sonuc.ekstre.id } })
  }
}
</script>

<template>
  <UygulamaPenceresi v-model:visible="gorunur" baslik="Ekstreyi tutarla gir" :genislik="32">
    <form class="flex flex-col gap-4" @submit.prevent="kaydet">
      <p class="text-xs text-surface-500">
        PDF yüklemeden yalnızca tutarı girin. Dönem borcu o ayın kart harcaması olarak tek satır
        sayılır; kalem kalem görmek isterseniz bunun yerine PDF yükleyin.
      </p>
      <FormAlani etiket="Kart" for="elle-kart" :hata="hatalar.kart_id" zorunlu>
        <Select
          id="elle-kart"
          v-model="kart"
          :options="aile.kartlar"
          option-label="ad"
          option-value="id"
          placeholder="Seçin"
        />
      </FormAlani>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormAlani
          etiket="Hesap kesim tarihi"
          for="elle-kesim"
          :hata="hatalar.kesim_tarihi"
          zorunlu
        >
          <DatePicker
            v-model="kesim"
            input-id="elle-kesim"
            date-format="dd.mm.yy"
            :max-date="new Date()"
            show-icon
            icon-display="input"
          />
        </FormAlani>
        <FormAlani
          etiket="Son ödeme tarihi"
          for="elle-son-odeme"
          :hata="hatalar.son_odeme_tarihi"
          zorunlu
        >
          <DatePicker
            v-model="sonOdeme"
            input-id="elle-son-odeme"
            date-format="dd.mm.yy"
            show-icon
            icon-display="input"
          />
        </FormAlani>
      </div>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormAlani etiket="Dönem borcu (TL)" for="elle-borc" :hata="hatalar.donem_borcu" zorunlu>
          <InputNumber
            v-model="borc"
            input-id="elle-borc"
            locale="tr-TR"
            :min-fraction-digits="0"
            :max-fraction-digits="2"
            :min="0"
            prefix="₺"
            inputmode="decimal"
          />
        </FormAlani>
        <FormAlani
          etiket="Asgari ödeme (TL)"
          for="elle-asgari"
          :hata="hatalar.asgari_odeme"
          ipucu="Boşsa kartın oranıyla hesaplanır"
        >
          <InputNumber
            v-model="asgari"
            input-id="elle-asgari"
            locale="tr-TR"
            :min-fraction-digits="0"
            :max-fraction-digits="2"
            :min="0"
            prefix="₺"
            inputmode="decimal"
          />
        </FormAlani>
      </div>
      <div class="flex justify-end gap-2">
        <Button label="Vazgeç" severity="secondary" text @click="gorunur = false" />
        <Button type="submit" label="Ekle" icon="pi pi-check" :loading="gonderiliyor" />
      </div>
    </form>
  </UygulamaPenceresi>
</template>
