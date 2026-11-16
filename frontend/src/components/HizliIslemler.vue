<script setup lang="ts">
import { ref } from 'vue'
import Button from 'primevue/button'
import Popover from 'primevue/popover'
import { useToast } from 'primevue/usetoast'
import EkstreYukleDialog from '@/components/aile/EkstreYukleDialog.vue'
import GelirFormu from '@/components/aile/GelirFormu.vue'
import GiderFormu from '@/components/aile/GiderFormu.vue'
import TasarrufFormu from '@/components/aile/TasarrufFormu.vue'
import { aileApi, type GelirKaynagi, type GiderKaynagi } from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { useAileStore } from '@/stores/aile'

/**
 * Hızlı işlemler: üst çubuktaki ⚡ düğmesi küçük bir pop-up menü açar; seçilen işlemin formu bulunulan sayfadan
 * bağımsız, hemen açılır. Menü bir listeden üretilir: yeni işlem eklemek için `HIZLI_ISLEMLER`'e bir satır ve
 * aşağıdaki şablona formu eklemek yeter. Kayıttan sonra `kaydedildi` olayı yayılır (açık sayfa yenilenir).
 */
export type HizliIslemAdi = 'nakit-harcama' | 'tasarruf' | 'ekstre-yukle' | 'gelir-ekle'

export interface HizliIslem {
  ad: HizliIslemAdi
  etiket: string
  aciklama: string
  ikon: string
}

const HIZLI_ISLEMLER: HizliIslem[] = [
  {
    ad: 'nakit-harcama',
    etiket: 'Nakit harcama',
    aciklama: 'Kartsız gideri hemen yaz',
    ikon: 'pi pi-wallet',
  },
  {
    ad: 'tasarruf',
    etiket: 'Tasarruf',
    aciklama: 'Altın, döviz, hisse alımı',
    ikon: 'pi pi-money-bill',
  },
  {
    ad: 'ekstre-yukle',
    etiket: 'Ekstre yükle',
    aciklama: 'PDF ya da yapay zekâ JSON’u',
    ikon: 'pi pi-upload',
  },
  {
    ad: 'gelir-ekle',
    etiket: 'Gelir ekle',
    aciklama: 'Maaş, ek ders ve diğerleri',
    ikon: 'pi pi-plus-circle',
  },
]

const emit = defineEmits<{ kaydedildi: [] }>()

const aile = useAileStore()
const toast = useToast()
const menu = ref<InstanceType<typeof Popover> | null>(null)

const giderAcik = ref(false)
const tasarrufAcik = ref(false)
const ekstreAcik = ref(false)
const gelirAcik = ref(false)
const giderKaynaklari = ref<GiderKaynagi[]>([])
const gelirKaynaklari = ref<GelirKaynagi[]>([])
const yukleniyor = ref<HizliIslemAdi | null>(null)

function ac(olay: Event): void {
  menu.value?.toggle(olay)
}

/** Seçilen işlemin formu: gereken veri yüklenir (önbellekli), sonra pencere açılır */
async function sec(islem: HizliIslem): Promise<void> {
  menu.value?.hide()
  yukleniyor.value = islem.ad
  try {
    await aile.yukle()
    if (islem.ad === 'nakit-harcama') {
      giderKaynaklari.value = await aileApi.giderKaynaklari()
      giderAcik.value = true
    } else if (islem.ad === 'gelir-ekle') {
      gelirKaynaklari.value = await aileApi.kaynaklar()
      gelirAcik.value = true
    } else if (islem.ad === 'tasarruf') {
      tasarrufAcik.value = true
    } else {
      ekstreAcik.value = true
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Açılamadı', detail: hataMesaji(e), life: 5000 })
  } finally {
    yukleniyor.value = null
  }
}
</script>

<template>
  <Button
    icon="pi pi-bolt"
    text
    rounded
    aria-label="Hızlı işlemler"
    title="Hızlı işlemler"
    aria-haspopup="true"
    data-test="hizli-dugme"
    @click="ac"
  />
  <Popover ref="menu" class="hizli-menu" data-test="hizli-menu">
    <div class="flex w-60 flex-col gap-0.5" role="menu" aria-label="Hızlı işlemler">
      <p
        class="px-2 pt-0.5 pb-1.5 text-[11px] font-bold tracking-widest text-surface-500 uppercase"
      >
        Hızlı işlemler
      </p>
      <button
        v-for="i in HIZLI_ISLEMLER"
        :key="i.ad"
        type="button"
        role="menuitem"
        class="flex min-h-12 items-center gap-3 rounded-xl px-2 py-1.5 text-left transition-colors hover:bg-primary-50 focus-visible:outline-2 focus-visible:outline-primary-500 active:scale-[0.98] disabled:opacity-60"
        :disabled="yukleniyor !== null"
        :data-test="`hizli-${i.ad}`"
        @click="sec(i)"
      >
        <span
          class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-700"
        >
          <i :class="[i.ikon, yukleniyor === i.ad ? 'pi pi-spin pi-spinner' : '']" />
        </span>
        <span class="min-w-0">
          <span class="block text-sm font-bold text-surface-900">{{ i.etiket }}</span>
          <span class="block text-xs leading-tight text-surface-500">{{ i.aciklama }}</span>
        </span>
      </button>
    </div>
  </Popover>

  <GiderFormu
    v-model:visible="giderAcik"
    baslik="Nakit harcama"
    :kaynaklar="giderKaynaklari"
    @kaydedildi="emit('kaydedildi')"
  />
  <TasarrufFormu v-model:visible="tasarrufAcik" @kaydedildi="emit('kaydedildi')" />
  <EkstreYukleDialog v-model:visible="ekstreAcik" />
  <GelirFormu
    v-model:visible="gelirAcik"
    :kaynaklar="gelirKaynaklari"
    @kaydedildi="emit('kaydedildi')"
  />
</template>
