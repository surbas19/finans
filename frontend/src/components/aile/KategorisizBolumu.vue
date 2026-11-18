<script setup lang="ts">
import { computed, ref } from 'vue'
import Accordion from 'primevue/accordion'
import AccordionContent from 'primevue/accordioncontent'
import AccordionHeader from 'primevue/accordionheader'
import AccordionPanel from 'primevue/accordionpanel'
import { useToast } from 'primevue/usetoast'
import KartEtiketi from '@/components/aile/KartEtiketi.vue'
import { aileApi, type KartIslemi } from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { useAileStore } from '@/stores/aile'
import { tarih, tl } from '@/utils/bicim'
import { kategoriNoktasi } from '@/utils/kategoriRengi'

/**
 * Kategorisiz harcamalar (listedeki süzgece göre): her satırın altında en çok kullanılan kategoriler tek dokunuşla;
 * "Diğer" tam seçiciyi açar. Seçim açıklaması aynı olan bütün işlemlere uygulanır, satır listeden düşer.
 */
const props = defineProps<{ islemler: KartIslemi[] }>()
const emit = defineEmits<{ degisti: []; diger: [islem: KartIslemi] }>()

const aile = useAileStore()
const toast = useToast()
const acik = ref<string[]>(['kategorisiz'])
const tumu = ref(false)
const gonderilen = ref<number | null>(null)
const ILK = 15

const gorunen = computed(() => (tumu.value ? props.islemler : props.islemler.slice(0, ILK)))

async function ata(islem: KartIslemi, kategoriId: number): Promise<void> {
  if (gonderilen.value !== null) return
  gonderilen.value = islem.id
  try {
    const sonuc = await aileApi.kartIslemiKategori(islem.id, kategoriId)
    toast.add({ severity: 'success', summary: sonuc.message, life: 3000 })
    void aile.kategorileriYenile().catch(() => {})
    emit('degisti')
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Yapılamadı', detail: hataMesaji(e), life: 5000 })
  } finally {
    gonderilen.value = null
  }
}
</script>

<template>
  <section class="kart border-amber-200 bg-amber-50/40 p-2" data-test="kategorisiz-bolumu">
    <Accordion
      v-model:value="acik"
      multiple
      class="[&_.p-accordioncontent-wrapper]:min-w-0 [&_.p-accordioncontent-wrapper]:overflow-hidden"
    >
      <AccordionPanel value="kategorisiz">
        <AccordionHeader data-test="kategorisiz-baslik"
          >Kategorisiz harcamalar ({{ islemler.length }})</AccordionHeader
        >
        <AccordionContent>
          <ul class="divide-y divide-surface-100">
            <li
              v-for="i in gorunen"
              :key="i.id"
              class="py-3"
              :class="gonderilen === i.id ? 'opacity-60' : ''"
              data-test="kategorisiz-satir"
            >
              <div class="flex items-start justify-between gap-3">
                <span class="min-w-0">
                  <span class="block truncate font-semibold text-surface-900">{{
                    i.aciklama
                  }}</span>
                  <span class="flex flex-wrap items-center gap-x-1.5 text-xs text-surface-500">
                    {{ i.islem_tarihi ? tarih(i.islem_tarihi) : '—' }} ·
                    <KartEtiketi :kart-id="i.kart_id" />
                  </span>
                </span>
                <span class="font-semibold whitespace-nowrap tabular-nums">{{ tl(i.tutar) }}</span>
              </div>
              <div class="mt-2 flex flex-wrap gap-1.5" data-test="kategorisiz-cipler">
                <button
                  v-for="k in aile.sikKategoriler"
                  :key="k.id"
                  type="button"
                  class="inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1.5 text-xs font-semibold ring-1 ring-surface-200 transition-all active:scale-95 disabled:opacity-50"
                  :disabled="gonderilen !== null"
                  data-test="kategorisiz-cip"
                  @click="ata(i, k.id)"
                >
                  <span class="size-2 rounded-full" :style="{ background: kategoriNoktasi(k) }" />{{
                    k.ad
                  }}
                </button>
                <button
                  type="button"
                  class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 text-xs font-semibold text-primary-700 ring-1 ring-primary-200 transition-all active:scale-95"
                  :disabled="gonderilen !== null"
                  data-test="kategorisiz-diger"
                  @click="emit('diger', i)"
                >
                  <i class="pi pi-ellipsis-h text-xs" /> Diğer
                </button>
              </div>
            </li>
          </ul>
          <button
            v-if="!tumu && islemler.length > ILK"
            type="button"
            class="mt-2 w-full rounded-2xl py-2 text-sm font-semibold text-primary-700"
            data-test="kategorisiz-tumu"
            @click="tumu = true"
          >
            Tümünü göster ({{ islemler.length }})
          </button>
        </AccordionContent>
      </AccordionPanel>
    </Accordion>
  </section>
</template>
