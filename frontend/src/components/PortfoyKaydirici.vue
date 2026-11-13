<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import Button from 'primevue/button'
import DegisimEtiketi from '@/components/DegisimEtiketi.vue'
import VeriGrafigi from '@/components/VeriGrafigi.vue'
import { useDarEkran } from '@/composables/useDarEkran'
import type { HalkaDilimi } from '@/utils/halka'
import { tl } from '@/utils/bicim'

/**
 * Özet'in üstündeki yana kaydırmalı halkalar: ilk slayt bütün hesaplar, sonra her hesap. Telefonda ortada duran
 * slayt seçilidir (yandakinin kenarı görünür, kaydırılabildiği anlaşılır; altta noktalar). Masaüstünde 2–3 slayt
 * yan yana, tıklanan seçilir, oklarla kaydırılır. Seçim dışarıdan değişince şerit o slayta kayar.
 */
export interface PortfoySlayti {
  /** `null`: bütün hesaplar */
  id: number | null
  ad: string
  altBaslik: string
  deger: number
  kz: number | null
  kz_yuzde: number | null
  gunluk_degisim: number | null
  dilimler: HalkaDilimi[]
  /** Halkanın ortasındaki kısa yazı (ör. "Payı" / "%62") */
  merkez: { ust: string; alt: string }
}

const props = defineProps<{ slaytlar: PortfoySlayti[] }>()
const secilen = defineModel<number | null>('secilen', { default: null })

const dar = useDarEkran()
const serit = ref<HTMLElement | null>(null)
const solaVar = ref(false)
const sagaVar = ref(false)

const seciliSira = computed(() =>
  Math.max(
    0,
    props.slaytlar.findIndex((s) => s.id === secilen.value),
  ),
)

function halkaAciklamasi(s: PortfoySlayti): string {
  const toplam = s.dilimler.reduce((t, d) => t + d.deger, 0) || 1
  const parcalar = s.dilimler.map((d) => `${d.etiket} %${Math.round((d.deger / toplam) * 100)}`)

  return `${s.ad} dağılımı: ${parcalar.join(', ') || 'boş'}`
}

/**
 * Grafik verisi yalnızca slaytlar değişince yeniden kurulur: şablonda her çizimde yeni dizi verilseydi seçim her
 * değiştiğinde (çerçeve rengi) bütün halkalar baştan çizilirdi (PrimeVue Chart veri değişince grafiği yeniden kurar).
 */
const grafikler = computed(() =>
  props.slaytlar.map((s) => ({
    etiketler: s.dilimler.map((d) => d.etiket),
    seriler: [
      { ad: s.ad, veri: s.dilimler.map((d) => d.deger), renk: s.dilimler.map((d) => d.renk) },
    ],
    aciklama: halkaAciklamasi(s),
  })),
)

function slaytEleman(sira: number): HTMLElement | null {
  return (serit.value?.children[sira] as HTMLElement | undefined) ?? null
}

/** Telefonda slaytı ortalar; masaüstünde yalnızca görünmüyorsa görünür yapar (tıklananı kaydırmaz) */
function goster(sira: number, davranis: ScrollBehavior = 'smooth'): void {
  const s = serit.value
  const el = slaytEleman(sira)
  if (!s || !el) return
  let sol: number | null
  if (dar.value) {
    sol = el.offsetLeft - (s.clientWidth - el.clientWidth) / 2
  } else if (el.offsetLeft < s.scrollLeft) {
    sol = el.offsetLeft
  } else if (el.offsetLeft + el.clientWidth > s.scrollLeft + s.clientWidth) {
    sol = el.offsetLeft + el.clientWidth - s.clientWidth
  } else {
    sol = null
  }
  if (sol !== null) s.scrollTo({ left: Math.max(0, sol), behavior: davranis })
}

function oklariGuncelle(): void {
  const s = serit.value
  if (!s) return
  solaVar.value = s.scrollLeft > 4
  sagaVar.value = s.scrollLeft + s.clientWidth < s.scrollWidth - 4
}

function kaydir(yon: -1 | 1): void {
  serit.value?.scrollBy({ left: yon * serit.value.clientWidth * 0.9, behavior: 'smooth' })
}

/*
 * Telefonda seçim kaydırmayla: ortada duran (en az %60'ı görünen) slayt, kaydırma 150 ms durunca seçilir.
 * Programla kaydırırken (noktaya dokunma, dışarıdan seçim) yoldaki slaytlar seçilmez: hedefe varana kadar
 * gözlem yok sayılır.
 */
let gozlemci: IntersectionObserver | null = null
let bekleme: ReturnType<typeof setTimeout> | undefined
let hedef: number | null = null
let hedefZamani: ReturnType<typeof setTimeout> | undefined
/** Seçim kaydırmadan geldiyse şeridi yeniden kaydırma */
let kaydirmadan = false

function gozlemiKur(): void {
  gozlemci?.disconnect()
  gozlemci = null
  const s = serit.value
  if (!dar.value || !s || typeof IntersectionObserver === 'undefined') return
  gozlemci = new IntersectionObserver(
    (kayitlar) => {
      for (const k of kayitlar) {
        if (!k.isIntersecting || k.intersectionRatio < 0.6) continue
        const sira = Number((k.target as HTMLElement).dataset.sira)
        if (hedef !== null && sira !== hedef) continue
        hedef = null
        clearTimeout(bekleme)
        bekleme = setTimeout(() => {
          const slayt = props.slaytlar[sira]
          if (slayt && slayt.id !== secilen.value) {
            kaydirmadan = true
            secilen.value = slayt.id
          }
        }, 150)
      }
    },
    { root: s, threshold: [0.6] },
  )
  Array.from(s.children).forEach((el) => gozlemci!.observe(el))
}

function sec(sira: number): void {
  const slayt = props.slaytlar[sira]
  if (!slayt) return
  if (slayt.id === secilen.value) {
    goster(sira)
    return
  }
  secilen.value = slayt.id
}

/** Seçili slayta kayar; varana kadar (en çok 1 sn) yoldaki slaytlar seçilmez */
function seciliyeGit(davranis: ScrollBehavior): void {
  hedef = seciliSira.value
  clearTimeout(hedefZamani)
  hedefZamani = setTimeout(() => (hedef = null), 1000)
  goster(seciliSira.value, davranis)
}

watch(secilen, () => {
  if (kaydirmadan) {
    kaydirmadan = false
    return
  }
  seciliyeGit('smooth')
})

watch([dar, () => props.slaytlar.length], async () => {
  await nextTick()
  gozlemiKur()
  seciliyeGit('instant')
  oklariGuncelle()
})

onMounted(() => {
  gozlemiKur()
  seciliyeGit('instant')
  oklariGuncelle()
  window.addEventListener('resize', oklariGuncelle)
})

onBeforeUnmount(() => {
  gozlemci?.disconnect()
  clearTimeout(bekleme)
  clearTimeout(hedefZamani)
  window.removeEventListener('resize', oklariGuncelle)
})
</script>

<template>
  <section aria-roledescription="carousel" aria-label="Hesaplar" class="flex flex-col gap-2">
    <div class="relative">
      <div
        ref="serit"
        data-test="kaydirici-serit"
        class="relative -mx-4 flex snap-x snap-mandatory gap-3 overflow-x-auto px-4 py-1 [scrollbar-width:none] sm:mx-0 sm:px-0.5 [&::-webkit-scrollbar]:hidden"
        @scroll.passive="oklariGuncelle"
      >
        <article
          v-for="(s, i) in slaytlar"
          :key="s.id ?? 'tumu'"
          :data-sira="i"
          data-test="kaydirici-slayt"
          role="group"
          aria-roledescription="slide"
          :aria-label="`${i + 1} / ${slaytlar.length}: ${s.ad}`"
          :aria-current="s.id === secilen ? 'true' : undefined"
          class="kart w-[88%] shrink-0 cursor-pointer snap-center p-4 transition-shadow sm:w-[calc(50%-0.375rem)] lg:w-[calc(33.333%-0.5rem)]"
          :class="s.id === secilen ? 'ring-2 ring-primary-500' : ''"
          @click="sec(i)"
        >
          <div class="flex flex-col items-center gap-3 min-[360px]:flex-row">
            <div class="relative h-28 w-28 shrink-0 min-[400px]:h-32 min-[400px]:w-32">
              <VeriGrafigi
                v-if="s.dilimler.length > 0"
                tur="doughnut"
                :etiketler="grafikler[i]!.etiketler"
                :seriler="grafikler[i]!.seriler"
                :aciklama="grafikler[i]!.aciklama"
                :gosterge="false"
                :ipucu="false"
                kesim="72%"
                yukseklik="100%"
              />
              <div v-else class="h-full w-full rounded-full border-[10px] border-surface-200" />
              <div
                class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center"
              >
                <span class="text-[11px] leading-tight text-surface-500">{{ s.merkez.ust }}</span>
                <span class="text-sm font-bold text-surface-900 tabular-nums">{{
                  s.merkez.alt
                }}</span>
              </div>
            </div>
            <div class="flex min-w-0 flex-1 flex-col items-center min-[360px]:items-start">
              <p class="max-w-full truncate text-sm font-semibold text-surface-900">{{ s.ad }}</p>
              <p class="max-w-full truncate text-xs text-surface-500">{{ s.altBaslik }}</p>
              <p
                class="mt-1 text-base font-bold tracking-tight whitespace-nowrap text-surface-900 tabular-nums min-[400px]:text-lg"
              >
                {{ tl(s.deger) }}
              </p>
              <DegisimEtiketi :tutar="s.kz" :yuzde="s.kz_yuzde" kucuk />
              <p class="mt-0.5 flex flex-wrap items-center gap-x-1 text-xs text-surface-500">
                Bugün <DegisimEtiketi :tutar="s.gunluk_degisim" kucuk />
              </p>
            </div>
          </div>
        </article>
      </div>
      <template v-if="!dar">
        <Button
          v-if="solaVar"
          icon="pi pi-chevron-left"
          rounded
          severity="secondary"
          class="!absolute top-1/2 -left-3 -translate-y-1/2 shadow-md"
          aria-label="Önceki hesaplar"
          @click="kaydir(-1)"
        />
        <Button
          v-if="sagaVar"
          icon="pi pi-chevron-right"
          rounded
          severity="secondary"
          class="!absolute top-1/2 -right-3 -translate-y-1/2 shadow-md"
          aria-label="Sonraki hesaplar"
          @click="kaydir(1)"
        />
      </template>
    </div>

    <!-- Telefonda noktalar: hangi slayttayız, dokununca o slayta kayar -->
    <div v-if="dar && slaytlar.length > 1" class="flex justify-center">
      <button
        v-for="(s, i) in slaytlar"
        :key="s.id ?? 'tumu'"
        type="button"
        data-test="kaydirici-nokta"
        class="p-1.5"
        :aria-label="s.ad"
        :aria-current="s.id === secilen ? 'true' : undefined"
        @click="sec(i)"
      >
        <span
          class="block h-2 rounded-full transition-all"
          :class="s.id === secilen ? 'w-5 bg-primary-600' : 'w-2 bg-surface-300'"
        />
      </button>
    </div>
  </section>
</template>
