import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ConfirmationService from 'primevue/confirmationservice'
import ToastService from 'primevue/toastservice'
import type { Fatura, FaturaAyOzeti, SaglayiciGecmisi } from '@/api/aile'
import { oneriDonem } from '@/utils/fatura'
import FaturalarView from '@/views/aile/FaturalarView.vue'

function fatura(id: number, ek: Partial<Fatura> = {}): Fatura {
  return {
    id,
    saglayici_id: id,
    fatura_turu_id: 1,
    hizmet_donemi: '2026-11',
    hizmet_donemi_etiketi: 'Kasım 2026',
    donem_kaynagi: 'kullanici',
    tutar: 100,
    son_odeme_tarihi: null,
    durum: 'odendi',
    gecikmis: false,
    odeme_tarihi: '2026-11-14',
    kart_islemi_id: null,
    eslesme: null,
    kaynak: 'elle',
    not: null,
    saglayici_ad: `S${id}`,
    kart_islemi: null,
    ...ek,
  }
}

const FATURALAR: Fatura[] = [
  fatura(1, { saglayici_ad: 'Enerjisa', tutar: 1284.6 }),
  fatura(2, {
    saglayici_ad: 'İSKİ',
    fatura_turu_id: 2,
    tutar: 412.3,
    durum: 'bekliyor',
    gecikmis: true,
    son_odeme_tarihi: '2026-11-12',
    odeme_tarihi: null,
  }),
  fatura(3, {
    saglayici_ad: 'Turkcell',
    tutar: 399,
    durum: 'bekliyor',
    son_odeme_tarihi: '2026-11-28',
    odeme_tarihi: null,
  }),
  fatura(4, {
    saglayici_ad: 'İGDAŞ',
    tutar: 1009.3,
    durum: 'bekliyor',
    son_odeme_tarihi: '2026-11-24',
    odeme_tarihi: null,
  }),
]

const OZET: FaturaAyOzeti = {
  donem: '2026-11',
  toplam: 3105.2,
  adet: 4,
  beklenen: 5,
  odendi: { tutar: 1284.6, adet: 1 },
  bekliyor: { tutar: 1408.3, adet: 2 },
  gecikti: { tutar: 412.3, adet: 1 },
  onceki_donem: '2026-10',
  onceki_toplam: 2875,
  gelmeyenler: [
    {
      saglayici_id: 9,
      saglayici_ad: 'Türk Telekom',
      fatura_turu_id: 3,
      son_donem: '2026-10',
      son_donem_etiketi: 'Ekim 2026',
      son_tutar: 549.9,
    },
  ],
  bilinmeyen_adet: 2,
}

const GECMIS: SaglayiciGecmisi = {
  saglayici: { id: 1, ad: 'Enerjisa', fatura_turu_id: 1 },
  adet: 2,
  ortalama: 1215.75,
  en_yuksek: { tutar: 1284.6, donem: '2026-11', etiket: 'Kasım 2026' },
  son_12_ay: 2431.5,
  seri: Array.from({ length: 12 }, (_, i) => ({
    donem: `2026-${String(i + 1).padStart(2, '0')}`,
    tutar: i === 10 ? 1146.9 : null,
  })),
  faturalar: [fatura(1, { saglayici_ad: 'Enerjisa', tutar: 1284.6 })],
}

const guncelle = vi.fn<(id: number, veri: Record<string, unknown>) => Promise<unknown>>()
const gecmis = vi.fn<(id: number) => Promise<SaglayiciGecmisi>>()
const faturalarApi = vi.fn<(s: Record<string, unknown>) => Promise<unknown>>()
vi.mock('@/api/aile', async (asil) => ({
  ...(await asil<typeof import('@/api/aile')>()),
  aileApi: {
    hane: () => Promise.resolve({ hane: { id: 1, ad: 'Aile' }, uyeler: [], ben_uye_id: 1 }),
    bankalar: () => Promise.resolve([]),
    kartlar: () => Promise.resolve([]),
    kategoriler: () => Promise.resolve([]),
    gelirTurleri: () => Promise.resolve([]),
    faturaTurleri: () =>
      Promise.resolve([
        { id: 1, ad: 'Elektrik', ikon: 'pi pi-bolt', sistem: true, anahtar_kelimeler: [] },
        { id: 2, ad: 'Su', ikon: 'pi pi-filter', sistem: true, anahtar_kelimeler: [] },
        { id: 3, ad: 'İnternet', ikon: 'pi pi-wifi', sistem: false, anahtar_kelimeler: [] },
      ]),
    saglayicilar: () =>
      Promise.resolve([
        {
          id: 1,
          ad: 'Enerjisa',
          fatura_turu_id: 1,
          otomatik_kayit: false,
          aktif: true,
          desenler: [],
        },
      ]),
    faturaOzeti: () => Promise.resolve(OZET),
    faturalar: (s: Record<string, unknown>) => faturalarApi(s),
    faturaGuncelle: (id: number, veri: Record<string, unknown>) => guncelle(id, veri),
    saglayiciGecmisi: (id: number) => gecmis(id),
  },
}))

const PencereTaklidi = {
  props: ['visible', 'baslik'],
  template: '<div v-if="visible" class="pencere-taklidi"><slot name="header" /><slot /></div>',
}

describe('Faturalar ödeme panosu', () => {
  const genel = {
    plugins: [PrimeVue, ToastService, ConfirmationService],
    stubs: {
      FaturaGrafigi: true,
      FaturaFormu: true,
      FaturaBaglaDialog: true,
      UygulamaPenceresi: PencereTaklidi,
      Dialog: PencereTaklidi,
    },
  }

  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-11-19T10:00:00'))
    setActivePinia(createPinia())
    guncelle.mockReset().mockResolvedValue({ fatura: {} })
    gecmis.mockReset().mockResolvedValue(GECMIS)
    faturalarApi.mockReset().mockImplementation((s) =>
      Promise.resolve({
        faturalar: s.bilinmeyen
          ? [
              fatura(7, {
                hizmet_donemi: null,
                hizmet_donemi_etiketi: null,
                odeme_tarihi: '2026-08-27',
              }),
            ]
          : FATURALAR,
        toplam: 0,
      }),
    )
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  async function kur() {
    const w = mount(FaturalarView, { global: genel })
    await flushPromises()

    return w
  }

  it('özet, durum grupları, gelmeyen satırı ve önceki aya fark', async () => {
    const w = await kur()
    const ozet = w.get('[data-test="fatura-ozeti"]').text()
    expect(ozet).toContain('4 / 5 geldi')
    expect(w.get('[data-test="fatura-fark"]').text()).toContain('▲ %8')

    const gecikmis = w.get('[data-test="grup-gecikmis"]').text()
    expect(gecikmis).toContain('İSKİ')
    expect(gecikmis).toContain('7 gün geçti')

    // Bekleyen: son ödemesi yakın olan önce; gelmeyen en sonda
    const bekleyen = w.get('[data-test="grup-bekleyen"]')
    const adlar = bekleyen.findAll('[data-test="fatura"]').map((b) => b.text())
    expect(adlar[0]).toContain('İGDAŞ')
    expect(adlar[0]).toContain('5 gün kaldı')
    expect(adlar[1]).toContain('Turkcell')
    expect(bekleyen.get('[data-test="gelmeyen"]').text()).toContain('henüz gelmedi · Ekim')

    expect(w.get('[data-test="grup-odenen"]').text()).toContain('Enerjisa')
    expect(w.get('[data-test="bilinmeyen-bant"]').text()).toContain('2 faturanın dönemi bilinmiyor')
    w.unmount()
  })

  it('tür çipi listeyi süzer', async () => {
    const w = await kur()
    const cipler = w.findAll('[data-test="tur-cipi"]')
    expect(cipler.map((c) => c.text())).toEqual(['Tümü', 'Elektrik', 'Su', 'İnternet'])
    await cipler[2]!.trigger('click')
    expect(
      w
        .findAll('[data-test="fatura"]')
        .map((b) => b.text())
        .join(),
    ).not.toContain('Enerjisa')
    expect(w.find('[data-test="grup-gecikmis"]').exists()).toBe(true)
    expect(w.find('[data-test="grup-odenen"]').exists()).toBe(false)
    w.unmount()
  })

  it('Ödendi düğmesi bugünün tarihiyle işaretler, öteki alanları korur', async () => {
    const w = await kur()
    await w.get('[data-test="grup-gecikmis"] [data-test="odendi-isaretle"]').trigger('click')
    await flushPromises()
    expect(guncelle).toHaveBeenCalledWith(2, {
      saglayici_id: 2,
      hizmet_donemi: '2026-11',
      tutar: 412.3,
      son_odeme_tarihi: '2026-11-12',
      durum: 'odendi',
      odeme_tarihi: '2026-11-19',
      not: null,
    })
    w.unmount()
  })

  it('satır detayı açar, Geçmiş sağlayıcı geçmişini gösterir', async () => {
    const w = await kur()
    await w.get('[data-test="grup-odenen"] [data-test="fatura"]').trigger('click')
    expect(w.get('[data-test="fatura-detayi"]').text()).toContain('Kart satırına bağla')
    await w.get('[data-test="detay-gecmis"]').trigger('click')
    await flushPromises()
    expect(gecmis).toHaveBeenCalledWith(1)
    const ozet = w.get('[data-test="saglayici-ozet"]').text()
    expect(ozet).toContain('Ortalama')
    expect(ozet).toContain('Kasım 2026')
    expect(w.findAll('[data-test="saglayici-fatura"]')).toHaveLength(1)
    w.unmount()
  })

  it('dönemi bilinmeyen faturaya önerilen ay uygulanır', async () => {
    const w = await kur()
    await w.get('[data-test="bilinmeyen-bant"]').trigger('click')
    await flushPromises()
    const dugme = w.get('[data-test="oneri-uygula"]')
    expect(dugme.text()).toContain('Ağustos 2026')
    await dugme.trigger('click')
    await flushPromises()
    expect(guncelle).toHaveBeenCalledWith(7, expect.objectContaining({ hizmet_donemi: '2026-08' }))
    w.unmount()
  })

  it('öneri: ödeme, son ödeme, kart satırı sırasıyla; tarih yoksa yok', () => {
    expect(oneriDonem(fatura(1, { odeme_tarihi: null, son_odeme_tarihi: '2026-07-20' }))).toBe(
      '2026-07',
    )
    expect(oneriDonem(fatura(1, { odeme_tarihi: null }))).toBeNull()
  })
})
