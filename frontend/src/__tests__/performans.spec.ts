import { describe, expect, it } from 'vitest'
import type { PerformansNoktasi } from '@/api/finans'
import { donemKz } from '@/utils/performans'

const nokta = (kz: number, gerceklesen = 0): PerformansNoktasi => ({
  tarih: '2026-11-13',
  deger: 0,
  maliyet: 0,
  kz,
  gerceklesen,
  eksik: false,
})

describe('donemKz', () => {
  it('iki noktadan azsa null', () => {
    expect(donemKz(null)).toBeNull()
    expect(donemKz([])).toBeNull()
    expect(donemKz([nokta(10)])).toBeNull()
  })

  it('son ile ilk noktanın K/Z farkı', () => {
    expect(donemKz([nokta(100), nokta(50), nokta(180)])).toBe(80)
    expect(donemKz([nokta(100), nokta(-20)])).toBe(-120)
  })

  it('satışta gerçekleşene geçen K/Z kaybolmaz', () => {
    // 100 gerçekleşmemiş K/Z vardı; yarısı satıldı (50 gerçekleşti), kalan 60'a çıktı
    expect(donemKz([nokta(100), nokta(60, 50)])).toBe(10)
  })
})
