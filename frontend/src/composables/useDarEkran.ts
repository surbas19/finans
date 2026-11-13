import { readonly, ref, type Ref } from 'vue'

/** Tailwind `sm` sınırının altı: telefon */
const SORGU = '(max-width: 639px)'
let durum: Ref<boolean> | null = null

/**
 * Ekran telefon genişliğinde mi (yerleşim için; kurulum için UA'ya bakan `mobilMu` ayrıdır, utils/platform.ts).
 * Modül düzeyinde tek `matchMedia` dinleyicisi; bütün bileşenler aynı değeri paylaşır, döndürünce güncellenir.
 */
export function useDarEkran(): Readonly<Ref<boolean>> {
  if (!durum) {
    const eslesme =
      typeof window !== 'undefined' && typeof window.matchMedia === 'function'
        ? window.matchMedia(SORGU)
        : null
    const deger = ref(eslesme?.matches ?? false)
    eslesme?.addEventListener('change', (olay) => {
      deger.value = olay.matches
    })
    durum = deger
  }

  return readonly(durum)
}
