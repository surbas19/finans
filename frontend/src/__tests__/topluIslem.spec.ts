import { describe, expect, it } from 'vitest'
import type { Varlik, VarlikTuru } from '@/api/finans'
import { satirlariCoz, sayiCoz, tarihCoz } from '@/utils/topluIslem'

function varlik(id: number, tur: VarlikTuru, kod: string, ad: string): Varlik {
  return {
    id,
    tur,
    kod,
    ad,
    birim: 'adet',
    para_birimi: 'TRY',
    kaynak: 'elle',
    gecikmeli: false,
    fiyat: null,
  }
}

const KATALOG = [
  varlik(1, 'altin', 'GRAM', 'Gram altın'),
  varlik(2, 'altin', 'CEYREK', 'Çeyrek altın'),
  varlik(3, 'altin', 'GUMUS', 'Gümüş'),
  varlik(4, 'doviz', 'USD', 'ABD doları'),
  varlik(5, 'hisse', 'THYAO', 'Türk Hava Yolları A.O.'),
  varlik(6, 'altin', 'ATA', 'Ata altın'),
  varlik(7, 'hisse', 'ATA', 'Ata Yatırım'),
]
const BUGUN = '2026-11-13'
const coz = (metin: string, varsayilan = BUGUN) => satirlariCoz(metin, KATALOG, varsayilan, BUGUN)

describe('sayiCoz', () => {
  it('Türkçe ve noktalı ondalık', () => {
    expect(sayiCoz('1.234,56')).toEqual({ deger: 1234.56, belirsiz: false })
    expect(sayiCoz('0,015')).toEqual({ deger: 0.015, belirsiz: false })
    expect(sayiCoz('12.5')).toEqual({ deger: 12.5, belirsiz: false })
    expect(sayiCoz('0.015')).toEqual({ deger: 0.015, belirsiz: false })
    expect(sayiCoz('1.234.567')).toEqual({ deger: 1234567, belirsiz: false })
    expect(sayiCoz('₺ 3.850 TL').deger).toBe(3850)
    expect(sayiCoz('25')).toEqual({ deger: 25, belirsiz: false })
  })

  it('tek nokta + 3 hane binlik sayılır ama belirsizdir', () => {
    expect(sayiCoz('1.500')).toEqual({ deger: 1500, belirsiz: true })
  })

  it.each(['', 'abc', '-5', '1,2,3', '1.2.3', '12,', '1,5.0', '12.'])('geçersiz: "%s"', (s) => {
    expect(sayiCoz(s).deger).toBeNull()
  })
})

describe('tarihCoz', () => {
  it('biçimler ve takvim denetimi', () => {
    expect(tarihCoz('01.03.2025')).toBe('2025-03-01')
    expect(tarihCoz('1/3/2025')).toBe('2025-03-01')
    expect(tarihCoz('2025-03-01')).toBe('2025-03-01')
    expect(tarihCoz('31.02.2025')).toBeNull()
    expect(tarihCoz('2025.03.01')).toBeNull()
    expect(tarihCoz('dün')).toBeNull()
  })
})

describe('satirlariCoz', () => {
  it('örnek metin: kod, miktar, fiyat, tarih, not', () => {
    const sonuc = coz(
      [
        'GRAM;25',
        'CEYREK;4;3850',
        'USD;1.500;32,40;01.03.2025',
        'THYAO;100;245,5;;Eski hesaptan',
      ].join('\n'),
    )

    expect(sonuc.map((s) => s.hata)).toEqual([null, null, null, null])
    expect(sonuc[0]).toMatchObject({ satirNo: 1, miktar: 25, birimFiyat: null, tarih: BUGUN })
    expect(sonuc[0]!.varlik?.id).toBe(1)
    expect(sonuc[1]).toMatchObject({ miktar: 4, birimFiyat: 3850 })
    expect(sonuc[2]).toMatchObject({ miktar: 1500, birimFiyat: 32.4, tarih: '2025-03-01' })
    expect(sonuc[2]!.uyari).toContain('1.500')
    expect(sonuc[3]).toMatchObject({ birimFiyat: 245.5, tarih: BUGUN, not: 'Eski hesaptan' })
  })

  it('ad ile ve Türkçe harf farkı gözetmeden eşleşir; sekmeyle ayrılmış satır', () => {
    const sonuc = coz('çeyrek;2\ngümüş;100\ngram altin\t10')

    expect(sonuc.map((s) => s.varlik?.id)).toEqual([2, 3, 1])
    expect(sonuc.every((s) => s.hata === null)).toBe(true)
  })

  it('boş, yorum ve başlık satırlarını atlar; satır numarası metindekidir', () => {
    const sonuc = coz('Kod;Miktar;Fiyat\n\n# kasadakiler\nGRAM;1')

    expect(sonuc).toHaveLength(1)
    expect(sonuc[0]!.satirNo).toBe(4)
  })

  it('bilinmeyen ad, belirsiz kod, eksik alan', () => {
    const [bilinmeyen, belirsiz, eksik] = coz('Türk Telekom;10\nATA;1\nGRAM')

    expect(bilinmeyen!.hata).toContain('Katalogda yok: Türk Telekom')
    expect(bilinmeyen!.hata).toContain('kodunu yazın')
    expect(bilinmeyen!.yeniKod).toBeNull()
    expect(belirsiz!.hata).toContain('Birden çok varlık')
    expect(eksik!.hata).toContain('Kod ve miktar gerekli')
    // Adıyla yazılınca belirsizlik kalkar
    expect(coz('Ata Yatırım;1')[0]!.varlik?.id).toBe(7)
  })

  it('katalogda olmayan kod hata değil, eklerken aranacak yeni kod', () => {
    const [hisse, turkce, kisa] = coz('asels;10;150\nşok;5\nX;1')

    expect(hisse).toMatchObject({
      varlik: null,
      yeniKod: 'ASELS',
      miktar: 10,
      birimFiyat: 150,
      hata: null,
    })
    // Türkçe harfler sadeleşir (sunucu yalnızca A-Z, 0-9 kabul eder)
    expect(turkce).toMatchObject({ yeniKod: 'SOK', hata: null })
    // Kod en az 2 karakter
    expect(kisa!.yeniKod).toBeNull()
    expect(kisa!.hata).toContain('Katalogda yok: X')
  })

  it('kaynakta bulunamayan yeni kod hatalı olur', () => {
    const [bulunamayan, digeri] = satirlariCoz(
      'ASELSAN;10\nSOL;2',
      KATALOG,
      BUGUN,
      BUGUN,
      new Set(['ASELSAN']),
    )

    expect(bulunamayan!.yeniKod).toBe('ASELSAN')
    expect(bulunamayan!.hata).toContain("ASELSAN Borsa İstanbul'da ve kriptoda bulunamadı")
    expect(digeri).toMatchObject({ yeniKod: 'SOL', hata: null })
  })

  it('sayı, tarih ve alan sayısı hataları', () => {
    const sonuc = coz(
      [
        'GRAM;0',
        'GRAM;on',
        'GRAM;1;-5',
        'GRAM;1;;14.11.2026',
        'GRAM;1;;30.02.2025',
        'GRAM;1;2;;not;fazla',
      ].join('\n'),
    )

    expect(sonuc[0]!.hata).toContain('sıfırdan büyük')
    expect(sonuc[1]!.hata).toContain('Miktar sayı değil')
    expect(sonuc[2]!.hata).toContain('Birim fiyat sayı değil')
    expect(sonuc[3]!.hata).toContain('İleri tarihli')
    expect(sonuc[4]!.hata).toContain('Tarih anlaşılmadı')
    expect(sonuc[5]!.hata).toContain('En çok 5 alan')
  })

  it('tarihsiz satıra varsayılan tarih verilir; Excel tırnakları ve sondaki boş alanlar', () => {
    const [satir] = coz('"GRAM";"2,5";;', '2025-01-10')

    expect(satir).toMatchObject({ miktar: 2.5, tarih: '2025-01-10', hata: null })
  })
})
