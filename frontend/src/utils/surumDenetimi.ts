import type { Router } from 'vue-router'
import { finansApi } from '@/api/finans'

/**
 * Yeni sürüm yayınlanınca açık kalmış uygulamanın (telefonda ana ekrandan açılan, ya da tarayıcı sekmesi) kendini
 * yenilemesi. Yayında eski arayüz dosyaları silinir (deploy/paket-ac.php); eski kodla yeni sayfaya geçilemez.
 *
 * - Denetim: uygulama öne gelince (en sık 30 sn'de bir) ve sayfa geçişinde (en sık 5 dk'da bir) `/api/v1/surum`
 *   derleme sürümüyle (`__SURUM__`) karşılaştırılır. Yerelde ve sürüm bilinmiyorsa kapalı.
 * - Yeni sürüm varsa uygun anda (açık pencere yok, sayfada dolu yazı alanı yok) sayfa yenilenir; değilse kullanıcı
 *   sonraki sayfaya geçerken o sayfa tam yüklenir. Yazılan hiçbir şey kaybolmaz.
 * - Eski dosya yüklenemezse (yayından önce açılmış sekme) hedef sayfa tam yüklenir.
 * - Döngü koruması: aynı sunucu sürümü için en çok saatte bir, dosya hatası için 20 sn'de bir yenilenir;
 *   sessionStorage yoksa kendiliğinden yenilenmez.
 */

const ANAHTAR = 'finans.surumYenileme'
const ONE_GELINCE_ARALIK = 30_000
const GECISTE_ARALIK = 5 * 60_000
const YAZI_TURLERI = new Set(['', 'text', 'password', 'email', 'number', 'search', 'tel', 'url'])
const ACIK_KATMANLAR =
  '.p-dialog-mask, .p-overlay-mask, .p-select-overlay, .p-datepicker-panel, .p-popover'

type Depo = Pick<Storage, 'getItem' | 'setItem'>

/** Sunucuda bu derlemeden farklı bir sürüm var mı (biri bilinmiyorsa hayır) */
export function yeniSurumMu(sunucu: string | null | undefined, istemci: string): boolean {
  return !!sunucu && !!istemci && sunucu !== istemci
}

/** Yenilemek yazılanı kaybettirmez mi: açık pencere / açılır panel yok, sayfadaki yazı alanları boş */
export function yenilemeGuvenliMi(belge: Document): boolean {
  if (belge.querySelector(ACIK_KATMANLAR)) return false
  for (const alan of belge.querySelectorAll<HTMLInputElement | HTMLTextAreaElement>(
    'main input, main textarea',
  )) {
    const tur = alan instanceof HTMLTextAreaElement ? '' : alan.type
    if (alan.readOnly || alan.disabled || !YAZI_TURLERI.has(tur)) continue
    if (alan.value.trim() !== '') return false
  }

  return true
}

/**
 * Döngü koruması. Aynı nedenle (ör. `surum:b77c1e8`) yakın zamanda yenilendiyse hayır; evetse zamanı yazar.
 * Depolama yoksa ya da hata verirse hayır (hatırlayamayan sayfa sonsuz yenilenebilir).
 */
export function yenilemeIzinliMi(neden: string, simdi: number, depo: Depo | null): boolean {
  if (!depo) return false
  try {
    const onceki = JSON.parse(depo.getItem(ANAHTAR) ?? 'null') as {
      neden: string
      zaman: number
    } | null
    const sure = neden.startsWith('surum:') ? 60 * 60_000 : 20_000
    if (onceki && onceki.neden === neden && simdi - onceki.zaman < sure) return false
    depo.setItem(ANAHTAR, JSON.stringify({ neden, zaman: simdi }))

    return true
  } catch {
    return false
  }
}

/** Tembel yüklenen dosya (sayfa kodu / stil) alınamadı mı */
export function dinamikYuklemeHatasiMi(hata: unknown): boolean {
  const mesaj = hata instanceof Error ? hata.message : String(hata)

  return /dynamically imported module|Importing a module script failed|Unable to preload CSS|Loading chunk/i.test(
    mesaj,
  )
}

function oturumDeposu(): Depo | null {
  try {
    return window.sessionStorage
  } catch {
    return null
  }
}

/** main.ts'te bir kez çağrılır */
export function surumDenetiminiKur(router: Router): void {
  const istemci = __SURUM__
  const etkin = !import.meta.env.DEV && istemci !== ''
  let bekleyen: string | null = null
  let sonDenetim = Date.now()
  let denetleniyor = false
  let gecisSuruyor = false

  function tamYukle(adres: string, neden: string): boolean {
    if (!yenilemeIzinliMi(neden, Date.now(), oturumDeposu())) return false
    if (adres === window.location.pathname + window.location.search) window.location.reload()
    else window.location.assign(adres)

    return true
  }

  function uygunsaYenile(): void {
    if (bekleyen && document.visibilityState === 'visible' && yenilemeGuvenliMi(document)) {
      tamYukle(window.location.pathname + window.location.search, `surum:${bekleyen}`)
    }
  }

  async function denetle(enAzAralik: number): Promise<void> {
    if (!etkin || denetleniyor || Date.now() - sonDenetim < enAzAralik) return
    denetleniyor = true
    sonDenetim = Date.now()
    try {
      const sunucu = await finansApi.surum()
      if (yeniSurumMu(sunucu, istemci)) {
        bekleyen = sunucu
        uygunsaYenile()
      }
    } catch {
      // Bağlantı yok / sunucu yanıt vermedi: bir sonraki fırsatta yeniden denenir.
    } finally {
      denetleniyor = false
    }
  }

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') void denetle(ONE_GELINCE_ARALIK)
  })

  // Yeni sürüm bekliyorsa ve şimdi yenilemek uygun değilse: kullanıcı başka sayfaya geçerken o sayfa tam yüklenir.
  router.beforeEach((hedef, nereden) => {
    gecisSuruyor = true
    if (bekleyen && nereden.matched.length > 0 && tamYukle(hedef.fullPath, `surum:${bekleyen}`)) {
      return false
    }

    return true
  })
  router.afterEach(() => {
    gecisSuruyor = false
    void denetle(GECISTE_ARALIK)
  })
  router.onError((hata, hedef) => {
    gecisSuruyor = false
    if (dinamikYuklemeHatasiMi(hata)) tamYukle(hedef.fullPath, 'dosya-hatasi')
  })
  // Sayfa geçişi dışındaki tembel yüklemeler (ör. grafik kütüphanesi): bulunduğu sayfa yeniden yüklenir.
  window.addEventListener('vite:preloadError', (olay) => {
    if (gecisSuruyor) return
    if (tamYukle(window.location.pathname + window.location.search, 'dosya-hatasi')) {
      olay.preventDefault()
    }
  })
}
