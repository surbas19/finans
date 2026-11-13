/**
 * Telefona kurulum (ana ekrana ekle) için cihaz / mod tespiti. Saf fonksiyonlar: tarayıcı değerleri dışarıdan verilir.
 */

/** iPhone / iPad. iPadOS 13+ Safari kendini masaüstü Mac gibi tanıtır; dokunmatik olmasından ayırt edilir. */
export function iosMu(userAgent: string, dokunmaNoktasi = 0): boolean {
  return /iPhone|iPad|iPod/i.test(userAgent) || (/Macintosh/i.test(userAgent) && dokunmaNoktasi > 1)
}

/** Telefon / tablet (iOS ya da Android) */
export function mobilMu(userAgent: string, dokunmaNoktasi = 0): boolean {
  return iosMu(userAgent, dokunmaNoktasi) || /Android/i.test(userAgent)
}

/** Uygulama ana ekrandan (tam ekran) mı açıldı: standart `display-mode` ya da eski iOS `navigator.standalone` */
export function kuruluMu(standaloneEslesiyor: boolean, iosStandalone?: boolean): boolean {
  return standaloneEslesiyor || iosStandalone === true
}
