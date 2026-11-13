import type { PerformansNoktasi } from '@/api/finans'

/**
 * Dönemdeki kâr / zarar: (gerçekleşmemiş + gerçekleşen K/Z) son noktada eksi ilk noktada.
 * Alış K/Z'yi değiştirmez (değer ve maliyet birlikte artar); satışta gerçekleşmemiş K/Z gerçekleşene geçer,
 * ikisi birlikte sayıldığı için para giriş-çıkışı sonucu yanıltmaz. İki noktadan azsa null.
 */
export function donemKz(noktalar: PerformansNoktasi[] | null | undefined): number | null {
  if (!noktalar || noktalar.length < 2) return null
  const ilk = noktalar[0]!
  const son = noktalar[noktalar.length - 1]!

  return Math.round((son.kz + son.gerceklesen - (ilk.kz + ilk.gerceklesen)) * 100) / 100
}
