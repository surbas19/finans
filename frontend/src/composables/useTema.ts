import { readonly, ref, type Ref } from 'vue'

/**
 * Görünüm: açık, koyu ya da telefonun / bilgisayarın ayarı (sistem). Seçim bu tarayıcıda saklanır (kişisel kolaylık;
 * sunucuya gitmez). Koyu temada `<html>`'e `koyu` sınıfı eklenir (renkler assets/koyu-tema.css'te). İlk boyamada
 * yanıp sönmesin diye aynı karar index.html'deki küçük betikte de verilir.
 */
export type Tema = 'sistem' | 'acik' | 'koyu'

const ANAHTAR = 'finans-tema'
const SORGU = '(prefers-color-scheme: dark)'
const ZEMIN = { acik: '#0f766e', koyu: '#020617' }

const secim = ref<Tema>(okunan())
let eslesme: MediaQueryList | null = null
/** Tema her değiştiğinde artar: renkleri CSS değişkeninden okuyan grafikler buna bağlanıp yeniden çizilir */
const surum = ref(0)
export const temaSurumu: Readonly<Ref<number>> = readonly(surum)

function okunan(): Tema {
  try {
    const deger = localStorage.getItem(ANAHTAR)
    return deger === 'acik' || deger === 'koyu' ? deger : 'sistem'
  } catch {
    return 'sistem'
  }
}

function koyuMu(): boolean {
  if (secim.value === 'koyu') return true
  if (secim.value === 'acik') return false
  return eslesme?.matches ?? false
}

function uygula(): void {
  const koyu = koyuMu()
  document.documentElement.classList.toggle('koyu', koyu)
  document
    .querySelector('meta[name="theme-color"]')
    ?.setAttribute('content', koyu ? ZEMIN.koyu : ZEMIN.acik)
  surum.value++
}

/** Uygulama açılırken bir kez: sistem ayarı değişince (gece / gündüz) tema da değişir */
export function temayiBaslat(): void {
  if (typeof window === 'undefined') return
  if (typeof window.matchMedia === 'function') {
    eslesme = window.matchMedia(SORGU)
    eslesme.addEventListener('change', uygula)
  }
  uygula()
}

export function useTema(): { tema: Readonly<Ref<Tema>>; temaSec: (t: Tema) => void } {
  function temaSec(t: Tema): void {
    secim.value = t
    try {
      if (t === 'sistem') localStorage.removeItem(ANAHTAR)
      else localStorage.setItem(ANAHTAR, t)
    } catch {
      // Gizli pencere vb.: seçim yalnız bu oturumda geçerli
    }
    uygula()
  }

  return { tema: readonly(secim), temaSec }
}
