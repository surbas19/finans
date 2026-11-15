<script setup lang="ts">
import { onMounted, ref } from 'vue'
import Button from 'primevue/button'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import Select from 'primevue/select'
import Skeleton from 'primevue/skeleton'
import BosDurum from '@/components/BosDurum.vue'
import IslemFormu from '@/components/IslemFormu.vue'
import IslemListesi from '@/components/IslemListesi.vue'
import SayfaBasligi from '@/components/SayfaBasligi.vue'
import TopluEkleDialog from '@/components/TopluEkleDialog.vue'
import ZekatOdemeFormu from '@/components/zekat/ZekatOdemeFormu.vue'
import { aileApi, type ZekatOdemesi } from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { tl } from '@/utils/bicim'
import { finansApi, type HesapOzeti, type Islem } from '@/api/finans'
import { useSonIstek } from '@/composables/useSonIstek'

/**
 * Bütün işlemler (en yeni üstte), hesaba göre süzülebilir.
 */
const islemler = ref<Islem[] | null>(null)
const hesaplar = ref<HesapOzeti[]>([])
const hesapId = ref<number | null>(null)
const formAcik = ref(false)
const topluAcik = ref(false)
const duzenlenen = ref<Islem | null>(null)
const sonIstek = useSonIstek()
const confirm = useConfirm()
const toast = useToast()
const zekatOdemeleri = ref<ZekatOdemesi[]>([])
const zekatFormuAcik = ref(false)

async function yukle(): Promise<void> {
  const veri = await sonIstek(() =>
    finansApi.islemler(hesapId.value ? { hesap_id: hesapId.value } : {}),
  )
  if (veri !== undefined) islemler.value = veri
  await zekatYukle()
}

/** Zekât ödemeleri hane kaydıdır, portföy hesabıyla ilgisizdir: hesap süzgeci seçiliyken gösterilmez. Hata sayfayı bozmaz. */
async function zekatYukle(): Promise<void> {
  if (hesapId.value) {
    zekatOdemeleri.value = []

    return
  }
  try {
    zekatOdemeleri.value = await aileApi.zekatOdemeleri()
  } catch {
    zekatOdemeleri.value = []
  }
}

function zekatSil(o: ZekatOdemesi): void {
  confirm.require({
    header: 'Zekât ödemesini sil',
    message: `${tl(o.tutar)} tutarındaki verilen zekât kaydı silinsin mi?`,
    acceptProps: { label: 'Sil', severity: 'danger' },
    rejectProps: { label: 'Vazgeç', severity: 'secondary', text: true },
    accept: async () => {
      try {
        await aileApi.zekatOdemeSil(o.id)
        toast.add({ severity: 'success', summary: 'Zekât ödemesi silindi', life: 3000 })
        await zekatYukle()
      } catch (e) {
        toast.add({ severity: 'error', summary: 'Silinemedi', detail: hataMesaji(e), life: 6000 })
      }
    },
  })
}

onMounted(async () => {
  void yukle()
  hesaplar.value = (await finansApi.hesaplar()).hesaplar
})

function ekle(): void {
  duzenlenen.value = null
  formAcik.value = true
}

function duzenle(i: Islem): void {
  duzenlenen.value = i
  formAcik.value = true
}
</script>

<template>
  <div>
    <SayfaBasligi baslik="İşlemler" aciklama="Alış, satış ve elde olan kayıtları">
      <template #eylemler>
        <Button
          label="Toplu ekle"
          icon="pi pi-file-import"
          severity="secondary"
          outlined
          @click="topluAcik = true"
        />
        <Button
          label="Zekât ödemesi"
          icon="pi pi-heart"
          severity="secondary"
          outlined
          data-test="zekat-odemesi-ekle"
          @click="zekatFormuAcik = true"
        />
        <Button label="İşlem ekle" icon="pi pi-plus" @click="ekle" />
      </template>
      <template v-if="hesaplar.length > 1" #filtreler>
        <Select
          v-model="hesapId"
          :options="hesaplar"
          option-label="ad"
          option-value="id"
          placeholder="Bütün hesaplar"
          show-clear
          class="w-full sm:w-64"
          @change="yukle"
        />
      </template>
    </SayfaBasligi>

    <div v-if="islemler === null" class="flex flex-col gap-2">
      <Skeleton v-for="n in 6" :key="n" height="3rem" />
    </div>
    <BosDurum
      v-else-if="islemler.length === 0 && zekatOdemeleri.length === 0"
      baslik="Henüz işlem yok"
      ikon="pi pi-list"
    >
      <Button label="İşlem ekle" icon="pi pi-plus" @click="ekle" />
      <Button
        label="Elde olanları toplu ekle"
        icon="pi pi-file-import"
        severity="secondary"
        outlined
        @click="topluAcik = true"
      />
    </BosDurum>
    <section v-else class="kart px-3 sm:px-5">
      <IslemListesi
        :islemler="islemler"
        :zekat-odemeleri="zekatOdemeleri"
        hesap-goster
        @duzenle="duzenle"
        @silindi="yukle"
        @zekat-sil="zekatSil"
      />
    </section>

    <IslemFormu
      v-model:visible="formAcik"
      :islem="duzenlenen"
      :varsayilan="hesapId ? { hesap_id: hesapId } : undefined"
      @kaydedildi="yukle"
    />
    <ZekatOdemeFormu v-model:visible="zekatFormuAcik" :yil-id="null" @kaydedildi="zekatYukle" />
    <TopluEkleDialog v-model:visible="topluAcik" :hesap-id="hesapId" @kaydedildi="yukle" />
  </div>
</template>
