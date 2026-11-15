<script setup lang="ts">
import Accordion from 'primevue/accordion'
import AccordionContent from 'primevue/accordioncontent'
import AccordionHeader from 'primevue/accordionheader'
import AccordionPanel from 'primevue/accordionpanel'
import Button from 'primevue/button'
import type { ZekatDonemi, ZekatOdemesi } from '@/api/aile'
import { tarih, tl } from '@/utils/bicim'

/**
 * Verilen zekât, "Ramazan'dan Ramazan'a" zekât yılı bazında: her yılın gereken, verilen ve kalan zekâtı, ödemeleri.
 * Zekât hesaplanmamış yılda gereken yoktur; yalnız verilen gösterilir.
 */
defineProps<{ donemler: ZekatDonemi[] }>()
const emit = defineEmits<{ ekle: [donem: ZekatDonemi]; sil: [odeme: ZekatOdemesi] }>()
</script>

<template>
  <Accordion
    :value="donemler.filter((d) => d.guncel).map((d) => d.baslangic)"
    multiple
    class="[&_.p-accordioncontent-wrapper]:min-w-0 [&_.p-accordioncontent-wrapper]:overflow-hidden"
    data-test="zekat-donemleri"
  >
    <AccordionPanel v-for="d in donemler" :key="d.baslangic" :value="d.baslangic">
      <AccordionHeader data-test="zekat-donem-baslik">
        <span class="flex min-w-0 flex-1 flex-col gap-0.5 pr-2 text-left">
          <span class="flex flex-wrap items-center gap-2">
            <span class="text-sm font-semibold">{{ d.etiket }}</span>
            <span
              v-if="d.guncel"
              class="rounded-full bg-primary-100 px-2 py-0.5 text-[11px] font-semibold text-primary-800"
              >Bu yıl</span
            >
            <span
              v-if="d.kalan === 0 && d.gereken !== null && d.gereken > 0"
              class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-800"
              data-test="zekat-tamam"
              >Tamamlandı</span
            >
          </span>
          <span class="flex flex-wrap gap-x-3 text-xs font-normal text-surface-500 tabular-nums">
            <span v-if="d.gereken !== null"
              >Gereken <strong>{{ tl(d.gereken) }}</strong></span
            >
            <span v-else>Zekât henüz hesaplanmadı</span>
            <span
              >Verilen <strong data-test="zekat-verilen">{{ tl(d.verilen) }}</strong></span
            >
            <span v-if="d.kalan !== null"
              >Kalan <strong data-test="zekat-kalan-donem">{{ tl(d.kalan) }}</strong></span
            >
            <span v-if="d.fazla !== null && d.fazla > 0" data-test="zekat-fazla"
              >Fazla {{ tl(d.fazla) }}</span
            >
          </span>
        </span>
      </AccordionHeader>
      <AccordionContent>
        <ul v-if="d.odemeler.length" class="divide-y divide-surface-100 text-sm">
          <li v-for="o in d.odemeler" :key="o.id" class="flex items-center gap-2 py-1.5">
            <span class="min-w-0 flex-1">
              <span class="block truncate"
                >{{ tarih(o.tarih) }}<template v-if="o.alici"> · {{ o.alici }}</template></span
              >
              <span v-if="o.not" class="block truncate text-xs text-surface-500">{{ o.not }}</span>
            </span>
            <span class="whitespace-nowrap tabular-nums">{{ tl(o.tutar) }}</span>
            <Button
              icon="pi pi-trash"
              severity="danger"
              text
              rounded
              size="small"
              aria-label="Ödemeyi sil"
              @click="emit('sil', o)"
            />
          </li>
        </ul>
        <p v-else class="text-sm text-surface-500">Bu zekât yılında ödeme girilmedi.</p>
        <p class="mt-2 flex justify-between text-sm font-semibold">
          <span>Verilen toplam</span><span class="tabular-nums">{{ tl(d.verilen) }}</span>
        </p>
        <Button
          class="mt-3"
          label="Ödeme ekle"
          icon="pi pi-plus"
          size="small"
          data-test="zekat-donem-odeme-ekle"
          @click="emit('ekle', d)"
        />
      </AccordionContent>
    </AccordionPanel>
  </Accordion>
</template>
