import { describe, expect, it } from 'vitest'
import type { HesapOzeti, Pozisyon } from '@/api/finans'
import {
  DIGER_RENGI,
  HALKA_RENKLERI,
  hesapDilimleri,
  hesapRengi,
  varlikDilimleri,
} from '@/utils/halka'

function pozisyon(id: number, kod: string, deger: number | null): Pozisyon {
  return {
    hesap_id: 1,
    varlik: {
      id,
      tur: 'hisse',
      kod,
      ad: `${kod} adı`,
      birim: 'pay',
      para_birimi: 'TRY',
      kaynak: 'yahoo',
      gecikmeli: true,
      fiyat: null,
    },
    miktar: 1,
    maliyeti_bilinmeyen_miktar: 0,
    ortalama_maliyet: null,
    maliyet: null,
    fiyat_tl: null,
    deger,
    kz: null,
    kz_yuzde: null,
    gunluk_degisim: null,
    gerceklesen_kz: null,
    gerceklesen_kz_eksik: false,
  }
}

function hesap(id: number, ad: string, deger: number, renk: string | null): HesapOzeti {
  return {
    id,
    ad,
    tur: 'araci_kurum',
    renk,
    sira: id,
    not: null,
    varlik_sayisi: 1,
    deger,
    maliyet: 0,
    kz: 0,
    kz_yuzde: null,
    gunluk_degisim: 0,
    gerceklesen_kz: 0,
    fiyatsiz: 0,
    maliyeti_eksik: false,
  }
}

describe('varlikDilimleri', () => {
  it('büyükten küçüğe ilk 6 renkli, kalanı gri "Diğer"; değersizler atlanır', () => {
    const liste = [
      pozisyon(1, 'A', 10),
      pozisyon(2, 'B', 80),
      pozisyon(3, 'C', null),
      pozisyon(4, 'D', 0),
      pozisyon(5, 'E', 50),
      pozisyon(6, 'F', 40),
      pozisyon(7, 'G', 30),
      pozisyon(8, 'H', 20),
      pozisyon(9, 'I', 15),
      pozisyon(10, 'J', 5),
    ]
    const { dilimler, renkler } = varlikDilimleri(liste)

    expect(dilimler.map((d) => d.etiket)).toEqual(['B', 'E', 'F', 'G', 'H', 'I', 'Diğer'])
    expect(dilimler[dilimler.length - 1]).toEqual({ etiket: 'Diğer', deger: 15, renk: DIGER_RENGI })
    expect(dilimler.slice(0, 6).map((d) => d.renk)).toEqual([...HALKA_RENKLERI])
    // Satır şeridi dilimle aynı renk; Diğer'e düşenler gri, değersizler haritada yok
    expect(renkler.get(2)).toBe(HALKA_RENKLERI[0])
    expect(renkler.get(1)).toBe(DIGER_RENGI)
    expect(renkler.get(10)).toBe(DIGER_RENGI)
    expect(renkler.has(3)).toBe(false)
    expect(renkler.has(4)).toBe(false)
  })

  it('az varlıkta "Diğer" yok; boş listede dilim yok', () => {
    expect(varlikDilimleri([pozisyon(1, 'A', 10), pozisyon(2, 'B', 5)]).dilimler).toHaveLength(2)
    expect(varlikDilimleri([]).dilimler).toEqual([])
  })
})

describe('hesapDilimleri', () => {
  it('değeri olan her hesap kendi renginde; renksiz hesap teal', () => {
    const dilimler = hesapDilimleri([
      hesap(1, 'Yatırım', 700, 'violet'),
      hesap(2, 'Boş', 0, 'sky'),
      hesap(3, 'Kasa', 300, null),
    ])

    expect(dilimler).toEqual([
      { etiket: 'Yatırım', deger: 700, renk: '--p-violet-500' },
      { etiket: 'Kasa', deger: 300, renk: '--p-teal-500' },
    ])
    expect(hesapRengi({ renk: 'amber' })).toBe('--p-amber-500')
  })
})
