<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import Chart from 'primevue/chart'
import SelectButton from 'primevue/selectbutton'
import Skeleton from 'primevue/skeleton'
import DegisimEtiketi from '@/components/DegisimEtiketi.vue'
import { finansApi, type PerformansNoktasi } from '@/api/finans'
import { useSonIstek } from '@/composables/useSonIstek'
import { isaretliTl, tarih, tl } from '@/utils/bicim'
import { donemKz } from '@/utils/performans'

/**
 * Bütün hesapların (hesapId null) ya da bir hesabın günlük değeri ve maliyeti; aradaki fark K/Z.
 * `yenile` değişince (işlem eklendi / silindi) yeniden yüklenir.
 */
const props = withDefaults(defineProps<{ hesapId: number | null; yenile?: number }>(), {
  yenile: 0,
})

const ARALIKLAR = [
  { deger: '1A', etiket: '1A' },
  { deger: '3A', etiket: '3A' },
  { deger: '1Y', etiket: '1Y' },
  { deger: 'T', etiket: 'Tümü' },
]
const aralik = ref('3A')
const gecmis = ref<PerformansNoktasi[] | null>(null)
const hata = ref(false)
const sonIstek = useSonIstek()

async function yukle(): Promise<void> {
  hata.value = false
  try {
    const veri = await sonIstek(() => finansApi.performans(aralik.value, props.hesapId))
    if (veri !== undefined) gecmis.value = veri
  } catch {
    hata.value = true
    gecmis.value = []
  }
}

onMounted(yukle)
watch(
  () => [props.hesapId, aralik.value],
  () => {
    gecmis.value = null
    void yukle()
  },
)
// İşlem değişince eski grafik yerinde kalır, yenisi gelince değişir
watch(() => props.yenile, yukle)

const kz = computed(() => donemKz(gecmis.value))
const eksik = computed(() => (gecmis.value ?? []).some((n) => n.eksik))

function css(degisken: string, yedek: string): string {
  return getComputedStyle(document.documentElement).getPropertyValue(degisken).trim() || yedek
}

const data = computed(() => {
  const g = gecmis.value ?? []
  const ana = css('--p-primary-500', '#6366f1')
  const gri = css('--p-surface-400', '#94a3b8')

  return {
    labels: g.map((n) => tarih(n.tarih)),
    datasets: [
      {
        label: 'Değer',
        data: g.map((n) => n.deger),
        borderColor: ana,
        backgroundColor: `${ana}1f`,
        fill: true,
        borderWidth: 2,
        tension: 0.25,
        pointRadius: 0,
        pointHoverRadius: 4,
      },
      {
        label: 'Maliyet',
        data: g.map((n) => n.maliyet),
        borderColor: gri,
        backgroundColor: gri,
        borderDash: [5, 4],
        fill: false,
        borderWidth: 1.5,
        tension: 0,
        pointRadius: 0,
        pointHoverRadius: 3,
      },
    ],
  }
})

const options = computed(() => {
  const metin = css('--p-surface-500', '#64748b')
  const cizgi = css('--p-surface-200', '#e2e8f0')
  const yazi = { family: 'Plus Jakarta Sans Variable', size: 11 }
  const kisa = new Intl.NumberFormat('tr-TR', { notation: 'compact', maximumFractionDigits: 1 })

  return {
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: {
        display: true,
        position: 'bottom',
        labels: { color: metin, usePointStyle: true, boxWidth: 8, font: yazi },
      },
      tooltip: {
        callbacks: {
          label: (c: { dataset: { label: string }; parsed: { y: number } }) =>
            `${c.dataset.label}: ${tl(c.parsed.y)}`,
          footer: (ogeler: { dataIndex: number }[]) => {
            const n = gecmis.value?.[ogeler[0]?.dataIndex ?? -1]

            return n ? `K/Z: ${isaretliTl(n.kz)}` : ''
          },
        },
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
          callback: (v: number) => `₺${kisa.format(v)}`,
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
      <span v-if="kz !== null" class="flex items-center gap-1.5 text-sm text-surface-500">
        Dönemde K/Z <DegisimEtiketi :tutar="kz" />
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
      {{
        hata
          ? 'Geçmiş yüklenemedi.'
          : 'Bu aralıkta grafik için yeterli veri yok. İşlem girdikçe ve fiyatlar kaydedildikçe dolar.'
      }}
    </p>
    <template v-else>
      <div class="h-64" role="img" :aria-label="`Değer ve maliyet grafiği, ${aralik}`">
        <Chart type="line" :data="data" :options="options" class="h-full" />
      </div>
      <p v-if="eksik" class="text-xs text-surface-400">
        Fiyat geçmişi olmayan günlerde bazı varlıklar işlem fiyatıyla değerlendi ya da değere
        katılmadı.
      </p>
    </template>
  </div>
</template>
