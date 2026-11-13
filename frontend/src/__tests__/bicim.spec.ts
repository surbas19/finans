import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import DegisimEtiketi from '@/components/DegisimEtiketi.vue'
import { bugun, degisimRengi, fiyat, isaretliTl, miktar, neZaman, tl, yuzde } from '@/utils/bicim'

// Intl çıktısındaki bölünmez boşlukları sadeleştirir
const sade = (metin: string) => metin.replace(/ | /g, ' ')

describe('bicim', () => {
  it('TL ve işaretli TL', () => {
    expect(sade(tl(1234.5))).toBe('₺1.234,50')
    expect(tl(null)).toBe('—')
    expect(sade(isaretliTl(10))).toBe('+₺10,00')
    expect(sade(isaretliTl(-3.456))).toBe('-₺3,46')
    expect(sade(isaretliTl(0))).toBe('₺0,00')
  })

  it('fiyat büyüklüğüne göre hane', () => {
    expect(sade(fiyat(6310.01))).toBe('₺6.310,01')
    expect(sade(fiyat(50.36))).toBe('₺50,36')
    expect(sade(fiyat(50.3612))).toBe('₺50,3612')
    expect(sade(fiyat(0.003148))).toBe('₺0,003148')
    expect(sade(fiyat(3917.5, 'USD'))).toBe('$3.917,50')
  })

  it('miktar ve yüzde', () => {
    expect(miktar(0.01)).toBe('0,01')
    expect(miktar(1500)).toBe('1.500')
    expect(yuzde(1.234)).toBe('+%1,23')
    expect(yuzde(-0.5)).toBe('-%0,50')
    expect(yuzde(0)).toBe('%0,00')
    expect(degisimRengi(2)).toBe('text-emerald-600')
    expect(degisimRengi(-2)).toBe('text-red-600')
    expect(degisimRengi(null)).toBe('text-surface-500')
  })

  it('ne zaman ve bugün (yerel saat)', () => {
    const simdi = new Date('2026-11-13T12:00:00')
    expect(neZaman('2026-11-13T11:58:00', simdi)).toBe('2 dk önce')
    expect(neZaman('2026-11-13T09:00:00', simdi)).toBe('3 sa önce')
    expect(neZaman('2026-11-11T12:00:00', simdi)).toBe('2 gün önce')
    expect(bugun(new Date('2026-01-05T00:30:00'))).toBe('2026-01-05')
  })
})

describe('DegisimEtiketi', () => {
  it('tutar ve yüzdeyi renkli gösterir', () => {
    const w = mount(DegisimEtiketi, { props: { tutar: 1500, yuzde: 12.5 } })
    expect(sade(w.text())).toContain('+₺1.500,00')
    expect(w.text()).toContain('+%12,50')
    expect(w.classes()).toContain('text-emerald-600')
  })

  it('düşüşte kırmızı, bilinmeyende tire', () => {
    expect(mount(DegisimEtiketi, { props: { yuzde: -1 } }).classes()).toContain('text-red-600')
    expect(mount(DegisimEtiketi, { props: { tutar: null } }).text()).toBe('—')
  })
})
