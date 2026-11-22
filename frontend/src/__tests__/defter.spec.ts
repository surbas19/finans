import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import PrimeVue from 'primevue/config'
import type { DefterYaniti } from '@/api/aile'

const defter = vi.fn<(donem: string) => Promise<DefterYaniti>>()

vi.mock('@/api/aile', async (asil) => ({
  ...(await asil<typeof import('@/api/aile')>()),
  aileApi: { defter: (d: string) => defter(d) },
}))

const stubs = {
  RouterLink: { template: '<a><slot /></a>' },
  AySecici: { template: '<div data-test="ay-secici" />' },
}
const plugins = [PrimeVue]

const yanit = (alan: Partial<DefterYaniti> = {}): DefterYaniti => ({
  donem: '2026-11',
  kapali: true,
  muhurlendi_at: '2026-12-01T00:10:00+03:00',
  ay_sonu: '2026-11-30',
  defter: {
    donem: '2026-11',
    etiket: 'Kasım 2026',
    ozet: { gelir: 59250, gider: 23924.4, tasarruf: 9800, kalan: 25525.6 },
    gruplar: [
      {
        id: 'gelir',
        ad: 'Gelir',
        tur: 'gelir',
        toplam: 59250,
        satirlar: [
          { tarih: '2026-11-01', ad: 'Maaş', tutar: 52400, yazan: 'Ben · 01.11 09:12', not: null },
          {
            tarih: '2026-11-05',
            ad: 'Ek ders',
            tutar: 6850,
            yazan: 'Eşim · 05.11 18:40',
            not: null,
          },
        ],
      },
      {
        id: 'k1',
        ad: 'Market',
        renk: '#7b3fb0',
        tur: 'gider',
        toplam: 925,
        satirlar: [
          {
            tarih: '2026-11-02',
            ad: 'Bakkal',
            tutar: 185,
            yazan: 'Ben · 02.11 08:50',
            not: 'nakit harcama',
          },
          { tarih: '2026-11-15', ad: 'Pazar', tutar: 740, yazan: 'Eşim · 15.11 11:25', not: null },
        ],
      },
    ],
    temettu: {
      toplam: 270,
      satirlar: [
        {
          tarih: '2026-11-18',
          ad: 'THYAO temettüsü',
          tutar: 270,
          yazan: 'Ben · 18.11 10:20',
          not: 'brüt 300 · stopaj 30',
        },
      ],
    },
    borclar: {
      alacak: 3000,
      borc: 2500,
      kayitlar: [
        {
          yon: 'alacak',
          kisi: 'Mehmet',
          tarih: '2026-11-03',
          tutar: 5000,
          yazan: 'Ben · 03.11 18:22',
          kalan: 3000,
          odemeler: [{ tarih: '2026-11-20', tutar: 2000, yazan: 'Ben · 20.11 12:10' }],
        },
      ],
    },
    yatirim: {
      kalemler: [
        {
          varlik_id: 7,
          kod: 'THYAO',
          ad: 'Türk Hava Yolları',
          tur: 'hisse',
          birim: 'lot',
          ilk: 1500,
          son: 1575,
          fiyat_ilk: 70,
          fiyat_son: 78.4,
          deger_ilk: 105000,
          deger_son: 123480,
          islemler: [{ tarih: '2026-11-12', adet: 75, fiyat: 75, yazan: 'Ben · 12.11 11:02' }],
          net_islem: 5625,
          fiyat_etkisi: 12855,
          temettu: 270,
        },
        {
          varlik_id: 8,
          kod: 'ASELS',
          ad: 'Aselsan',
          tur: 'hisse',
          birim: 'lot',
          ilk: 400,
          son: 400,
          fiyat_ilk: 120,
          fiyat_son: 111.5,
          deger_ilk: 48000,
          deger_son: 44600,
          islemler: [],
          net_islem: 0,
          fiyat_etkisi: -3400,
          temettu: 0,
        },
      ],
    },
  },
  degisiklikler: [
    {
      id: 1,
      tip: 'eklendi',
      tur: 'gider',
      baslik: 'Pazar',
      kayit_tarihi: '2026-11-29',
      eski_tutar: null,
      yeni_tutar: 520,
      yapan_ad: 'Eşim',
      yapildi_at: '2026-12-02T10:15:00+03:00',
    },
    {
      id: 2,
      tip: 'duzeltildi',
      tur: 'gider',
      baslik: 'Elektrik',
      kayit_tarihi: '2026-11-03',
      eski_tutar: 1284.5,
      yeni_tutar: 1248.5,
      yapan_ad: 'Ben',
      yapildi_at: '2026-12-03T21:07:00+03:00',
    },
    {
      id: 3,
      tip: 'silindi',
      tur: 'gider',
      baslik: 'Terzi',
      kayit_tarihi: '2026-11-12',
      eski_tutar: 600,
      yeni_tutar: null,
      yapan_ad: 'Ben',
      yapildi_at: '2026-12-04T08:40:00+03:00',
    },
  ],
  ...alan,
})

beforeEach(() => {
  vi.stubGlobal('matchMedia', () => ({
    matches: false,
    addEventListener: () => {},
    removeEventListener: () => {},
  }))
  defter.mockReset()
})

async function ac(y: DefterYaniti = yanit()) {
  defter.mockResolvedValue(y)
  const { default: DefterView } = await import('@/views/aile/DefterView.vue')
  const w = mount(DefterView, { global: { plugins, stubs } })
  await flushPromises()
  return w
}

describe('Defter', () => {
  it('açılışta Kalemler sekmesi: önceki ay sonu, alım, adet değişimi, fiyat ve değer değişimi ile ayrım', async () => {
    const w = await ac()
    const kart = w.findAll('[data-test="defter-kalem"]')[0]!
    const metin = kart.text().replace(/\s+/g, ' ')
    expect(metin).toContain('THYAO')
    expect(metin).toContain('Önceki ay sonu1.500 lot')
    expect(metin).toContain('alım 75 lot')
    expect(metin).toContain('+75 lot')
    expect(metin).toContain('%5,0')
    expect(metin).toContain('Ay sonu1.575 lot')
    expect(metin).toContain('%12,0')
    expect(metin).toContain('+₺18.480,00')
    expect(metin).toContain('Alımınız +₺5.625,00')
    expect(metin).toContain('Fiyat hareketi +₺12.855,00')
    expect(metin).toContain('Temettü +₺270,00')
    // Adedi değişmeyen kalem
    expect(w.findAll('[data-test="defter-kalem"]')[1]!.text()).toContain('değişmedi')
    w.unmount()
  })

  it('üst şeritte yatırım değeri, fiyat hareketi, net alım, kalan ve sonradan değişiklik sayısı', async () => {
    const w = await ac()
    const serit = w.get('[data-test="defter-serit"]').text()
    expect(serit).toContain('168.080') // 123.480 + 44.600
    expect(serit).toContain('9.455') // 12.855 − 3.400
    expect(serit).toContain('5.625')
    expect(serit).toContain('25.525,60')
    expect(w.get('[data-test="serit-sonradan"]').text()).toContain('3 değişiklik')
    w.unmount()
  })

  it('kapanmış ay mühürlü uyarısı; açık ay canlı uyarısı; düzenle / sil düğmesi yok', async () => {
    const w = await ac()
    expect(w.get('[data-test="defter-durum"]').text()).toContain('mühürlü')
    expect(w.text()).not.toMatch(/Düzenle|Sil\b/)
    w.unmount()
    const a = await ac(yanit({ kapali: false, muhurlendi_at: null }))
    expect(a.get('[data-test="defter-durum"]').text()).toContain('canlı')
    a.unmount()
  })

  it('kategori sekmesi satırları, tarih ve yazanla listeler; Tümü hepsini tarih sırasıyla', async () => {
    const w = await ac()
    await w.get('[data-test="defter-sekme-g:k1"]').trigger('click')
    const sayfa = w.get('[data-test="defter-sayfa"]').text()
    expect(sayfa).toContain('Market')
    expect(sayfa).toContain('Bakkal')
    expect(sayfa).toContain('Eşim · 15.11 11:25')
    expect(sayfa).toContain('−₺185,00')
    await w.get('[data-test="defter-sekme-tumu"]').trigger('click')
    const satirlar = w.get('[data-test="defter-sayfa"]').text()
    expect(satirlar.indexOf('Maaş')).toBeLessThan(satirlar.indexOf('Bakkal'))
    expect(satirlar).toContain('+₺52.400,00')
    w.unmount()
  })

  it('Temettü ve Borç / Alacak sekmeleri', async () => {
    const w = await ac()
    await w.get('[data-test="defter-sekme-temettu"]').trigger('click')
    expect(w.get('[data-test="defter-sayfa"]').text()).toContain('THYAO temettüsü')
    await w.get('[data-test="defter-sekme-borc"]').trigger('click')
    const b = w.get('[data-test="defter-sayfa"]').text()
    expect(b).toContain("Mehmet'e verdim")
    expect(b).toContain('Tahsilat')
    expect(b).toContain('Kalan ₺3.000,00')
    w.unmount()
  })

  it('Sonradan değişiklikler: tür etiketi, eski → yeni tutar, silinen üstü çizili, gider etkisi', async () => {
    const w = await ac()
    await w.get('[data-test="defter-sekme-sonradan"]').trigger('click')
    const satirlar = w.findAll('[data-test="defter-degisiklik"]')
    expect(satirlar).toHaveLength(3)
    expect(satirlar[0]!.text()).toContain('EKLENDİ')
    expect(satirlar[1]!.text()).toContain('DÜZELTİLDİ')
    expect(satirlar[1]!.text()).toContain('₺1.284,50 → ₺1.248,50')
    expect(satirlar[2]!.text()).toContain('SİLİNDİ')
    expect(satirlar[2]!.find('.iptal').exists()).toBe(true)
    // +520 − 36 − 600 = −116
    expect(w.get('[data-test="defter-sayfa"]').text()).toContain('−₺116,00')
    w.unmount()
  })

  it('Değerlendirme: toplam değişim, işlem / fiyat ayrımı, en çok yükselen ve düşen, bütçe', async () => {
    const w = await ac()
    await w.get('[data-test="defter-sekme-deger"]').trigger('click')
    const t = w.get('[data-test="defter-yorum"]').text().replace(/\s+/g, ' ')
    expect(t).toContain('₺153.000,00 iken ₺168.080,00 oldu')
    expect(t).toContain('+₺15.080,00')
    expect(t).toContain('THYAO %12,0')
    expect(t).toContain('ASELS %7,1')
    expect(t).toContain('Ay sonunda ₺25.525,60 kaldı')
    w.unmount()
  })
})
