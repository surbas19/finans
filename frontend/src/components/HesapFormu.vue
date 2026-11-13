<script setup lang="ts">
import { ref, watch } from 'vue'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Textarea from 'primevue/textarea'
import FormAlani from '@/components/FormAlani.vue'
import {
  finansApi,
  HESAP_RENKLERI,
  HESAP_TURLERI,
  type HesapGirdisi,
  type HesapOzeti,
  type HesapTuru,
} from '@/api/finans'
import { useFormGonder } from '@/composables/useFormGonder'

/**
 * Hesap / konum ekleme ve düzenleme ("Yatırım hesabım", "Eşimin hesabı", "Banka kasası"…). Hesap numarası istenmez.
 */
const props = defineProps<{ hesap?: HesapOzeti | null }>()
const gorunur = defineModel<boolean>('visible', { default: false })
const emit = defineEmits<{ kaydedildi: [] }>()

const turler = (Object.keys(HESAP_TURLERI) as HesapTuru[]).map((t) => ({
  deger: t,
  etiket: HESAP_TURLERI[t].etiket,
}))
const form = ref<HesapGirdisi>({ ad: '', tur: 'araci_kurum', renk: 'teal', not: null })
const { hatalar, gonderiliyor, gonder } = useFormGonder()

watch(gorunur, (acik) => {
  if (!acik) return
  hatalar.value = {}
  form.value = props.hesap
    ? { ad: props.hesap.ad, tur: props.hesap.tur, renk: props.hesap.renk, not: props.hesap.not }
    : { ad: '', tur: 'araci_kurum', renk: 'teal', not: null }
})

async function kaydet(): Promise<void> {
  const sonuc = await gonder(
    () =>
      props.hesap
        ? finansApi.hesapGuncelle(props.hesap.id, form.value)
        : finansApi.hesapEkle(form.value),
    props.hesap ? 'Hesap güncellendi' : 'Hesap eklendi',
  )
  if (sonuc) {
    gorunur.value = false
    emit('kaydedildi')
  }
}
</script>

<template>
  <Dialog
    v-model:visible="gorunur"
    modal
    :header="hesap ? 'Hesabı düzenle' : 'Hesap ekle'"
    class="w-[min(30rem,calc(100vw-2rem))]"
  >
    <form class="flex flex-col gap-4" @submit.prevent="kaydet">
      <FormAlani
        etiket="Hesap adı"
        for="hesap-ad"
        :hata="hatalar.ad"
        ipucu="Ör. Yatırım hesabım, Eşimin hesabı, Banka kasası"
        zorunlu
      >
        <InputText id="hesap-ad" v-model="form.ad" maxlength="60" autofocus />
      </FormAlani>
      <FormAlani etiket="Türü" for="hesap-tur" :hata="hatalar.tur" zorunlu>
        <Select
          id="hesap-tur"
          v-model="form.tur"
          :options="turler"
          option-label="etiket"
          option-value="deger"
        />
      </FormAlani>
      <FormAlani etiket="Renk" :hata="hatalar.renk">
        <div class="flex flex-wrap gap-2">
          <button
            v-for="r in HESAP_RENKLERI"
            :key="r"
            type="button"
            class="h-8 w-8 rounded-full border-2 transition-transform"
            :class="form.renk === r ? 'scale-110 border-surface-900' : 'border-transparent'"
            :style="{ background: `var(--p-${r}-500)` }"
            :aria-label="`Renk ${r}`"
            :aria-pressed="form.renk === r"
            @click="form.renk = r"
          />
        </div>
      </FormAlani>
      <FormAlani
        etiket="Not"
        for="hesap-not"
        :hata="hatalar.not"
        ipucu="Hesap / kart numarası yazmayın."
      >
        <Textarea id="hesap-not" v-model="form.not" rows="2" maxlength="500" auto-resize />
      </FormAlani>
      <div class="flex justify-end gap-2">
        <Button label="Vazgeç" severity="secondary" text @click="gorunur = false" />
        <Button type="submit" label="Kaydet" icon="pi pi-check" :loading="gonderiliyor" />
      </div>
    </form>
  </Dialog>
</template>
