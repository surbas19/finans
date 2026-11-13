<script setup lang="ts">
import { ref, watch } from 'vue'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Select from 'primevue/select'
import ToggleSwitch from 'primevue/toggleswitch'
import FormAlani from '@/components/FormAlani.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import { aileApi, type Kart } from '@/api/aile'
import { useFormGonder } from '@/composables/useFormGonder'
import { useAileStore } from '@/stores/aile'

/**
 * Kredi kartı: banka, görünen ad, sahibi, kesim ve son ödeme günü. Kart numarası, son 4 hane ve CVV istenmez.
 */
const props = defineProps<{ kart?: Kart | null }>()
const gorunur = defineModel<boolean>('visible', { default: false })
const emit = defineEmits<{ kaydedildi: [kart: Kart] }>()

const aile = useAileStore()
const { hatalar, gonderiliyor, gonder } = useFormGonder()
const form = ref<Partial<Kart>>({})
const yeniBanka = ref('')

watch(gorunur, (acik) => {
  if (!acik) return
  hatalar.value = {}
  yeniBanka.value = ''
  const k = props.kart
  form.value = k
    ? { ...k }
    : {
        banka_id: aile.bankalar[0]?.id,
        sahip_uye_id: aile.hane?.ben_uye_id ?? undefined,
        ad: '',
        kesim_gunu: null,
        son_odeme_gunu: null,
        aktif: true,
      }
})

async function bankaEkle(): Promise<void> {
  const banka = await gonder(() => aileApi.bankaEkle(yeniBanka.value.trim()), 'Banka eklendi')
  if (banka) {
    await aile.yukle(true)
    form.value.banka_id = banka.id
    yeniBanka.value = ''
  }
}

async function kaydet(): Promise<void> {
  const sonuc = await gonder(
    () =>
      props.kart ? aileApi.kartGuncelle(props.kart.id, form.value) : aileApi.kartEkle(form.value),
    'Kart kaydedildi',
  )
  if (sonuc) {
    await aile.yukle(true)
    gorunur.value = false
    emit('kaydedildi', sonuc.kart)
  }
}
</script>

<template>
  <UygulamaPenceresi
    v-model:visible="gorunur"
    :baslik="kart ? 'Kartı düzenle' : 'Kart ekle'"
    :genislik="30"
  >
    <form class="flex flex-col gap-4" @submit.prevent="kaydet">
      <Message severity="secondary" size="small">
        Kart numarası, son 4 hane ya da CVV istenmez; yalnızca tanıyacağınız bir ad verin.
      </Message>
      <FormAlani etiket="Banka" for="kart-banka" :hata="hatalar.banka_id" zorunlu>
        <Select
          v-if="aile.bankalar.length"
          id="kart-banka"
          v-model="form.banka_id"
          :options="aile.bankalar"
          option-label="ad"
          option-value="id"
          placeholder="Seçin"
        />
      </FormAlani>
      <div class="flex gap-2">
        <InputText
          v-model="yeniBanka"
          :placeholder="
            aile.bankalar.length ? 'Listede yoksa yeni banka' : 'Banka adı (ör. Garanti BBVA)'
          "
          class="min-w-0 flex-1"
          maxlength="60"
          aria-label="Yeni banka adı"
        />
        <Button
          icon="pi pi-plus"
          severity="secondary"
          outlined
          aria-label="Bankayı ekle"
          :disabled="yeniBanka.trim().length < 2"
          @click="bankaEkle"
        />
      </div>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormAlani
          etiket="Kartın adı"
          for="kart-ad"
          :hata="hatalar.ad"
          ipucu='ör. "Bonus - Ayşe"'
          zorunlu
        >
          <InputText id="kart-ad" v-model="form.ad" maxlength="60" />
        </FormAlani>
        <FormAlani etiket="Kart sahibi" for="kart-sahip" :hata="hatalar.sahip_uye_id" zorunlu>
          <Select
            id="kart-sahip"
            v-model="form.sahip_uye_id"
            :options="aile.aktifUyeler"
            option-label="ad"
            option-value="id"
            placeholder="Seçin"
          />
        </FormAlani>
      </div>
      <div class="grid grid-cols-2 gap-4">
        <FormAlani
          etiket="Kesim günü"
          for="kart-kesim"
          :hata="hatalar.kesim_gunu"
          ipucu="Ekstrenin kapandığı gün"
        >
          <InputNumber
            v-model="form.kesim_gunu"
            input-id="kart-kesim"
            :min="1"
            :max="31"
            :use-grouping="false"
          />
        </FormAlani>
        <FormAlani etiket="Son ödeme günü" for="kart-odeme" :hata="hatalar.son_odeme_gunu">
          <InputNumber
            v-model="form.son_odeme_gunu"
            input-id="kart-odeme"
            :min="1"
            :max="31"
            :use-grouping="false"
          />
        </FormAlani>
      </div>
      <label v-if="kart" class="flex items-center gap-2 text-sm text-surface-700">
        <ToggleSwitch v-model="form.aktif" /> Kullanımda
      </label>
      <div class="flex justify-end gap-2">
        <Button label="Vazgeç" severity="secondary" text @click="gorunur = false" />
        <Button type="submit" label="Kaydet" icon="pi pi-check" :loading="gonderiliyor" />
      </div>
    </form>
  </UygulamaPenceresi>
</template>
