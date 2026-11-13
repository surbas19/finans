<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import InputText from 'primevue/inputtext'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import BosDurum from '@/components/BosDurum.vue'
import DegisimEtiketi from '@/components/DegisimEtiketi.vue'
import SayfaBasligi from '@/components/SayfaBasligi.vue'
import VarlikEkleDialog from '@/components/VarlikEkleDialog.vue'
import { finansApi, VARLIK_TURLERI, type Varlik, type VarlikTuru } from '@/api/finans'
import { hataMesaji } from '@/api/http'
import { useSonIstek } from '@/composables/useSonIstek'
import { useKatalogStore } from '@/stores/katalog'
import { fiyat, neZaman } from '@/utils/bicim'

/**
 * İzleme listesi: seçtiğim varlıkların güncel fiyatı ve günlük değişimi. "Ekle" katalogdan seçtirir; katalogda
 * olmayan hisse / kripto / fon kodla eklenir.
 */
const liste = ref<Varlik[] | null>(null)
const ekleAcik = ref(false)
const kodlaEkleAcik = ref(false)
const arama = ref('')
const katalog = useKatalogStore()
const toast = useToast()
const sonIstek = useSonIstek()

async function yukle(): Promise<void> {
  const veri = await sonIstek(() => finansApi.izleme())
  if (veri !== undefined) liste.value = veri
}

onMounted(yukle)

const izlenenler = computed(() => new Set((liste.value ?? []).map((v) => v.id)))

const gruplar = computed(() => {
  const q = arama.value.trim().toLocaleLowerCase('tr-TR')
  return (Object.keys(VARLIK_TURLERI) as VarlikTuru[])
    .map((tur) => ({
      tur,
      ogeler: katalog.varliklar.filter(
        (v) =>
          v.tur === tur &&
          (!q ||
            v.kod.toLocaleLowerCase('tr-TR').includes(q) ||
            v.ad.toLocaleLowerCase('tr-TR').includes(q)),
      ),
    }))
    .filter((g) => g.ogeler.length > 0)
})

async function ekleAc(): Promise<void> {
  arama.value = ''
  ekleAcik.value = true
  await katalog.yukle(true)
}

async function degistir(v: Varlik): Promise<void> {
  try {
    if (izlenenler.value.has(v.id)) {
      await finansApi.izlemeCikar(v.id)
    } else {
      await finansApi.izlemeEkle(v.id)
    }
    await yukle()
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Yapılamadı', detail: hataMesaji(e), life: 4000 })
  }
}

async function kodlaEklendi(v: Varlik): Promise<void> {
  await finansApi.izlemeEkle(v.id)
  await yukle()
}
</script>

<template>
  <div>
    <SayfaBasligi baslik="İzleme listesi" aciklama="Takip ettiğiniz varlıkların güncel fiyatları">
      <template #eylemler>
        <Button label="Ekle / çıkar" icon="pi pi-list-check" @click="ekleAc" />
      </template>
    </SayfaBasligi>

    <div v-if="liste === null" class="flex flex-col gap-2">
      <Skeleton v-for="n in 5" :key="n" height="3.5rem" />
    </div>
    <BosDurum
      v-else-if="liste.length === 0"
      baslik="İzleme listeniz boş"
      aciklama="Gram altın, dolar, hisse ya da kripto ekleyin."
      ikon="pi pi-eye"
    >
      <Button label="Ekle" icon="pi pi-plus" @click="ekleAc" />
    </BosDurum>
    <ul v-else class="kart divide-y divide-surface-100 px-2 sm:px-4">
      <li v-for="v in liste" :key="v.id">
        <RouterLink
          :to="{ name: 'varlik', params: { id: v.id } }"
          class="flex items-center gap-3 py-3 hover:bg-surface-50"
        >
          <span
            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-surface-100 text-surface-600"
          >
            <i :class="VARLIK_TURLERI[v.tur].ikon" />
          </span>
          <span class="min-w-0 flex-1">
            <span class="flex items-center gap-2">
              <span class="truncate font-semibold text-surface-900">{{ v.ad }}</span>
              <Tag v-if="v.gecikmeli" value="gecikmeli" severity="secondary" class="!text-[10px]" />
              <Tag v-if="v.fiyat?.elle" value="elle" severity="warn" class="!text-[10px]" />
            </span>
            <span class="block text-xs text-surface-500">
              {{ v.kod }} · {{ neZaman(v.fiyat?.zaman) }}
            </span>
          </span>
          <span class="flex shrink-0 flex-col items-end">
            <span class="font-semibold text-surface-900 tabular-nums">
              {{ v.fiyat ? fiyat(v.fiyat.satis ?? v.fiyat.fiyat, v.para_birimi) : '—' }}
            </span>
            <span
              v-if="v.fiyat?.alis && v.fiyat?.satis"
              class="text-[11px] text-surface-400 tabular-nums"
            >
              alış {{ fiyat(v.fiyat.alis, v.para_birimi) }}
            </span>
            <DegisimEtiketi :yuzde="v.fiyat?.degisim_yuzde ?? null" kucuk />
          </span>
        </RouterLink>
      </li>
    </ul>

    <UygulamaPenceresi v-model:visible="ekleAcik" baslik="İzleme listesi" :genislik="32">
      <div class="flex flex-col gap-3">
        <div class="flex gap-2">
          <IconField class="flex-1">
            <InputIcon class="pi pi-search" />
            <InputText v-model="arama" placeholder="Ara: gram, USD, THYAO…" class="w-full" />
          </IconField>
          <Button
            icon="pi pi-plus"
            severity="secondary"
            outlined
            title="Listede yok mu? Kodla ekleyin"
            aria-label="Kodla varlık ekle"
            @click="kodlaEkleAcik = true"
          />
        </div>
        <div class="max-h-[60vh] overflow-y-auto">
          <section v-for="g in gruplar" :key="g.tur" class="mb-3">
            <h3 class="bolum-etiketi mb-1">{{ VARLIK_TURLERI[g.tur].etiket }}</h3>
            <button
              v-for="v in g.ogeler"
              :key="v.id"
              type="button"
              class="flex w-full items-center gap-3 rounded-xl px-2 py-2 text-left hover:bg-surface-50"
              @click="degistir(v)"
            >
              <i
                class="pi text-lg"
                :class="
                  izlenenler.has(v.id)
                    ? 'pi-check-circle text-primary-600'
                    : 'pi-circle text-surface-300'
                "
              />
              <span class="min-w-0 flex-1 truncate">{{ v.ad }}</span>
              <span class="text-xs text-surface-400">{{ v.kod }}</span>
            </button>
          </section>
          <p v-if="gruplar.length === 0" class="py-6 text-center text-sm text-surface-500">
            Bulunamadı. Hisse, kripto ya da fonsa
            <button
              type="button"
              class="font-semibold text-primary-600"
              @click="kodlaEkleAcik = true"
            >
              kodla ekleyin</button
            >.
          </p>
        </div>
      </div>
    </UygulamaPenceresi>
    <VarlikEkleDialog v-model:visible="kodlaEkleAcik" @eklendi="kodlaEklendi" />
  </div>
</template>
