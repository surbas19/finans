<script setup lang="ts">
import { ref, watch } from 'vue'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import { useToast } from 'primevue/usetoast'
import FormAlani from '@/components/FormAlani.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import { finansApi, type TemettuPlani } from '@/api/finans'
import { useFormGonder } from '@/composables/useFormGonder'
import { tl } from '@/utils/bicim'

/**
 * "Yattı": planı gerçek temettüye çevirir. Net tutar hesaplananla dolu gelir; bankanın hesaba yatırdığı tutar farklıysa
 * değiştirilir (fark stopaj olarak yazılır). Hisse satırına temettü + hesaba Nakit (TL) alışı eşleşik yazılır.
 */
const props = defineProps<{ plan: TemettuPlani | null }>()
const gorunur = defineModel<boolean>('visible', { default: false })
const emit = defineEmits<{ kaydedildi: [] }>()

const toast = useToast()
const { hatalar, gonderiliyor, gonder } = useFormGonder()
const net = ref<number | null>(null)

watch(gorunur, (acik) => {
  if (!acik) return
  hatalar.value = {}
  net.value = props.plan?.net ?? null
})

async function kaydet(): Promise<void> {
  if (!props.plan) return
  // Değiştirilmediyse sunucu kendi hesabını yazar
  const ezme = net.value !== null && net.value !== props.plan.net ? net.value : null
  const sonuc = await gonder(() => finansApi.temettuYatti(props.plan!.id, ezme))
  if (sonuc) {
    toast.add({
      severity: 'success',
      summary: 'Temettü kaydedildi',
      detail: sonuc.message,
      life: 4000,
    })
    gorunur.value = false
    emit('kaydedildi')
  }
}
</script>

<template>
  <UygulamaPenceresi v-model:visible="gorunur" baslik="Temettü yattı" :genislik="24">
    <form
      v-if="plan"
      class="flex flex-col gap-4"
      data-test="temettu-yatti-form"
      @submit.prevent="kaydet"
    >
      <p class="text-sm text-surface-600">
        <strong>{{ plan.varlik.kod }}</strong> · {{ plan.lot }} lot · {{ plan.hesap_ad }} hesabına
        yatan net tutarı onaylayın.
      </p>
      <FormAlani
        etiket="Yatan net tutar (TL)"
        for="ty-net"
        :hata="hatalar.net ?? hatalar.tarih ?? hatalar.durum"
        :ipucu="`Hesaplanan: ${tl(plan.net)}`"
      >
        <InputNumber
          v-model="net"
          input-id="ty-net"
          locale="tr-TR"
          :min="0"
          :min-fraction-digits="0"
          :max-fraction-digits="2"
          prefix="₺"
          inputmode="decimal"
          fluid
          data-test="temettu-yatan-net"
        />
      </FormAlani>
      <div class="flex justify-end gap-2">
        <Button label="Vazgeç" severity="secondary" text @click="gorunur = false" />
        <Button
          type="submit"
          label="Kaydet"
          icon="pi pi-check"
          :loading="gonderiliyor"
          :disabled="!net"
          data-test="temettu-yatti-kaydet"
        />
      </div>
    </form>
  </UygulamaPenceresi>
</template>
