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
  UygulamaPenceresi: { props: ['visible'], template: '<div v-if="visible"><slot /></div>' },
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

describe('Defter (telefonda sade)', () => {
  it('yalnız iki ana bölüm (Kalemler, Kayıtlar); harcama türü sekmeleri yok', async () => {
    const w = await ac()
    const sekmeler = w.findAll('[role="tab"][data-test^="defter-sekme-"]')
    expect(sekmeler.map((s) => s.text())).toEqual(['Kalemler', 'Kayıtlar'])
    expect(w.text()).not.toContain('Market')
    w.unmount()
  })

  it('açılışta Kalemler: adet değişimi, alım, fiyat, değer ve işlem / fiyat ayrımı; ay özeti', async () => {
    const w = await ac()
    const metin = w.findAll('[data-test="defter-kalem"]')[0]!.text().replace(/\s+/g, ' ')
    expect(metin).toContain('THYAO')
    expect(metin).toContain('1.500 → 1.575 lot')
    expect(metin).toContain('+75')
    expect(metin).toContain('alım 75')
    expect(metin).toContain('× ₺75,00')
    expect(metin).toContain('₺70,00 → ₺78,40')
    expect(metin).toContain('%17,6')
    expect(metin).toContain('₺105.000,00 → ₺123.480,00')
    expect(metin).toContain('Alımınız +₺5.625,00')
    expect(metin).toContain('Fiyat hareketi +₺12.855,00')
    expect(metin).toContain('Temettü +₺270,00')
    expect(w.findAll('[data-test="defter-kalem"]')[1]!.text()).toContain('değişmedi')
    const ozet = w.get('[data-test="defter-ay-ozeti"]').text().replace(/\s+/g, ' ')
    expect(ozet).toContain('₺153.000,00 iken ₺168.080,00 oldu')
    expect(ozet).toContain('+₺15.080,00')
    expect(ozet).toContain('THYAO %12,0')
    expect(ozet).toContain('ASELS %7,1')
    w.unmount()
  })

  it('üstte gelir / gider / kalan; alt düğmeler temettü, borç net ve sonradan sayısı', async () => {
    const w = await ac()
    const serit = w.get('[data-test="defter-serit"]').text()
    expect(serit).toContain('59.250')
    expect(serit).toContain('23.924')
    expect(serit).toContain('25.526')
    const dugmeler = w.get('[data-test="defter-alt-dugmeler"]').text().replace(/\s+/g, ' ')
    expect(dugmeler).toContain('Temettü+₺270,00')
    expect(dugmeler).toContain('net +₺500,00')
    expect(w.get('[data-test="serit-sonradan"]').text()).toContain('3 değişiklik')
    w.unmount()
  })

  it('mühür / canlı uyarısı; düzenle ya da sil düğmesi yok', async () => {
    const w = await ac()
    expect(w.get('[data-test="defter-durum"]').text()).toContain('mühürlü')
    expect(w.text()).not.toMatch(/Düzenle|Sil\b/)
    w.unmount()
    const a = await ac(yanit({ kapali: false, muhurlendi_at: null }))
    expect(a.get('[data-test="defter-durum"]').text()).toContain('canlı')
    a.unmount()
  })

  it('Kayıtlar: tek tarih sıralı liste, tür çipiyle süzülür', async () => {
    const w = await ac()
    await w.get('[data-test="defter-sekme-kayit"]').trigger('click')
    let satirlar = w.findAll('[data-test="defter-kayit"]').map((s) => s.text())
    expect(satirlar).toHaveLength(4)
    expect(satirlar[0]).toContain('Maaş')
    expect(satirlar[0]).toContain('+₺52.400,00')
    expect(satirlar[3]).toContain('Pazar')
    await w.get('[data-test="defter-filtre-gider"]').trigger('click')
    satirlar = w.findAll('[data-test="defter-kayit"]').map((s) => s.text())
    expect(satirlar).toHaveLength(2)
    expect(satirlar[0]).toContain('−₺185,00')
    await w.get('[data-test="defter-filtre-gelir"]').trigger('click')
    expect(w.findAll('[data-test="defter-kayit"]')).toHaveLength(2)
    w.unmount()
  })

  it('Temettü ve Borç / Alacak alt sayfada açılır', async () => {
    const w = await ac()
    expect(w.find('[data-test="defter-alt-sayfa"]').exists()).toBe(false)
    await w.get('[data-test="defter-sayfa-temettu"]').trigger('click')
    expect(w.get('[data-test="defter-alt-sayfa"]').text()).toContain('THYAO temettüsü')
    await w.get('[data-test="defter-sayfa-borc"]').trigger('click')
    const b = w.get('[data-test="defter-alt-sayfa"]').text()
    expect(b).toContain("Mehmet'e verdim")
    expect(b).toContain('Tahsilat')
    expect(b).toContain('Kalan ₺3.000,00')
    w.unmount()
  })

  it('Sonradan değişiklikler alt sayfada: tür etiketi, eski → yeni, silinen üstü çizili, gider etkisi', async () => {
    const w = await ac()
    await w.get('[data-test="defter-sayfa-sonradan"]').trigger('click')
    const satirlar = w.findAll('[data-test="defter-degisiklik"]')
    expect(satirlar).toHaveLength(3)
    expect(satirlar[0]!.text()).toContain('EKLENDİ')
    expect(satirlar[1]!.text()).toContain('DÜZELTİLDİ')
    expect(satirlar[1]!.text()).toContain('₺1.284,50 → ₺1.248,50')
    expect(satirlar[2]!.text()).toContain('SİLİNDİ')
    expect(satirlar[2]!.find('.iptal').exists()).toBe(true)
    expect(w.get('[data-test="defter-alt-sayfa"]').text()).toContain('−₺116,00')
    w.unmount()
  })
})
