import { computed, ref, shallowRef } from 'vue'
import { iosMu, kuruluMu, mobilMu } from '@/utils/platform'

/** Chrome / Edge / Samsung Internet'in "uygulamayı yükle" istemi (standart tiplerde yok) */
interface KurulumIstemi extends Event {
  prompt(): Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

// Olay sayfa açılır açılmaz gelir; bileşenler sonradan bağlandığı için modül düzeyinde tutulur.
const istem = shallowRef<KurulumIstemi | null>(null)
const kurulu = ref(false)
let dinleniyor = false

/** main.ts'te uygulama kurulmadan önce bir kez çağrılır. */
export function kurulumDinle(): void {
  if (dinleniyor || typeof window === 'undefined') return
  dinleniyor = true
  kurulu.value = kuruluMu(
    window.matchMedia?.('(display-mode: standalone)').matches ?? false,
    (navigator as Navigator & { standalone?: boolean }).standalone,
  )
  window.addEventListener('beforeinstallprompt', (olay) => {
    // Tarayıcının kendi küçük çubuğu yerine uygulamadaki düğme kullanılır (menüdeki "Yükle" yine durur).
    olay.preventDefault()
    istem.value = olay as KurulumIstemi
  })
  window.addEventListener('appinstalled', () => {
    kurulu.value = true
    istem.value = null
  })
}

/**
 * Ana ekrana ekleme durumu: Android'de istem hazırsa `kur()` açar; iPhone'da yalnızca elle (Paylaş → Ana Ekrana Ekle).
 */
export function useUygulamaKurulumu() {
  const ua = typeof navigator === 'undefined' ? '' : navigator.userAgent
  const dokunma = typeof navigator === 'undefined' ? 0 : navigator.maxTouchPoints
  const ios = iosMu(ua, dokunma)
  const mobil = mobilMu(ua, dokunma)
  const kurulabilir = computed(() => istem.value !== null)

  async function kur(): Promise<boolean> {
    const i = istem.value
    if (!i) return false
    await i.prompt()
    const { outcome } = await i.userChoice
    istem.value = null

    return outcome === 'accepted'
  }

  return { kurulu, kurulabilir, ios, mobil, kur }
}
