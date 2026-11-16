import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ConfirmationService from 'primevue/confirmationservice'
import ToastService from 'primevue/toastservice'
import type { Ekstre, FaturaOnerisi, KartIslemi } from '@/api/aile'

const getir = vi.fn<() => Promise<unknown>>()
vi.mock('vue-router', () => ({
  useRouter: () => ({ replace: vi.fn<() => void>(), push: vi.fn<() => void>() }),
}))
vi.mock('@/api/aile', async (asil) => ({
  ...(await asil<typeof import('@/api/aile')>()),
  aileApi: {
    ekstre: () => getir(),
    hane: () => Promise.resolve({ hane: { id: 1, ad: 'Aile' }, uyeler: [], ben_uye_id: 1 }),
    bankalar: () => Promise.resolve([]),
    kartlar: () => Promise.resolve([]),
    kategoriler: () => Promise.resolve([]),
    faturaTurleri: () => Promise.resolve([]),
    saglayicilar: () => Promise.resolve([]),
    gelirTurleri: () => Promise.resolve([]),
    kaynaklar: () => Promise.resolve([]),
    giderKaynaklari: () => Promise.resolve([]),
  },
}))

const oneri = (ek: Partial<FaturaOnerisi>): FaturaOnerisi => ({
  guven: 'orta',
  puan: 60,
  saglayici_id: 1,
  fatura_turu_id: 1,
  adaylar: [],
  gerekce: [],
  hizmet_donemi: '2026-11',
  donem_kaynagi: 'kesim',
  otomatik: false,
  secildi: false,
  ...ek,
})
function satir(id: number, aciklama: string, f: FaturaOnerisi | null): KartIslemi {
  return {
    id,
    kart_id: 1,
    durum: 'taslak',
    islem_tarihi: '2026-10-05',
    aciklama,
    tutar: 100,
    tur: 'harcama',
    kategori_id: null,
    fatura_onerisi: f,
    okuma: null,
  } as unknown as KartIslemi
}
function ekstre(ek: Partial<Ekstre> = {}): Ekstre {
  return {
    id: 5,
    kart_id: 1,
    durum: 'taslak',
    donem: '2026-11',
    donem_etiketi: 'Kasım 2026',
    kesim_tarihi: '2026-11-10',
    son_odeme_tarihi: '2026-11-20',
    donem_borcu: 1000,
    asgari_odeme: 400,
    okunamayan: [],
    alan_kaynaklari: {},
    banka_profili: 'genel',
    ...ek,
  } as unknown as Ekstre
}

const satirlar = [
  satir(1, 'MIGROS KADIKOY', null),
  satir(2, 'XYZ DOGALGAZ', oneri({ guven: 'dusuk', saglayici_id: null, adaylar: [3] })),
  satir(3, 'IGDAS ODEME', oneri({ guven: 'orta' })),
  satir(4, 'ISKI SU', oneri({ guven: 'yuksek', secildi: true, otomatik: true })),
  satir(5, 'ENERJISA', oneri({ guven: 'yuksek' })),
]

async function kur(e: Ekstre) {
  getir.mockResolvedValue({ ekstre: e, kart: { id: 1, ad: 'Bonus', asgari_oran: 40 }, satirlar })
  const { default: EkstreView } = await import('@/views/aile/EkstreView.vue')
  setActivePinia(createPinia())
  const w = mount(EkstreView, {
    props: { id: 5 },
    global: {
      plugins: [PrimeVue, ToastService, ConfirmationService],
      stubs: {
        EkstreSatiri: {
          props: ['satir'],
          template: '<li data-test="satir" :data-id="satir.id">{{ satir.aciklama }}</li>',
        },
        AiDenetimPaneli: true,
        EkstreAiPaneli: true,
        SatirDuzeltDialog: true,
        FormAlani: true,
        UygulamaPenceresi: true,
        KartEtiketi: true,
      },
    },
  })
  await flushPromises()

  return w
}

describe('Ekstre: fatura önerileri en üstte; onayı kaldır', () => {
  beforeEach(() => {
    getir.mockReset()
    vi.stubGlobal('matchMedia', () => ({
      matches: false,
      addEventListener: () => {},
      removeEventListener: () => {},
    }))
  })

  it('fatura önerisi olan satırlar sayfanın en üstünde (işaretli ve güvenilir önce); listede tekrarlanmaz', async () => {
    const w = await kur(ekstre())
    const bolum = w.get('[data-test="fatura-onerileri"]')
    expect(bolum.text()).toContain('Fatura önerileri (4)')
    expect(bolum.findAll('[data-test="satir"]').map((e) => e.attributes('data-id'))).toEqual([
      '4', // işaretli
      '5', // yüksek
      '3', // orta
      '2', // düşük (aday var)
    ])
    // Sayfada ilk bölüm bu: başlık kartından önce
    const html = w.html()
    expect(html.indexOf('data-test="fatura-onerileri"')).toBeLessThan(html.indexOf('Kasım 2026'))
    // Ana listede yalnız fatura önerisi olmayan satır
    const tumu = w.findAll('[data-test="satir"]').map((e) => e.attributes('data-id'))
    expect(tumu.filter((i) => i === '1')).toHaveLength(1)
    expect(tumu.filter((i) => i === '4')).toHaveLength(1)
    w.unmount()
  })

  it('öneri yoksa bölüm görünmez', async () => {
    getir.mockResolvedValue({
      ekstre: ekstre(),
      kart: { id: 1, ad: 'Bonus', asgari_oran: 40 },
      satirlar: [satir(1, 'MIGROS', null)],
    })
    const { default: EkstreView } = await import('@/views/aile/EkstreView.vue')
    setActivePinia(createPinia())
    const w = mount(EkstreView, {
      props: { id: 5 },
      global: {
        plugins: [PrimeVue, ToastService, ConfirmationService],
        stubs: {
          EkstreSatiri: true,
          AiDenetimPaneli: true,
          EkstreAiPaneli: true,
          SatirDuzeltDialog: true,
          FormAlani: true,
          UygulamaPenceresi: true,
          KartEtiketi: true,
        },
      },
    })
    await flushPromises()
    expect(w.find('[data-test="fatura-onerileri"]').exists()).toBe(false)
    w.unmount()
  })

  it('onaylı ekstrede "Onayı kaldır" düğmesi var; taslakta ve dosyasız (elle) ekstrede yok', async () => {
    let w = await kur(ekstre({ durum: 'onaylandi' }))
    expect(w.find('[data-test="onayi-kaldir"]').exists()).toBe(true)
    expect(w.text()).toContain('Onayı kaldır')
    w.unmount()
    w = await kur(ekstre({ durum: 'taslak' }))
    expect(w.find('[data-test="onayi-kaldir"]').exists()).toBe(false)
    w.unmount()
    w = await kur(ekstre({ durum: 'onaylandi', banka_profili: 'elle' }))
    expect(w.find('[data-test="onayi-kaldir"]').exists()).toBe(false)
    w.unmount()
  })
})
