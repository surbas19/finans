<script setup lang="ts">
import { onMounted, ref } from 'vue'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import BosDurum from '@/components/BosDurum.vue'
import DegisimEtiketi from '@/components/DegisimEtiketi.vue'
import HesapFormu from '@/components/HesapFormu.vue'
import SayfaBasligi from '@/components/SayfaBasligi.vue'
import { finansApi, HESAP_TURLERI, type HesapOzeti } from '@/api/finans'
import { useSonIstek } from '@/composables/useSonIstek'
import { tl } from '@/utils/bicim'

const hesaplar = ref<HesapOzeti[] | null>(null)
const formAcik = ref(false)
const sonIstek = useSonIstek()

async function yukle(): Promise<void> {
  const veri = await sonIstek(() => finansApi.hesaplar())
  if (veri !== undefined) hesaplar.value = veri.hesaplar
}

onMounted(yukle)
</script>

<template>
  <div>
    <SayfaBasligi
      baslik="Hesaplar"
      aciklama="Varlıklarınızın durduğu yerler: yatırım hesapları, banka kasası, ev…"
    >
      <template #eylemler>
        <Button label="Hesap ekle" icon="pi pi-plus" @click="formAcik = true" />
      </template>
    </SayfaBasligi>

    <div v-if="hesaplar === null" class="grid gap-3 sm:grid-cols-2">
      <Skeleton v-for="n in 4" :key="n" height="6rem" border-radius="1.5rem" />
    </div>
    <BosDurum
      v-else-if="hesaplar.length === 0"
      baslik="Henüz hesap yok"
      aciklama='Ör. "Yatırım hesabım", "Eşimin hesabı", "Banka kasası". Her hesabın varlıklarını ayrı ve toplu görürsünüz.'
      ikon="pi pi-wallet"
    >
      <Button label="Hesap ekle" icon="pi pi-plus" @click="formAcik = true" />
    </BosDurum>
    <div v-else class="grid gap-3 sm:grid-cols-2">
      <RouterLink
        v-for="h in hesaplar"
        :key="h.id"
        :to="{ name: 'hesap', params: { id: h.id } }"
        class="kart etkilesimli-kart flex flex-col gap-3 p-4 sm:p-5"
      >
        <div class="flex items-center gap-3">
          <span
            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl text-white"
            :style="{ background: `var(--p-${h.renk ?? 'teal'}-500)` }"
          >
            <i :class="HESAP_TURLERI[h.tur].ikon" />
          </span>
          <span class="min-w-0 flex-1">
            <span class="block truncate font-semibold text-surface-900">{{ h.ad }}</span>
            <span class="block truncate text-xs text-surface-500">
              {{ HESAP_TURLERI[h.tur].etiket }} · {{ h.varlik_sayisi }} varlık
            </span>
          </span>
          <i class="pi pi-angle-right text-surface-400" />
        </div>
        <div class="flex items-end justify-between gap-2">
          <span class="text-xl font-bold text-surface-900 tabular-nums">{{ tl(h.deger) }}</span>
          <span class="flex flex-col items-end">
            <DegisimEtiketi :tutar="h.kz" :yuzde="h.kz_yuzde" kucuk />
            <span class="text-xs text-surface-400"
              >bugün <DegisimEtiketi :tutar="h.gunluk_degisim" kucuk
            /></span>
          </span>
        </div>
      </RouterLink>
    </div>

    <HesapFormu v-model:visible="formAcik" @kaydedildi="yukle" />
  </div>
</template>
