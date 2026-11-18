<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import KartEtiketi from '@/components/aile/KartEtiketi.vue'
import { aileApi, type KartIslemi, type SirketGecmisi } from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { useDarEkran } from '@/composables/useDarEkran'
import { useAileStore } from '@/stores/aile'
import { tl } from '@/utils/bicim'
import { buAy, donemEkle, donemEtiketi } from '@/utils/donem'
import { kartRengi } from '@/utils/kartRengi'

/**
 * Şirket geçmişi: bir kart satırının şirketinden (aynı açıklama) bütün kartlarda yapılmış harcamalar. Telefonda tam
 * ekran; üstte toplam / aylık ortalama / bu ay, aylık toplam eğrisi; altında her ayın başlığı (toplamıyla) ve o ayın
 * harcamaları. Hesap sunucuda (`SirketGecmisi`); burada yalnız boş aylar eğri için sıfırla doldurulur.
 */
const props = defineProps<{ islem: KartIslemi | null }>()
const gorunur = defineModel<boolean>('visible', { default: false })
const aile = useAileStore()
const dar = useDarEkran()

const veri = ref<SirketGecmisi | null>(null)
const hata = ref<string | null>(null)
let istekNo = 0

watch(
  () => [gorunur.value, props.islem?.id] as const,
  async ([acik, id]) => {
    if (!acik || !id) return
    const no = ++istekNo
    veri.value = null
    hata.value = null
    try {
      const sonuc = await aileApi.kartIslemiGecmisi(id)
      if (no === istekNo) veri.value = sonuc
    } catch (e) {
      if (no === istekNo) hata.value = hataMesaji(e)
    }
  },
  { immediate: true },
)

const kategori = computed(() => aile.kategori(props.islem?.kategori_id ?? null))
const renk = computed(() => (kategori.value ? kartRengi(kategori.value) : 'slate'))

/** Eğri: ilk harcama ayından bu aya (ya da son harcama ayına) her ay; harcama olmayan ay 0 */
const seri = computed(() => {
  const v = veri.value
  if (!v?.ilk_donem || v.aylar.length === 0) return []
  const toplamlar = new Map(v.aylar.map((a) => [a.donem, a.toplam]))
  const son = [v.aylar[0]!.donem, buAy()].sort()[1]!
  const liste: { donem: string; toplam: number }[] = []
  for (let d = v.ilk_donem; d <= son && liste.length < 240; d = donemEkle(d, 1)) {
    liste.push({ donem: d, toplam: toplamlar.get(d) ?? 0 })
  }

  return liste
})

const G = 320
const Y = 84
const egri = computed(() => {
  const s = seri.value
  if (s.length === 0) return null
  const enCok = Math.max(...s.map((n) => n.toplam), 1)
  const adim = s.length > 1 ? (G - 12) / (s.length - 1) : 0
  const noktalar = s.map((n, i) => ({
    x: s.length > 1 ? 6 + i * adim : G / 2,
    y: Y - 6 - (Math.max(n.toplam, 0) / enCok) * (Y - 18),
  }))
  const cizgi = noktalar
    .map((n, i) => `${i ? 'L' : 'M'}${n.x.toFixed(1)} ${n.y.toFixed(1)}`)
    .join(' ')
  const ilk = noktalar[0]!
  const son = noktalar[noktalar.length - 1]!

  return { cizgi, alan: `${cizgi} L${son.x.toFixed(1)} ${Y} L${ilk.x.toFixed(1)} ${Y} Z`, son }
})

const gunAy = (t: string | null): string =>
  t
    ? new Date(`${t}T00:00:00`).toLocaleDateString('tr-TR', { day: '2-digit', month: 'short' })
    : '—'
</script>

<template>
  <Dialog
    v-model:visible="gorunur"
    modal
    :draggable="false"
    :dismissable-mask="!dar"
    :header="islem?.aciklama ?? 'Şirket geçmişi'"
    :style="
      dar
        ? { width: '100vw', height: '100dvh', maxHeight: '100dvh', margin: 0, borderRadius: 0 }
        : { width: 'min(36rem, calc(100vw - 2rem))', height: 'min(48rem, calc(100dvh - 4rem))' }
    "
    :content-style="{ display: 'flex', flexDirection: 'column', padding: 0, minHeight: 0 }"
    data-test="sirket-gecmisi"
  >
    <template #header>
      <div class="flex min-w-0 items-center gap-3">
        <span
          class="flex size-10 shrink-0 items-center justify-center rounded-xl text-base font-bold"
          :style="{ background: `var(--p-${renk}-100)`, color: `var(--p-${renk}-700)` }"
          aria-hidden="true"
          >{{ (islem?.aciklama ?? '?').trim().charAt(0) }}</span
        >
        <span class="min-w-0">
          <span class="block truncate font-bold text-surface-900">{{ islem?.aciklama }}</span>
          <span class="block truncate text-xs text-surface-500">
            {{ kategori?.ad ?? 'Kategorisiz' }}
            <template v-if="veri">
              · {{ veri.adet }} işlem
              <template v-if="veri.ilk_donem">· ilki {{ donemEtiketi(veri.ilk_donem) }}</template>
            </template>
          </span>
        </span>
      </div>
    </template>

    <div class="flex flex-col gap-3 px-4 pb-3">
      <Message v-if="hata" severity="error" :closable="false">{{ hata }}</Message>
      <template v-else-if="!veri">
        <Skeleton height="3.5rem" border-radius="1rem" />
        <Skeleton height="5.5rem" border-radius="1rem" />
      </template>
      <template v-else>
        <div class="grid grid-cols-3 gap-2" data-test="gecmis-ozet">
          <div class="rounded-2xl bg-surface-50 p-2.5">
            <span class="block text-xs text-surface-500">Toplam</span>
            <span class="block text-sm font-bold tabular-nums">{{ tl(veri.toplam) }}</span>
          </div>
          <div class="rounded-2xl bg-surface-50 p-2.5">
            <span class="block text-xs text-surface-500">Aylık ort.</span>
            <span class="block text-sm font-bold tabular-nums">{{ tl(veri.aylik_ortalama) }}</span>
          </div>
          <div class="rounded-2xl bg-surface-50 p-2.5">
            <span class="block text-xs text-surface-500">Bu ay</span>
            <span class="block text-sm font-bold tabular-nums">{{ tl(veri.bu_ay) }}</span>
          </div>
        </div>
        <svg
          v-if="egri"
          :viewBox="`0 0 ${G} ${Y}`"
          preserveAspectRatio="none"
          class="block h-20 w-full"
          role="img"
          :aria-label="`Aylık toplamlar, ${seri.length} ay`"
          data-test="gecmis-egri"
        >
          <path
            :d="egri.alan"
            :fill="`color-mix(in srgb, var(--p-${renk}-500) 18%, transparent)`"
          />
          <path
            :d="egri.cizgi"
            fill="none"
            :stroke="`var(--p-${renk}-500)`"
            stroke-width="2.2"
            stroke-linejoin="round"
            vector-effect="non-scaling-stroke"
          />
          <circle :cx="egri.son.x" :cy="egri.son.y" r="3.5" :fill="`var(--p-${renk}-500)`" />
        </svg>
      </template>
    </div>

    <div v-if="veri" class="min-h-0 flex-1 overflow-y-auto pb-[env(safe-area-inset-bottom)]">
      <p v-if="veri.aylar.length === 0" class="p-6 text-center text-sm text-surface-500">
        Onaylı işlem yok.
      </p>
      <section v-for="ay in veri.aylar" :key="ay.donem" data-test="gecmis-ay">
        <h3
          class="sticky top-0 z-10 flex items-center justify-between border-t border-surface-100 px-4 py-2 text-sm font-bold"
          :style="{ background: `color-mix(in srgb, var(--p-${renk}-50) 85%, var(--p-surface-0))` }"
        >
          <span
            >{{ ay.etiket }}
            <span class="text-xs font-medium text-surface-500">· {{ ay.adet }} işlem</span></span
          >
          <span class="tabular-nums" data-test="gecmis-ay-toplam">{{ tl(ay.toplam) }}</span>
        </h3>
        <ul>
          <li
            v-for="s in ay.islemler"
            :key="s.id"
            class="flex items-center justify-between gap-3 px-4 py-2 text-sm"
          >
            <span class="flex min-w-0 flex-wrap items-center gap-x-1.5 text-surface-600">
              <span class="tabular-nums">{{ gunAy(s.islem_tarihi) }}</span>
              <span class="text-surface-300">·</span>
              <KartEtiketi :kart-id="s.kart_id" />
              <span v-if="s.taksit_sayisi" class="text-xs text-surface-500"
                >· taksit {{ s.taksit_no }}/{{ s.taksit_sayisi }}</span
              >
              <span v-if="s.tur === 'iade'" class="text-xs text-surface-500">· İade</span>
            </span>
            <span
              class="font-semibold whitespace-nowrap tabular-nums"
              :class="
                s.etki < 0
                  ? 'text-emerald-700'
                  : s.etki === 0
                    ? 'text-surface-400'
                    : 'text-surface-900'
              "
              >{{ s.etki < 0 ? '−' : '' }}{{ tl(s.tutar) }}</span
            >
          </li>
        </ul>
      </section>
    </div>
  </Dialog>
</template>
