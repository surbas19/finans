<script setup lang="ts">
import { computed } from 'vue'
import VeriGrafigi from '@/components/VeriGrafigi.vue'
import { VARLIK_TURLERI, type DagilimDilimi } from '@/api/finans'
import { tl } from '@/utils/bicim'

/**
 * Varlık türlerine göre dağılım: halka grafik ve tür / tutar / yüzde listesi (özet ve hesap detayı).
 */
const props = defineProps<{ dagilim: DagilimDilimi[]; toplamDeger: number }>()

const grafik = computed(() => ({
  etiketler: props.dagilim.map((x) => x.etiket),
  seriler: [
    {
      ad: 'Değer',
      veri: props.dagilim.map((x) => x.deger),
      renk: props.dagilim.map((x) => VARLIK_TURLERI[x.tur].renk),
    },
  ],
}))

function oran(deger: number): string {
  return ((deger / (props.toplamDeger || 1)) * 100).toLocaleString('tr-TR', {
    maximumFractionDigits: 1,
  })
}
</script>

<template>
  <section class="kart p-4 sm:p-5">
    <h2 class="bolum-etiketi mb-2">Dağılım</h2>
    <VeriGrafigi
      tur="doughnut"
      :etiketler="grafik.etiketler"
      :seriler="grafik.seriler"
      aciklama="Varlık türlerine göre dağılım"
      yukseklik="15rem"
    />
    <ul class="mt-3 flex flex-col gap-1.5 text-sm">
      <li v-for="d in dagilim" :key="d.tur" class="flex items-center justify-between gap-2">
        <span class="flex items-center gap-2 text-surface-600">
          <span
            class="h-2.5 w-2.5 rounded-full"
            :style="{ background: `var(${VARLIK_TURLERI[d.tur].renk})` }"
          />
          {{ d.etiket }}
        </span>
        <span class="font-semibold text-surface-900 tabular-nums">
          {{ tl(d.deger) }}
          <span class="ml-1 text-xs font-medium text-surface-400">%{{ oran(d.deger) }}</span>
        </span>
      </li>
    </ul>
  </section>
</template>
