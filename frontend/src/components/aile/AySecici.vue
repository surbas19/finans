<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { buAy, donemEkle, donemEtiketi, gecerliDonem } from '@/utils/donem'

/**
 * Ay çarkı: Bütçe sayfalarının en üstündeki tek tip, çerçevesiz, 50 px yüksekliğinde dikey ay seçici. Ortadaki ay
 * seçilidir; üstte önceki, altta sonraki ay yarım ve soluk görünür. Kaydırınca ortaya oturan ay, soluk aya dokununca
 * o ay seçilir; sağda önceki / sonraki ay düğmeleri, klavyede Yukarı / Aşağı. `ileriSinir` verilirse (varsayılan bu
 * ay; `null` sınırsız) sonrası listede yoktur. `bos`: "Tüm dönemler" yazar (herhangi bir seçim bir ayı seçer);
 * `pasif`: soluk ve kapalı. Soldaki `#ek` yuvası ek düğme içindir ("Tümü", "En yeni").
 */
const props = withDefaults(
  defineProps<{ modelValue: string; ileriSinir?: string | null; bos?: boolean; pasif?: boolean }>(),
  { ileriSinir: undefined, bos: false, pasif: false },
)
// defineModel aynı değeri yaymaz; "Tüm dönemler"deyken o anki aya dönmek için her seçim yayılır
const emit = defineEmits<{ 'update:modelValue': [donem: string] }>()

/** Satır yüksekliği (px); çark 50 px: ortada bir tam satır, üstte ve altta yarım satır */
const SATIR = 24
/** Liste, merkez ayın bu kadar öncesini ve sonrasını içerir; kenara yaklaşınca yeniden ortalanır */
const YARIM = 24

const secili = computed(() => (gecerliDonem(props.modelValue) ? props.modelValue : buAy()))
const sinir = computed(() => (props.ileriSinir === undefined ? buAy() : props.ileriSinir))
const ileriKapali = computed(() => sinir.value !== null && secili.value >= sinir.value)

const merkez = ref(secili.value)
const aylar = computed(() => {
  const liste: string[] = []
  for (let i = -YARIM; i <= YARIM; i++) {
    const d = donemEkle(merkez.value, i)
    if (sinir.value !== null && d > sinir.value) break
    liste.push(d)
  }

  return liste
})

const kutu = ref<HTMLElement | null>(null)
/** Kaydırma sürerken ortadaki satır (vurgu anında değişsin) */
const ortadaki = ref(0)
/** Kodla başlatılan kaydırmanın hedefi: oraya varınca seçim yayılmaz */
let hedef: number | null = null
let zamanlayici: ReturnType<typeof setTimeout> | undefined

function azHareket(): boolean {
  return (
    typeof window.matchMedia === 'function' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches
  )
}

function kaydir(i: number, yumusak: boolean): void {
  ortadaki.value = i
  const el = kutu.value
  if (!el || i < 0) return
  const ust = i * SATIR
  if (Math.abs(el.scrollTop - ust) < 1) return
  hedef = i
  if (yumusak && !azHareket() && typeof el.scrollTo === 'function') {
    el.scrollTo({ top: ust, behavior: 'smooth' })
  } else {
    el.scrollTop = ust
  }
}

/** Seçili ay listede yoksa ya da kenara yakınsa liste onun çevresinde yeniden kurulur */
async function ortala(yumusak: boolean): Promise<void> {
  const liste = aylar.value
  const i = liste.indexOf(secili.value)
  const sonaYakin = liste[liste.length - 1] !== sinir.value && i > liste.length - 7
  if (i === -1 || i < 6 || sonaYakin) {
    merkez.value = secili.value
    await nextTick()
    kaydir(aylar.value.indexOf(secili.value), false)
    return
  }
  kaydir(i, yumusak)
}

watch(secili, () => void ortala(true))
watch(sinir, () => {
  merkez.value = secili.value
  void nextTick(() => kaydir(aylar.value.indexOf(secili.value), false))
})
onMounted(() => kaydir(aylar.value.indexOf(secili.value), false))
onBeforeUnmount(() => clearTimeout(zamanlayici))

function sec(d: string | undefined): void {
  if (!d || props.pasif) return
  if (sinir.value !== null && d > sinir.value) return
  if (d !== secili.value || props.bos) emit('update:modelValue', d)
}

function kaydirmaBitti(): void {
  clearTimeout(zamanlayici)
  const el = kutu.value
  if (!el) return
  const i = Math.max(0, Math.min(aylar.value.length - 1, Math.round(el.scrollTop / SATIR)))
  if (hedef !== null && i === hedef) {
    hedef = null
    return
  }
  hedef = null
  sec(aylar.value[i])
}

function kaydiriliyor(): void {
  const el = kutu.value
  if (!el) return
  ortadaki.value = Math.round(el.scrollTop / SATIR)
  clearTimeout(zamanlayici)
  // scrollend olmayan tarayıcılar için: kaydırma 120 ms durunca
  zamanlayici = setTimeout(kaydirmaBitti, 120)
}

function tus(e: KeyboardEvent): void {
  if (e.key === 'ArrowUp') {
    e.preventDefault()
    sec(donemEkle(secili.value, -1))
  } else if (e.key === 'ArrowDown') {
    e.preventDefault()
    sec(donemEkle(secili.value, 1))
  }
}
</script>

<template>
  <div
    class="ay-carki relative h-[50px] w-full shrink-0 select-none"
    :class="pasif ? 'pointer-events-none opacity-40' : ''"
    :aria-disabled="pasif || undefined"
    data-test="ay-carki"
  >
    <div class="absolute inset-y-0 left-0 z-10 flex items-center">
      <slot name="ek" />
    </div>
    <div
      ref="kutu"
      class="ay-carki-liste absolute inset-y-0 right-10 left-10 overflow-y-auto overscroll-contain py-[13px] outline-none"
      role="listbox"
      tabindex="0"
      aria-label="Ay"
      @scroll.passive="kaydiriliyor"
      @scrollend="kaydirmaBitti"
      @keydown="tus"
    >
      <div
        v-for="(d, i) in aylar"
        :key="d"
        role="option"
        :aria-selected="!bos && d === secili"
        class="ay-carki-satir flex h-6 cursor-pointer items-center justify-center whitespace-nowrap tabular-nums transition-[color,font-size] duration-150"
        :class="
          i === ortadaki
            ? bos
              ? 'text-transparent'
              : 'text-[19px] font-extrabold tracking-tight text-primary-700'
            : 'text-xs font-medium text-surface-400'
        "
        :data-test="!bos && d === secili ? 'ay-secici' : undefined"
        @click="sec(d)"
      >
        {{ donemEtiketi(d) }}
      </div>
    </div>
    <span
      v-if="bos"
      class="pointer-events-none absolute inset-x-10 top-[13px] flex h-6 items-center justify-center text-[17px] font-extrabold text-primary-700"
      aria-live="polite"
      data-test="ay-secici-bos"
      >Tüm dönemler</span
    >
    <span v-else class="sr-only" aria-live="polite">{{ donemEtiketi(secili) }}</span>
    <div class="absolute inset-y-0 right-0 flex w-9 flex-col items-center justify-center">
      <button
        type="button"
        class="flex h-6 w-8 items-center justify-center rounded-full text-surface-400 transition-colors hover:bg-primary-50 hover:text-primary-700 focus-visible:outline-2 focus-visible:outline-primary-500 disabled:opacity-30 disabled:hover:bg-transparent"
        aria-label="Önceki ay"
        @click="sec(donemEkle(secili, -1))"
      >
        <i class="pi pi-chevron-up text-[11px]" />
      </button>
      <button
        type="button"
        class="flex h-6 w-8 items-center justify-center rounded-full text-surface-400 transition-colors hover:bg-primary-50 hover:text-primary-700 focus-visible:outline-2 focus-visible:outline-primary-500 disabled:opacity-30 disabled:hover:bg-transparent"
        aria-label="Sonraki ay"
        :disabled="ileriKapali"
        @click="sec(donemEkle(secili, 1))"
      >
        <i class="pi pi-chevron-down text-[11px]" />
      </button>
    </div>
  </div>
</template>

<style scoped>
.ay-carki-liste {
  scroll-snap-type: y mandatory;
  scrollbar-width: none;
  -webkit-mask-image: linear-gradient(
    to bottom,
    transparent 0,
    #000 35%,
    #000 65%,
    transparent 100%
  );
  mask-image: linear-gradient(to bottom, transparent 0, #000 35%, #000 65%, transparent 100%);
}
.ay-carki-liste::-webkit-scrollbar {
  display: none;
}
.ay-carki-satir {
  scroll-snap-align: center;
}
.ay-carki-liste:focus-visible .ay-carki-satir[aria-selected='true'] {
  text-decoration: underline;
  text-underline-offset: 4px;
}
@media (prefers-reduced-motion: reduce) {
  .ay-carki-satir {
    transition: none;
  }
}
</style>
