<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import Tab from 'primevue/tab'
import TabList from 'primevue/tablist'
import TabPanel from 'primevue/tabpanel'
import TabPanels from 'primevue/tabpanels'
import Tabs from 'primevue/tabs'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import BosDurum from '@/components/BosDurum.vue'
import DagilimKarti from '@/components/DagilimKarti.vue'
import DegisimEtiketi from '@/components/DegisimEtiketi.vue'
import HesapFormu from '@/components/HesapFormu.vue'
import IslemFormu from '@/components/IslemFormu.vue'
import IslemListesi from '@/components/IslemListesi.vue'
import PerformansGrafigi from '@/components/PerformansGrafigi.vue'
import PozisyonListesi from '@/components/PozisyonListesi.vue'
import TopluEkleDialog from '@/components/TopluEkleDialog.vue'
import {
  finansApi,
  HESAP_TURLERI,
  type DagilimDilimi,
  type HesapOzeti,
  type Islem,
  type Pozisyon,
} from '@/api/finans'
import { hataMesaji } from '@/api/http'
import { useSonIstek } from '@/composables/useSonIstek'
import { tl } from '@/utils/bicim'

const props = defineProps<{ id: number }>()
const router = useRouter()
const confirm = useConfirm()
const toast = useToast()

const hesap = ref<HesapOzeti | null>(null)
const pozisyonlar = ref<Pozisyon[]>([])
const dagilim = ref<DagilimDilimi[]>([])
const islemler = ref<Islem[]>([])
/** İşlem eklenince / silinince performans grafiği yenilenir */
const yenile = ref(0)
const bulunamadi = ref(false)
const sekme = ref('varliklar')
const hesapFormuAcik = ref(false)
const islemFormuAcik = ref(false)
const topluAcik = ref(false)
const duzenlenen = ref<Islem | null>(null)
const sonIstek = useSonIstek()

async function yukle(): Promise<void> {
  try {
    const veri = await sonIstek(() => finansApi.hesap(props.id))
    if (veri === undefined) return
    hesap.value = veri.hesap
    pozisyonlar.value = veri.pozisyonlar
    dagilim.value = veri.dagilim
    islemler.value = veri.islemler
  } catch {
    bulunamadi.value = true
  }
}

onMounted(yukle)

function islemDegisti(): void {
  yenile.value++
  void yukle()
}

function islemEkle(): void {
  duzenlenen.value = null
  islemFormuAcik.value = true
}

function islemDuzenle(i: Islem): void {
  duzenlenen.value = i
  islemFormuAcik.value = true
}

function hesabiSil(): void {
  confirm.require({
    header: 'Hesabı sil',
    message: `"${hesap.value?.ad}" ve içindeki ${islemler.value.length} işlem kalıcı olarak silinsin mi?`,
    acceptProps: { label: 'Sil', severity: 'danger' },
    rejectProps: { label: 'Vazgeç', severity: 'secondary', text: true },
    accept: async () => {
      try {
        await finansApi.hesapSil(props.id)
        toast.add({ severity: 'success', summary: 'Hesap silindi', life: 3000 })
        await router.replace({ name: 'hesaplar' })
      } catch (e) {
        toast.add({ severity: 'error', summary: 'Silinemedi', detail: hataMesaji(e), life: 5000 })
      }
    },
  })
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <Message v-if="bulunamadi" severity="error">Hesap bulunamadı.</Message>
    <template v-else>
      <section class="kart p-5 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="flex min-w-0 items-center gap-3">
            <span
              v-if="hesap"
              class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl text-lg text-white"
              :style="{ background: `var(--p-${hesap.renk ?? 'teal'}-500)` }"
            >
              <i :class="HESAP_TURLERI[hesap.tur].ikon" />
            </span>
            <div class="min-w-0">
              <Skeleton v-if="!hesap" width="10rem" height="1.5rem" />
              <h1 v-else class="truncate text-lg font-bold text-surface-900">{{ hesap.ad }}</h1>
              <p v-if="hesap" class="text-xs text-surface-500">
                {{ HESAP_TURLERI[hesap.tur].etiket }}
              </p>
            </div>
          </div>
          <div class="flex gap-1">
            <Button
              icon="pi pi-pencil"
              text
              rounded
              aria-label="Hesabı düzenle"
              :disabled="!hesap"
              @click="hesapFormuAcik = true"
            />
            <Button
              icon="pi pi-trash"
              text
              rounded
              severity="danger"
              aria-label="Hesabı sil"
              :disabled="!hesap"
              @click="hesabiSil"
            />
          </div>
        </div>
        <div v-if="hesap" class="mt-4 flex flex-wrap items-end justify-between gap-3">
          <div>
            <p
              class="text-2xl font-bold tracking-tight text-surface-900 tabular-nums min-[375px]:text-3xl"
            >
              {{ tl(hesap.deger) }}
            </p>
            <p class="mt-1 flex flex-wrap gap-x-3 text-sm text-surface-500">
              <span>K/Z <DegisimEtiketi :tutar="hesap.kz" :yuzde="hesap.kz_yuzde" /></span>
              <span>Bugün <DegisimEtiketi :tutar="hesap.gunluk_degisim" /></span>
            </p>
          </div>
          <div class="flex flex-wrap gap-2">
            <Button
              label="Toplu ekle"
              icon="pi pi-file-import"
              severity="secondary"
              outlined
              @click="topluAcik = true"
            />
            <Button label="İşlem ekle" icon="pi pi-plus" @click="islemEkle" />
          </div>
        </div>
        <p v-if="hesap?.not" class="mt-3 text-sm text-surface-500">{{ hesap.not }}</p>
      </section>

      <section v-if="hesap && islemler.length > 0" class="kart p-4 sm:p-5">
        <h2 class="bolum-etiketi mb-2">Performans</h2>
        <PerformansGrafigi :hesap-id="id" :yenile="yenile" />
      </section>

      <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-5">
        <section
          class="kart p-2 sm:p-4"
          :class="dagilim.length > 0 ? 'lg:col-span-3' : 'lg:col-span-5'"
        >
          <Tabs v-model:value="sekme">
            <TabList>
              <Tab value="varliklar">Varlıklar ({{ pozisyonlar.length }})</Tab>
              <Tab value="islemler">İşlemler ({{ islemler.length }})</Tab>
            </TabList>
            <TabPanels>
              <TabPanel value="varliklar">
                <BosDurum
                  v-if="hesap && pozisyonlar.length === 0"
                  kucuk
                  baslik="Bu hesapta varlık yok"
                  aciklama="Alış ya da elde olan varlığınızı girin."
                  ikon="pi pi-wallet"
                >
                  <Button label="İşlem ekle" icon="pi pi-plus" size="small" @click="islemEkle" />
                  <Button
                    label="Elde olanları toplu ekle"
                    icon="pi pi-file-import"
                    size="small"
                    severity="secondary"
                    outlined
                    @click="topluAcik = true"
                  />
                </BosDurum>
                <PozisyonListesi v-else :pozisyonlar="pozisyonlar" />
              </TabPanel>
              <TabPanel value="islemler">
                <p v-if="islemler.length === 0" class="py-6 text-center text-sm text-surface-500">
                  Henüz işlem yok.
                </p>
                <IslemListesi
                  v-else
                  :islemler="islemler"
                  @duzenle="islemDuzenle"
                  @silindi="islemDegisti"
                />
              </TabPanel>
            </TabPanels>
          </Tabs>
        </section>
        <DagilimKarti
          v-if="hesap && dagilim.length > 0"
          :dagilim="dagilim"
          :toplam-deger="hesap.deger"
          class="lg:col-span-2"
        />
      </div>
    </template>

    <HesapFormu v-model:visible="hesapFormuAcik" :hesap="hesap" @kaydedildi="yukle" />
    <IslemFormu
      v-model:visible="islemFormuAcik"
      :islem="duzenlenen"
      :varsayilan="{ hesap_id: id }"
      @kaydedildi="islemDegisti"
    />
    <TopluEkleDialog v-model:visible="topluAcik" :hesap-id="id" @kaydedildi="islemDegisti" />
  </div>
</template>
