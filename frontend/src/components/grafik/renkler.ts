/** Çizgi grafik serileri için sıralı, birbirinden ayrışan renkler (PrimeVue paleti) */
export const SERI_RENKLERI = [
  'var(--p-sky-500)',
  'var(--p-emerald-500)',
  'var(--p-amber-500)',
  'var(--p-rose-500)',
  'var(--p-violet-500)',
  'var(--p-orange-500)',
  'var(--p-teal-500)',
  'var(--p-pink-500)',
]
/** Toplam çizgisi */
export const TOPLAM_RENGI = 'var(--p-primary-500)'

/** Gelecek ayları çizmemek için: içinde bulunulan yılda bu ayın sırası, geçmiş yılda 11 */
export const sonAyIndeksi = (yil: number): number =>
  yil === new Date().getFullYear()
    ? new Date().getMonth()
    : yil < new Date().getFullYear()
      ? 11
      : -1

export const seriRengi = (sira: number): string => SERI_RENKLERI[sira % SERI_RENKLERI.length]!
