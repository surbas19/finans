<script setup lang="ts" generic="T">
import { computed, onBeforeUnmount, onMounted, ref, watch, nextTick } from 'vue'
import Button from 'primevue/button'
import { useDarEkran } from '@/composables/useDarEkran'

/**
 * Yana kaydırmalı paneller (kartlar, gelir / gider…). Telefonda her seferinde tek panel görünür (yandakinin kenarı
 * belli olur, altta noktalar); kaydırınca ortada duran panel seçilir. Geniş ekranda `sutun` kadar panel yan yana,
 * oklarla kaydırılır. Seçili sıra dışarıdan değişirse şerit o panele kayar. Panel içeriği `#panel` slotundan gelir.
 */
const props = withDefaults(
  defineProps<{
    ogeler: T[]
    /** Geniş ekranda yan yana panel sayısı */
    sutun?: 1 | 2 | 3
    etiket?: (oge: T, sira: number) => string
    anahtar?: (oge: T, sira: number) => string | number
    /** Telefonda panel ekranı tam kaplar (yandakinin kenarı görünmez) */
    tamGenislik?: boolean
    /** Telefonda alttaki gösterge noktaları */
    noktalar?: boolean
    /** Paneller aynı boyda (en uzun panel kadar) */
    esitBoy?: boolean
  }>(),
  {
    sutun: 2,
    etiket: undefined,
    anahtar: undefined,
    tamGenislik: false,
    noktalar: true,
    esitBoy: false,
  },
)
const sira = defineModel<number>('sira', { default: 0 })

const dar = useDarEkran()
const serit = ref<HTMLElement | null>(null)
const solaVar = ref(false)
const sagaVar = ref(false)

const panelSinifi = computed(
  () =>
    'shrink-0 snap-center ' +
    (props.tamGenislik ? 'w-full ' : 'w-[88%] ') +
    (props.sutun === 1
      ? 'sm:w-full'
      : props.sutun === 2
        ? 'sm:w-[calc(50%-0.375rem)]'
        : 'sm:w-[calc(50%-0.375rem)] lg:w-[calc(33.333%-0.5rem)]'),
)

function eleman(i: number): HTMLElement | null {
  return (serit.value?.children[i] as HTMLElement | undefined) ?? null
}

function goster(i: number, davranis: ScrollBehavior = 'smooth'): void {
  const s = serit.value
  const el = eleman(i)
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
 * Telefonda seçim kaydırmayla: ortada duran (en az %60'ı görünen) panel, kaydırma 150 ms durunca seçilir. Programla
 * kaydırırken (noktaya dokunma, dışarıdan seçim) yoldaki paneller seçilmez: hedefe varana kadar gözlem yok sayılır.
 */
let gozlemci: IntersectionObserver | null = null
let bekleme: ReturnType<typeof setTimeout> | undefined
let hedef: number | null = null
let hedefZamani: ReturnType<typeof setTimeout> | undefined
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
        const i = Number((k.target as HTMLElement).dataset.sira)
        if (hedef !== null && i !== hedef) continue
        hedef = null
        clearTimeout(bekleme)
        bekleme = setTimeout(() => {
          if (i !== sira.value) {
            kaydirmadan = true
            sira.value = i
          }
        }, 150)
      }
    },
    { root: s, threshold: [0.6] },
  )
  Array.from(s.children).forEach((el) => gozlemci!.observe(el))
}

function sec(i: number): void {
  if (i === sira.value) {
    goster(i)

    return
  }
  hedef = i
  clearTimeout(hedefZamani)
  hedefZamani = setTimeout(() => (hedef = null), 1000)
  sira.value = i
}

watch(sira, async (i) => {
  if (kaydirmadan) {
    kaydirmadan = false

    return
  }
  await nextTick()
  goster(i)
})

watch(
  () => props.ogeler.length,
  async () => {
    await nextTick()
    gozlemiKur()
    oklariGuncelle()
  },
)
watch(dar, async () => {
  await nextTick()
  gozlemiKur()
  oklariGuncelle()
})

onMounted(async () => {
  await nextTick()
  gozlemiKur()
  oklariGuncelle()
  if (sira.value > 0) goster(sira.value, 'instant')
})
onBeforeUnmount(() => {
  gozlemci?.disconnect()
  clearTimeout(bekleme)
  clearTimeout(hedefZamani)
})
</script>

<template>
  <div class="relative" data-test="kaydirmali-paneller" aria-roledescription="carousel">
    <div
      ref="serit"
      class="flex snap-x snap-mandatory gap-3 overflow-x-auto py-1 [scrollbar-width:none] sm:mx-0 sm:px-0.5 [&::-webkit-scrollbar]:hidden"
      :class="[esitBoy ? 'items-stretch' : 'items-start', tamGenislik ? '' : '-mx-4 px-4']"
      data-test="kaydirici-serit"
      @scroll.passive="oklariGuncelle"
    >
      <div
        v-for="(o, i) in ogeler"
        :key="anahtar ? anahtar(o, i) : i"
        :data-sira="i"
        :class="panelSinifi"
        role="group"
        aria-roledescription="slide"
        :aria-label="etiket ? etiket(o, i) : `Panel ${i + 1} / ${ogeler.length}`"
        data-test="kaydirici-panel"
      >
        <slot name="panel" :oge="o" :sira="i" :secili="i === sira" />
      </div>
    </div>

    <template v-if="!dar && (solaVar || sagaVar)">
      <Button
        v-if="solaVar"
        icon="pi pi-chevron-left"
        rounded
        severity="secondary"
        class="!absolute top-1/2 -left-3 -translate-y-1/2 shadow"
        aria-label="Öncekine kaydır"
        @click="kaydir(-1)"
      />
      <Button
        v-if="sagaVar"
        icon="pi pi-chevron-right"
        rounded
        severity="secondary"
        class="!absolute top-1/2 -right-3 -translate-y-1/2 shadow"
        aria-label="Sonrakine kaydır"
        @click="kaydir(1)"
      />
    </template>

    <div
      v-if="noktalar && dar && ogeler.length > 1"
      class="mt-2 flex justify-center gap-1.5"
      role="tablist"
      aria-label="Paneller"
    >
      <button
        v-for="(o, i) in ogeler"
        :key="`n-${anahtar ? anahtar(o, i) : i}`"
        type="button"
        role="tab"
        class="h-2 rounded-full transition-all"
        :class="i === sira ? 'w-5 bg-primary-600' : 'w-2 bg-surface-300'"
        :aria-selected="i === sira"
        :aria-label="etiket ? etiket(o, i) : `Panel ${i + 1}`"
        data-test="kaydirici-nokta"
        @click="sec(i)"
      />
    </div>
  </div>
</template>
