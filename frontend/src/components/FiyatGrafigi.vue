<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import Chart from 'primevue/chart'
import SelectButton from 'primevue/selectbutton'
import Skeleton from 'primevue/skeleton'
import { finansApi } from '@/api/finans'
import { useSonIstek } from '@/composables/useSonIstek'
import { degisimRengi, fiyat, tarih, yuzde } from '@/utils/bicim'

/**
 * Varlığın günlük kapanış grafiği (1H / 1A / 3A / 1Y / Tümü). Dönemde artış yeşil, düşüş kırmızı çizilir.
 */
const props = defineProps<{ varlikId: number; paraBirimi: string }>()

const ARALIKLAR = [
  { deger: '1H', etiket: '1H' },
  { deger: '1A', etiket: '1A' },
  { deger: '3A', etiket: '3A' },
  { deger: '1Y', etiket: '1Y' },
  { deger: 'T', etiket: 'Tümü' },
]
const aralik = ref('3A')
const gecmis = ref<{ tarih: string; kapanis: number }[] | null>(null)
const sonIstek = useSonIstek()

async function yukle(): Promise<void> {
  gecmis.value = null
  const veri = await sonIstek(() => finansApi.gecmis(props.varlikId, aralik.value))
  if (veri !== undefined) gecmis.value = veri
}

onMounted(yukle)
watch(aralik, yukle)

const donemDegisimi = computed(() => {
  const g = gecmis.value
  if (!g || g.length < 2) return null
  const ilk = g[0]!.kapanis
  const son = g[g.length - 1]!.kapanis

  return ilk > 0 ? ((son - ilk) / ilk) * 100 : null
})

function css(degisken: string): string {
  return getComputedStyle(document.documentElement).getPropertyValue(degisken).trim()
}

const data = computed(() => {
  const yukselis = (donemDegisimi.value ?? 0) >= 0
  const renk =
    css(yukselis ? '--p-emerald-500' : '--p-red-500') || (yukselis ? '#10b981' : '#ef4444')

  return {
    labels: (gecmis.value ?? []).map((s) => tarih(s.tarih)),
    datasets: [
      {
        label: 'Kapanış',
        data: (gecmis.value ?? []).map((s) => s.kapanis),
        borderColor: renk,
        backgroundColor: `${renk}22`,
        fill: true,
        borderWidth: 2,
        tension: 0.25,
        pointRadius: 0,
        pointHoverRadius: 4,
      },
    ],
  }
})

const options = computed(() => {
  const metin = css('--p-surface-500') || '#64748b'
  const cizgi = css('--p-surface-200') || '#e2e8f0'
  const yazi = { family: 'Plus Jakarta Sans Variable', size: 11 }

  return {
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: { display: false },
      tooltip: {
        callbacks: { label: (c: { parsed: { y: number } }) => fiyat(c.parsed.y, props.paraBirimi) },
      },
    },
    scales: {
      x: {
        ticks: { color: metin, font: yazi, maxTicksLimit: 5, maxRotation: 0 },
        grid: { display: false },
      },
      y: {
        ticks: {
          color: metin,
          font: yazi,
          maxTicksLimit: 5,
          callback: (v: number) => fiyat(v, props.paraBirimi),
        },
        grid: { color: cizgi },
        border: { display: false },
      },
    },
  }
})
</script>

<template>
  <div class="flex flex-col gap-3">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <span v-if="donemDegisimi !== null" class="text-sm text-surface-500">
        Dönemde
        <span class="font-semibold" :class="degisimRengi(donemDegisimi)">{{
          yuzde(donemDegisimi)
        }}</span>
      </span>
      <span v-else />
      <SelectButton
        v-model="aralik"
        :options="ARALIKLAR"
        option-label="etiket"
        option-value="deger"
        :allow-empty="false"
        size="small"
        aria-label="Grafik aralığı"
      />
    </div>
    <Skeleton v-if="gecmis === null" height="16rem" />
    <p
      v-else-if="gecmis.length < 2"
      class="flex h-40 items-center justify-center text-center text-sm text-surface-500"
    >
      Bu aralıkta yeterli fiyat geçmişi yok. Fiyatlar her gün kaydedildikçe grafik dolar.
    </p>
    <div v-else class="h-64" role="img" :aria-label="`Fiyat grafiği, ${aralik}`">
      <Chart type="line" :data="data" :options="options" class="h-full" />
    </div>
  </div>
</template>
