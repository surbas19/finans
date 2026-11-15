import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ConfirmationService from 'primevue/confirmationservice'
import ToastService from 'primevue/toastservice'
import type { AylikOzet } from '@/api/aile'

const ozet = vi.fn<(ay: string) => Promise<unknown>>()
vi.mock('@/api/aile', async (asil) => ({
  ...(await asil<typeof import('@/api/aile')>()),
  aileApi: {
    ozet: (ay: string) => ozet(ay),
    kaynaklar: () => Promise.resolve([]),
    giderKaynaklari: () => Promise.resolve([]),
    hane: () => Promise.resolve({ hane: { id: 1, ad: 'Aile' }, uyeler: [], ben_uye_id: 1 }),
    bankalar: () => Promise.resolve([]),
    kartlar: () => Promise.resolve([]),
    kategoriler: () => Promise.resolve([]),
    faturaTurleri: () => Promise.resolve([]),
    saglayicilar: () => Promise.resolve([]),
    gelirTurleri: () => Promise.resolve([]),
  },
}))

function ornek(): AylikOzet {
  return {
    donem: '2026-11',
    etiket: 'Kasım 2026',
    gelir: {
      donem: '2026-11',
      toplam: 50000,
      duzenli: 50000,
      duzensiz: 0,
      tek_seferlik: 0,
      kisiler: [],
      beklenenler: [],
    },
    gider: {
      toplam: 16000,
      kart_harcamasi: 1000,
      kartsiz_faturalar: 0,
      duzenli: { toplam: 15000, kalemler: [] },
      beklenenler: [
        {
          kaynak: {
            id: 4,
            uye_id: null,
            kategori_id: null,
            ad: 'Bakıcı',
            siklik: 'aylik',
            beklenen_tutar: 9000,
            gun: 5,
            baslangic: null,
            bitis: null,
            aktif: true,
            not: null,
          },
          donem: '2026-11',
          tarih: '2026-11-05',
        },
      ],
      kategoriler: [],
      kartlar: [],
      haric: [],
      taksitler: [],
      sonraki_taksitler: [],
    },
    faturalar: {
      toplam: 0,
      odenen: 0,
      bekleyen: 0,
      kartla_odenen: 0,
      turler: [],
      donemi_bilinmeyen: 0,
    },
    tasarruf: {
      toplam: 7511,
      kalemler: [
        {
          id: 1,
          uye_id: null,
          hesap_id: 2,
          hesap_ad: 'Evde',
          islem_id: 3,
          varlik_id: 5,
          varlik_ad: 'Gram altın',
          varlik_tur: 'altin',
          miktar: 2.5,
          tarih: '2026-11-10',
          donem: '2026-11',
          tutar: 7511,
          not: null,
        },
      ],
    },
    fark: 34000,
    kalan: 26489,
    uyarilar: { gecikmis_faturalar: [], bekleyen_faturalar: [], son_odemeler: [] },
  }
}

describe('Bütçe aylık özeti', () => {
  beforeEach(() => {
    ozet.mockReset()
    vi.stubGlobal('matchMedia', () => ({
      matches: false,
      addEventListener: () => {},
      removeEventListener: () => {},
    }))
  })

  it('gelir, gider, tasarruf ve kalanı ayrı gösterir; beklenen gider ile tasarruf listelenir', async () => {
    ozet.mockResolvedValue(ornek())
    const { default: AileOzetView } = await import('@/views/aile/AileOzetView.vue')
    setActivePinia(createPinia())
    const sayfa = mount(AileOzetView, {
      global: {
        plugins: [PrimeVue, ConfirmationService, ToastService],
        stubs: {
          RouterLink: { template: '<a><slot /></a>' },
          VeriGrafigi: true,
          GelirFormu: true,
          GiderFormu: true,
          TasarrufFormu: true,
        },
      },
    })
    await flushPromises()

    const metin = (alan: string) => sayfa.get(`[data-test="${alan}"]`).text().replace(/\s+/g, ' ')
    expect(metin('ozet-gelir')).toContain('50.000')
    expect(metin('ozet-gider')).toContain('16.000')
    expect(metin('ozet-tasarruf')).toContain('7.511')
    expect(metin('ozet-kalan')).toContain('26.489')
    expect(sayfa.text()).toContain('Bakıcı')
    expect(sayfa.get('[data-test="tasarruf-bolumu"]').text()).toContain('Gram altın')
    expect(sayfa.get('[data-test="tasarruf-yap"]').text()).toContain('Tasarruf yap')
    sayfa.unmount()
  })

  it('taksitler ve sonraki taksitler akordiyon; kart başına kalan taksit toplamı', async () => {
    const veri = ornek()
    veri.gider.taksitler = [
      {
        islem_id: 1,
        aciklama: 'TEKNOSA',
        kart_id: 7,
        taksit_no: 1,
        taksit_sayisi: 6,
        aya_dusen: 250.25,
        toplam_borc: 1501.5,
        toplam_tahmini: true,
      },
    ]
    veri.gider.sonraki_taksitler = [
      {
        kart_id: 7,
        ad: 'Bonus',
        toplam: 1251.25,
        tahmini: true,
        kalem_sayisi: 1,
        kalemler: [
          {
            islem_id: 1,
            aciklama: 'TEKNOSA',
            kalan_adet: 5,
            aya_dusen: 250.25,
            kalan_toplam: 1251.25,
            tahmini: true,
          },
        ],
      },
    ]
    ozet.mockResolvedValue(veri)
    const { default: AileOzetView } = await import('@/views/aile/AileOzetView.vue')
    setActivePinia(createPinia())
    const sayfa = mount(AileOzetView, {
      global: {
        plugins: [PrimeVue, ConfirmationService, ToastService],
        stubs: {
          RouterLink: { template: '<a><slot /></a>' },
          VeriGrafigi: true,
          GelirFormu: true,
          GiderFormu: true,
          TasarrufFormu: true,
        },
      },
    })
    await flushPromises()
    const akordiyon = sayfa.get('[data-test="taksit-akordiyon"]')
    expect(akordiyon.text()).toContain('Taksitler (1)')
    expect(akordiyon.text()).toContain('Sonraki taksitler')
    // Sonraki taksitler başlıkta kapalı; açınca kart başına toplam görünür
    await sayfa.get('[data-test="sonraki-taksitler-baslik"]').trigger('click')
    await flushPromises()
    const kart = sayfa.get('[data-test="sonraki-taksit-karti"]').text().replace(/\s+/g, ' ')
    expect(kart).toContain('1.251,25')
    expect(kart).toContain('5 taksit')
    sayfa.unmount()
  })
})
