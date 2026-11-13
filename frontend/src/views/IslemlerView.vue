<script setup lang="ts">
import { onMounted, ref } from 'vue'
import Button from 'primevue/button'
import Select from 'primevue/select'
import Skeleton from 'primevue/skeleton'
import BosDurum from '@/components/BosDurum.vue'
import IslemFormu from '@/components/IslemFormu.vue'
import IslemListesi from '@/components/IslemListesi.vue'
import SayfaBasligi from '@/components/SayfaBasligi.vue'
import { finansApi, type HesapOzeti, type Islem } from '@/api/finans'
import { useSonIstek } from '@/composables/useSonIstek'

/**
 * Bütün işlemler (en yeni üstte), hesaba göre süzülebilir.
 */
const islemler = ref<Islem[] | null>(null)
const hesaplar = ref<HesapOzeti[]>([])
const hesapId = ref<number | null>(null)
const formAcik = ref(false)
const duzenlenen = ref<Islem | null>(null)
const sonIstek = useSonIstek()

async function yukle(): Promise<void> {
  const veri = await sonIstek(() =>
    finansApi.islemler(hesapId.value ? { hesap_id: hesapId.value } : {}),
  )
  if (veri !== undefined) islemler.value = veri
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
    <BosDurum v-else-if="islemler.length === 0" baslik="Henüz işlem yok" ikon="pi pi-list">
      <Button label="İşlem ekle" icon="pi pi-plus" @click="ekle" />
    </BosDurum>
    <section v-else class="kart px-3 sm:px-5">
      <IslemListesi :islemler="islemler" hesap-goster @duzenle="duzenle" @silindi="yukle" />
    </section>

    <IslemFormu
      v-model:visible="formAcik"
      :islem="duzenlenen"
      :varsayilan="hesapId ? { hesap_id: hesapId } : undefined"
      @kaydedildi="yukle"
    />
  </div>
</template>
