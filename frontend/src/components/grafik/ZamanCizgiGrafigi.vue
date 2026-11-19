<script setup lang="ts">
import { temaSurumu } from '@/composables/useTema'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import Chart from 'primevue/chart'
import { tl } from '@/utils/bicim'

/**
 * Dönem dönem çizgi grafik (Faturalar, Kartlar, Gelirler): yumuşak eğriler, degrade alan dolgusu, soldan sağa
 * çizilen giriş animasyonu, vurgulu son nokta, dokununca / üzerine gelince tüm serilerin o aydaki değeri.
 * Serileri açıp kapatan renkli düğmeler (dokunmatik dostu). `prefers-reduced-motion` açıksa animasyon yoktur.
 * Üstteki "son ay" değeri çizgi çizilirken sayarak yükselir (sayaçlı belirme). Hesap yapılmaz; sunucunun değerleri yalnızca çizilir. Chart.js ilk kullanımda yüklenir (PrimeVue Chart).
 */
export interface ZamanSerisi {
  anahtar: string
  ad: string
  /** Her ay için değer; bilinmeyen ay null (çizgide boşluk) */
  veri: (number | null)[]
  /** CSS rengi ya da `--p-...` / `var(--p-...)` */
  renk: string
  /** Toplam çizgisi: daha kalın, altı dolu */
  kalin?: boolean
  /** Başlangıçta gizli (düğmeyle açılır) */
  gizli?: boolean
}

const props = withDefaults(
  defineProps<{
    etiketler: string[]
    seriler: ZamanSerisi[]
    aciklama: string
    birim?: 'tl' | 'yuzde'
    yukseklik?: string
    bosMetin?: string
    /** Çizilecek son ayın sırası (0-11); sonrası boş kalır (içinde bulunulan yılın gelecek ayları) */
    sonAy?: number
  }>(),
  { birim: 'tl', yukseklik: '15rem', bosMetin: 'Bu yıl için veri yok.', sonAy: 11 },
)

const kapali = ref<Set<string>>(new Set(props.seriler.filter((s) => s.gizli).map((s) => s.anahtar)))
// Seri listesi değişince (örn. tür seçimi) yalnız artık olmayanlar temizlenir; kullanıcının seçimi korunur
watch(
  () => props.seriler.map((s) => s.anahtar).join('|'),
  () => {
    const mevcut = new Set(props.seriler.map((s) => s.anahtar))
    kapali.value = new Set(
      [...kapali.value, ...props.seriler.filter((s) => s.gizli).map((s) => s.anahtar)].filter((k) =>
        mevcut.has(k),
      ),
    )
  },
)

function degistir(anahtar: string): void {
  const yeni = new Set(kapali.value)
  if (yeni.has(anahtar)) yeni.delete(anahtar)
  else yeni.add(anahtar)
  kapali.value = yeni
}

const gorunenler = computed(() => props.seriler.filter((s) => !kapali.value.has(s.anahtar)))

/** "Hiçbiri": görünen seri varsa hepsini kapatır (tek seriyi seçmek kolaylaşır); hepsi kapalıysa "Hepsi" açar */
const hicbiriGorunmuyor = computed(() => gorunenler.value.length === 0)
function hepsiniDegistir(): void {
  kapali.value = hicbiriGorunmuyor.value ? new Set() : new Set(props.seriler.map((s) => s.anahtar))
}
const veriVar = computed(() =>
  props.seriler.some((s) => s.veri.some((d) => d !== null && Number(d) !== 0)),
)

function cssRengi(deger: string): string {
  void temaSurumu.value // tema değişince yeniden okunur
  const ad = deger.startsWith('var(') ? deger.slice(4, -1).trim() : deger
  if (!ad.startsWith('--')) return deger

  return getComputedStyle(document.documentElement).getPropertyValue(ad).trim() || '#6366f1'
}

/** Herhangi bir CSS rengini (oklch dahil) saydamlıklı rgba'ya çevirir; tuval yoksa rengi aynen döndürür */
function alfa(renk: string, a: number): string {
  try {
    const tuval = document.createElement('canvas')
    tuval.width = tuval.height = 1
    const c = tuval.getContext('2d')
    if (!c) return renk
    c.fillStyle = renk
    c.fillRect(0, 0, 1, 1)
    const [r, g, b] = c.getImageData(0, 0, 1, 1).data

    return `rgba(${r}, ${g}, ${b}, ${a})`
  } catch {
    return renk
  }
}

/** Chart.js animasyon bağlamının kullandığımız kısmı */
interface AnimasyonBaglami {
  chart: {
    scales: Record<string, { getPixelForValue: (d: number) => number } | undefined>
    getDatasetMeta: (i: number) => {
      data: { getProps: (anahtarlar: string[], son: boolean) => { y: number } }[]
    }
  }
  datasetIndex: number
  index: number
}

const azHareket = (): boolean =>
  typeof window !== 'undefined' &&
  typeof window.matchMedia === 'function' &&
  window.matchMedia('(prefers-reduced-motion: reduce)').matches

/** Çizim süresi (ms): çizgi noktadan noktaya uzar, üstteki sayaç aynı sürede sayar */
const CIZIM_SURESI = 1300

/** Üstteki özet: görünen ilk serinin (varsa kalın toplam serisinin) son dolu ayı */
const ozetSerisi = computed(() => gorunenler.value.find((s) => s.kalin) ?? gorunenler.value[0])
const ozetHedef = computed(() => {
  const s = ozetSerisi.value
  if (!s) return null
  const i = sonDolu(s.veri.map((d, k) => (k > props.sonAy ? null : d)))

  return i < 0 ? null : { etiket: props.etiketler[i] ?? '', deger: Number(s.veri[i]), ad: s.ad }
})
const sayilan = ref(0)
let sayacKare = 0

function say(hedef: number): void {
  cancelAnimationFrame(sayacKare)
  if (azHareket()) {
    sayilan.value = hedef

    return
  }
  const t0 = performance.now()
  const kare = (t: number): void => {
    const p = Math.min(1, (t - t0) / CIZIM_SURESI)
    sayilan.value = hedef * (1 - Math.pow(1 - p, 3))
    if (p < 1) sayacKare = requestAnimationFrame(kare)
  }
  sayacKare = requestAnimationFrame(kare)
}
onMounted(() => say(ozetHedef.value?.deger ?? 0))
watch(
  () => ozetHedef.value?.deger,
  (d) => say(d ?? 0),
)
onBeforeUnmount(() => cancelAnimationFrame(sayacKare))

const kisaEtiket = (e: string): string => (e.split(' ')[0] ?? e).slice(0, 3)
const kompakt = new Intl.NumberFormat('tr-TR', { notation: 'compact', maximumFractionDigits: 1 })
const bicim = (d: number): string =>
  props.birim === 'yuzde' ? `%${d.toLocaleString('tr-TR', { maximumFractionDigits: 1 })}` : tl(d)

/** Son dolu ayın indeksi (vurgulu nokta) */
function sonDolu(veri: (number | null)[]): number {
  for (let i = veri.length - 1; i >= 0; i--) if (veri[i] !== null && Number(veri[i]) !== 0) return i

  return -1
}

const data = computed(() => ({
  labels: props.etiketler.map(kisaEtiket),
  datasets: gorunenler.value.map((s) => {
    const renk = cssRengi(s.renk)
    const son = sonDolu(s.veri)

    return {
      label: s.ad,
      data: s.veri.map((d, i) => (i > props.sonAy ? null : d)),
      borderColor: renk,
      borderWidth: s.kalin ? 3.5 : 2.5,
      tension: 0.4,
      cubicInterpolationMode: 'monotone' as const,
      spanGaps: true,
      fill: s.kalin ? 'origin' : false,
      backgroundColor: (ctx: {
        chart: { chartArea?: { top: number; bottom: number }; ctx: CanvasRenderingContext2D }
      }) => {
        const alan = ctx.chart.chartArea
        if (!alan) return alfa(renk, 0.15)
        const g = ctx.chart.ctx.createLinearGradient(0, alan.top, 0, alan.bottom)
        g.addColorStop(0, alfa(renk, 0.32))
        g.addColorStop(1, alfa(renk, 0))

        return g
      },
      pointRadius: (ctx: { dataIndex: number }) => (ctx.dataIndex === son ? 4.5 : 0),
      pointHoverRadius: 6,
      pointBackgroundColor: renk,
      pointBorderColor: cssRengi('--p-surface-0'),
      pointBorderWidth: 2,
      pointHoverBorderWidth: 2,
    }
  }),
}))

const options = computed(() => {
  const metin = cssRengi('--p-surface-500')
  const cizgi = cssRengi('--p-surface-200')
  const n = Math.max(props.etiketler.length, 1)
  const adim = CIZIM_SURESI / n
  const hareket = !azHareket()
  const onceki = (ctx: AnimasyonBaglami): number =>
    ctx.index === 0
      ? ctx.chart.scales.y!.getPixelForValue(0)
      : (ctx.chart.getDatasetMeta(ctx.datasetIndex).data[ctx.index - 1]?.getProps(['y'], true).y ??
        0)

  return {
    maintainAspectRatio: false,
    locale: 'tr-TR',
    interaction: { mode: 'index', intersect: false },
    animation: hareket ? undefined : false,
    // Soldan sağa, nokta nokta çizilir (ilk yüklemede); seri açılıp kapanınca yumuşak geçiş
    animations: hareket
      ? {
          x: {
            type: 'number',
            easing: 'easeOutCubic',
            duration: adim,
            from: Number.NaN,
            delay: (ctx: { type: string; xStarted?: boolean; index: number }) => {
              if (ctx.type !== 'data' || ctx.xStarted) return 0
              ctx.xStarted = true

              return ctx.index * adim
            },
          },
          y: {
            type: 'number',
            easing: 'easeOutCubic',
            duration: adim,
            from: onceki,
            delay: (ctx: { type: string; yStarted?: boolean; index: number }) => {
              if (ctx.type !== 'data' || ctx.yStarted) return 0
              ctx.yStarted = true

              return ctx.index * adim
            },
          },
        }
      : undefined,
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: cssRengi('--p-surface-900'),
        padding: 10,
        cornerRadius: 12,
        usePointStyle: true,
        titleFont: { family: 'Plus Jakarta Sans Variable', weight: 'bold' },
        bodyFont: { family: 'Plus Jakarta Sans Variable' },
        callbacks: {
          title: (items: { dataIndex: number }[]) =>
            props.etiketler[items[0]?.dataIndex ?? 0] ?? '',
          label: (o: { dataset: { label?: string }; raw: unknown }) =>
            `${o.dataset.label ?? ''}: ${o.raw === null || o.raw === undefined ? '—' : bicim(Number(o.raw))}`,
        },
      },
    },
    scales: {
      x: {
        grid: { display: false },
        border: { display: false },
        ticks: {
          color: metin,
          font: { family: 'Plus Jakarta Sans Variable', size: 11 },
          maxRotation: 0,
        },
      },
      y: {
        beginAtZero: true,
        grid: { color: cizgi, drawTicks: false },
        border: { display: false },
        ticks: {
          color: metin,
          font: { family: 'Plus Jakarta Sans Variable', size: 11 },
          maxTicksLimit: 5,
          callback: (v: number | string) =>
            props.birim === 'yuzde' ? `%${Number(v)}` : kompakt.format(Number(v)),
        },
      },
    },
  }
})
</script>

<template>
  <div class="min-w-0" data-test="zaman-grafigi">
    <div
      v-if="seriler.length > 1"
      class="-mx-1 mb-2 flex gap-1.5 overflow-x-auto px-1 pb-1"
      role="group"
      aria-label="Seriler"
    >
      <button
        type="button"
        class="shrink-0 rounded-full border border-dashed border-surface-300 px-3 py-1.5 text-xs font-semibold text-surface-600 transition-all active:scale-95"
        data-test="seri-hicbiri"
        @click="hepsiniDegistir"
      >
        {{ hicbiriGorunmuyor ? 'Hepsi' : 'Hiçbiri' }}
      </button>
      <button
        v-for="s in seriler"
        :key="s.anahtar"
        type="button"
        class="flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold ring-1 transition-all active:scale-95"
        :class="
          kapali.has(s.anahtar)
            ? 'bg-surface-0 text-surface-400 ring-surface-200'
            : 'bg-surface-50 text-surface-800 ring-surface-300'
        "
        :aria-pressed="!kapali.has(s.anahtar)"
        data-test="seri-dugmesi"
        @click="degistir(s.anahtar)"
      >
        <span
          class="size-2.5 rounded-full transition-opacity"
          :class="kapali.has(s.anahtar) ? 'opacity-30' : ''"
          :style="{ background: s.renk }"
        />
        {{ s.ad }}
      </button>
    </div>
    <p v-if="veriVar && ozetHedef" class="mb-1 flex items-baseline gap-2" data-test="grafik-sayac">
      <span class="text-2xl font-extrabold tracking-tight text-surface-900 tabular-nums">{{
        bicim(sayilan)
      }}</span>
      <span class="text-xs text-surface-500">{{ ozetHedef.etiket }} · {{ ozetHedef.ad }}</span>
    </p>
    <div v-if="!veriVar" class="py-10 text-center text-sm text-surface-500" data-test="grafik-bos">
      {{ bosMetin }}
    </div>
    <div v-else class="relative" :style="{ height: yukseklik }" role="img" :aria-label="aciklama">
      <p
        v-if="hicbiriGorunmuyor"
        class="absolute inset-0 z-10 flex items-center justify-center text-sm text-surface-500"
        data-test="grafik-seri-yok"
      >
        Seri seçin
      </p>
      <Chart type="line" :data="data" :options="options" class="h-full" />
    </div>
  </div>
</template>
