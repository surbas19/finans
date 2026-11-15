<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import InputNumber from 'primevue/inputnumber'
import Message from 'primevue/message'
import Select from 'primevue/select'
import Textarea from 'primevue/textarea'
import FormAlani from '@/components/FormAlani.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import VarlikAdi from '@/components/VarlikAdi.vue'
import { finansApi, type HesapOzeti, type Pozisyon } from '@/api/finans'
import { useFormGonder } from '@/composables/useFormGonder'
import { miktar as miktarMetni, tarihMetni } from '@/utils/bicim'

/**
 * Yatırımı başka hesaba aktarır (kısmen ya da tamamen). Kaynakta satış, hedefte alış yazılır; maliyet taşınır,
 * toplam değer ile kâr / zarar değişmez.
 */
const props = defineProps<{ pozisyon: Pozisyon | null }>()
const gorunur = defineModel<boolean>('visible', { default: false })
const emit = defineEmits<{ aktarildi: [] }>()

const { hatalar, gonderiliyor, gonder } = useFormGonder()
const hesaplar = ref<HesapOzeti[]>([])
const hedefId = ref<number | null>(null)
const miktar = ref<number | null>(null)
const tarihSecimi = ref<Date>(new Date())
const not = ref<string | null>(null)

const hedefler = computed(() => hesaplar.value.filter((h) => h.id !== props.pozisyon?.hesap_id))
const kaynakAdi = computed(
  () => hesaplar.value.find((h) => h.id === props.pozisyon?.hesap_id)?.ad ?? '',
)

watch(gorunur, async (acik) => {
  if (!acik || !props.pozisyon) return
  hatalar.value = {}
  hedefId.value = null
  miktar.value = props.pozisyon.miktar
  tarihSecimi.value = new Date()
  not.value = null
  hesaplar.value = (await finansApi.hesaplar()).hesaplar
  if (hedefler.value.length === 1) hedefId.value = hedefler.value[0]!.id
})

async function kaydet(): Promise<void> {
  const p = props.pozisyon
  if (!p || p.hesap_id === null || hedefId.value === null || miktar.value === null) return
  const sonuc = await gonder(
    () =>
      finansApi.pozisyonAktar(p.hesap_id!, p.varlik.id, {
        hedef_hesap_id: hedefId.value!,
        miktar: miktar.value!,
        tarih: tarihMetni(tarihSecimi.value),
        not: not.value,
      }),
    'Aktarıldı',
  )
  if (sonuc) {
    gorunur.value = false
    emit('aktarildi')
  }
}
</script>

<template>
  <UygulamaPenceresi v-model:visible="gorunur" baslik="Başka hesaba aktar" :genislik="30">
    <form v-if="pozisyon" class="flex flex-col gap-4" @submit.prevent="kaydet">
      <p class="rounded-2xl bg-surface-50 p-3 text-sm">
        <VarlikAdi :kod="pozisyon.varlik.kod" :ad="pozisyon.varlik.ad" />
        <span class="block text-xs text-surface-500">
          {{ kaynakAdi }} hesabında {{ miktarMetni(pozisyon.miktar) }} {{ pozisyon.varlik.birim }}
        </span>
      </p>
      <Message v-if="hesaplar.length > 0 && hedefler.length === 0" severity="warn">
        Aktarmak için başka bir hesap gerekir (Hesaplar → Hesap ekle).
      </Message>
      <FormAlani etiket="Hangi hesaba" for="akt-hedef" :hata="hatalar.hedef_hesap_id" zorunlu>
        <Select
          id="akt-hedef"
          v-model="hedefId"
          :options="hedefler"
          option-label="ad"
          option-value="id"
          placeholder="Seçin"
          data-test="aktar-hedef"
        />
      </FormAlani>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormAlani
          :etiket="`Miktar (${pozisyon.varlik.birim})`"
          for="akt-miktar"
          :hata="hatalar.miktar"
          zorunlu
        >
          <InputNumber
            v-model="miktar"
            input-id="akt-miktar"
            locale="tr-TR"
            :min-fraction-digits="0"
            :max-fraction-digits="8"
            :min="0"
            :max="pozisyon.miktar"
            inputmode="decimal"
            fluid
          />
        </FormAlani>
        <FormAlani etiket="Tarih" for="akt-tarih" :hata="hatalar.tarih" zorunlu>
          <DatePicker
            v-model="tarihSecimi"
            input-id="akt-tarih"
            date-format="dd.mm.yy"
            :max-date="new Date()"
            show-icon
            icon-display="input"
          />
        </FormAlani>
      </div>
      <FormAlani etiket="Not" for="akt-not" :hata="hatalar.not">
        <Textarea id="akt-not" v-model="not" rows="2" maxlength="200" auto-resize />
      </FormAlani>
      <p class="text-xs text-surface-500">
        Kaynakta satış, hedefte alış yazılır; maliyet taşınır, toplam değer ve kâr / zarar değişmez.
      </p>
      <div class="flex justify-end gap-2">
        <Button label="Vazgeç" severity="secondary" text @click="gorunur = false" />
        <Button
          type="submit"
          label="Aktar"
          icon="pi pi-arrow-right-arrow-left"
          :loading="gonderiliyor"
          :disabled="hedefler.length === 0"
          data-test="aktar-kaydet"
        />
      </div>
    </form>
  </UygulamaPenceresi>
</template>
