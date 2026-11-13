<script setup lang="ts">
import { computed } from 'vue'
import Chart from 'primevue/chart'

/**
 * Tema renkleriyle çizilen sade grafik (chart.js, PrimeVue Chart üzerinden; chart.js ilk kullanımda yüklenir).
 * Renkler okul sisteminin docs/TASARIM.md belgesi'deki anlamlarla verilir; verilmezse sırayla indigo, zümrüt, kehribar, gök, gül, gri kullanılır.
 */
export interface GrafikSerisi {
  ad: string
  /** Bilinmeyen değer null (grafikte boşluk) */
  veri: (number | null)[]
  /** Tek renk veya (halka/pasta için) dilim başına renk. CSS rengi ya da `--p-...` değişkeni. */
  renk?: string | string[]
}

const props = withDefaults(
  defineProps<{
    tur: 'bar' | 'line' | 'doughnut'
    etiketler: string[]
    seriler: GrafikSerisi[]
    /** Ekran okuyucular için grafiğin ne gösterdiği. */
    aciklama: string
    yukseklik?: string
    /** Çubuk grafiği yatay çizer (uzun etiketler için). */
    yatay?: boolean
    /** Alttaki etiket listesi (halkada varsayılan açık). */
    gosterge?: boolean
    /** İpucundaki değerin biçimi (ör. TL). */
    degerBicimi?: (deger: number) => string
    /** Halkanın iç boşluğu (Chart.js `cutout`). */
    kesim?: string
    /** Dokununca / üzerine gelince ipucu (küçük halkada kapatılır: tuvale sığmaz). */
    ipucu?: boolean
  }>(),
  {
    yukseklik: '16rem',
    yatay: false,
    gosterge: true,
    degerBicimi: undefined,
    kesim: '65%',
    ipucu: true,
  },
)

/** Kategorik sıra: indigo, zümrüt, kehribar, gök, gül, gri. */
const GRAFIK_RENKLERI = [
  '--p-primary-500',
  '--p-emerald-500',
  '--p-amber-500',
  '--p-sky-500',
  '--p-rose-500',
  '--p-surface-400',
]

function cssRengi(deger: string): string {
  if (!deger.startsWith('--')) return deger

  return getComputedStyle(document.documentElement).getPropertyValue(deger).trim() || '#6366f1'
}

const halka = computed(() => props.tur === 'doughnut')

const data = computed(() => ({
  labels: props.etiketler,
  datasets: props.seriler.map((seri, i) => {
    const renk =
      seri.renk ?? (halka.value ? GRAFIK_RENKLERI : GRAFIK_RENKLERI[i % GRAFIK_RENKLERI.length]!)
    const renkler = Array.isArray(renk) ? renk.map(cssRengi) : cssRengi(renk)

    return {
      label: seri.ad,
      data: seri.veri,
      backgroundColor: renkler,
      borderColor:
        props.tur === 'line' ? renkler : halka.value ? cssRengi('--p-surface-0') : 'transparent',
      borderWidth: props.tur === 'line' ? 2 : halka.value ? 2 : 0,
      borderRadius: props.tur === 'bar' ? 6 : 0,
      maxBarThickness: 36,
      tension: 0.35,
      pointRadius: 0,
      pointHoverRadius: 4,
      fill: false,
    }
  }),
}))

const options = computed(() => {
  const metin = cssRengi('--p-surface-500')
  const cizgi = cssRengi('--p-surface-200')
  const eksen = {
    ticks: { color: metin, font: { family: 'Plus Jakarta Sans Variable', size: 12 }, precision: 0 },
    grid: { color: cizgi, drawTicks: false },
    border: { display: false },
  }

  return {
    maintainAspectRatio: false,
    indexAxis: props.yatay ? 'y' : 'x',
    locale: 'tr-TR',
    plugins: {
      legend: {
        display: halka.value ? props.gosterge : props.seriler.length > 1,
        position: 'bottom',
        labels: {
          color: metin,
          usePointStyle: true,
          boxWidth: 8,
          font: { family: 'Plus Jakarta Sans Variable' },
        },
      },
      tooltip: {
        enabled: props.ipucu,
        titleFont: { family: 'Plus Jakarta Sans Variable' },
        bodyFont: { family: 'Plus Jakarta Sans Variable' },
        ...(props.degerBicimi
          ? {
              callbacks: {
                // Halkada dilim adı, çizgi / çubukta seri adı
                label: (o: { label?: string; raw: unknown; dataset?: { label?: string } }) =>
                  `${(halka.value ? o.label : o.dataset?.label) ?? ''}: ${props.degerBicimi!(Number(o.raw))}`,
              },
            }
          : {}),
      },
    },
    ...(halka.value
      ? { cutout: props.kesim }
      : {
          scales: {
            x: { ...eksen, grid: props.yatay ? eksen.grid : { display: false }, beginAtZero: true },
            y: { ...eksen, grid: props.yatay ? { display: false } : eksen.grid, beginAtZero: true },
          },
        }),
  }
})
</script>

<template>
  <div :style="{ height: yukseklik }" role="img" :aria-label="aciklama">
    <Chart :type="tur" :data="data" :options="options" class="h-full" />
  </div>
</template>
