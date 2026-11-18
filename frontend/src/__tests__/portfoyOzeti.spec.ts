import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ConfirmationService from 'primevue/confirmationservice'
import ToastService from 'primevue/toastservice'
import type { HesapOzeti, Ozet, Toplam } from '@/api/finans'

const route = { query: {} as Record<string, unknown> }
const degistir = vi.fn<(a: { query: Record<string, unknown> }) => void>()
vi.mock('vue-router', () => ({
  useRoute: () => route,
  useRouter: () => ({ replace: degistir, push: vi.fn<() => void>() }),
}))

const toplam = (deger: number, ek: Partial<Toplam> = {}): Toplam => ({
  deger,
  maliyet: deger - 1000,
  kz: 1000,
  kz_yuzde: 10,
  gunluk_degisim: 50,
  gerceklesen_kz: 0,
  fiyatsiz: 0,
  maliyeti_eksik: false,
  ...ek,
})
const hesap = (id: number, ad: string, deger: number): HesapOzeti => ({
  ...toplam(deger),
  id,
  ad,
  tur: 'araci_kurum',
  renk: id === 1 ? 'teal' : 'sky',
  sira: id,
  not: null,
  varlik_sayisi: 1,
})

const ozetVerisi = (): Ozet => ({
  usd_kuru: null,
  toplam: toplam(30000, { temettu: 680, maliyeti_eksik: true }),
  hesaplar: [hesap(1, 'Yatırım hesabım', 20000), hesap(2, 'Kasa', 10000)],
  dagilim: [],
  varliklar: [],
  pozisyonlar: [],
  son_guncelleme: null,
})

vi.mock('@/components/UygulamaKurKarti.vue', () => ({ default: { template: '<div />' } }))

vi.mock('@/api/finans', async (asil) => ({
  ...(await asil<typeof import('@/api/finans')>()),
  finansApi: { ozet: () => Promise.resolve(ozetVerisi()) },
}))

async function kur() {
  const { default: OzetView } = await import('@/views/OzetView.vue')
  setActivePinia(createPinia())
  const w = mount(OzetView, {
    global: {
      plugins: [PrimeVue, ConfirmationService, ToastService],
      stubs: {
        RouterLink: { template: '<a><slot /></a>' },
        VeriGrafigi: true,
        PerformansGrafigi: true,
        IslemFormu: true,
        PozisyonAktarDialog: true,
        UygulamaKurKarti: true,
        DagilimKarti: true,
      },
    },
  })
  await flushPromises()

  return w
}

describe('Portföy Özet: üst kart ve hesap çipleri', () => {
  beforeEach(() => {
    route.query = {}
    degistir.mockReset()
  })

  it('bütün hesaplarda toplam değer, ayrıntılar tek satırda, maliyet notu; kaydırıcı yok', async () => {
    const w = await kur()
    expect(w.get('[data-test="secili-baslik"]').text()).toBe('Toplam varlık')
    expect(w.get('[data-test="secili-deger"]').text()).toContain('30.000')
    const ayrinti = w.get('[data-test="secili-ayrinti"]').text()
    expect(ayrinti).toContain('Maliyet')
    expect(ayrinti).toContain('29.000')
    expect(w.get('[data-test="ozet-temettu"]').text()).toContain('680')
    expect(w.find('[data-test="maliyet-eksik"]').exists()).toBe(true)
    expect(w.find('[data-test="kaydirici-slayt"]').exists()).toBe(false)
    w.unmount()
  })

  it('çipler: Tümü + her hesap; hesaba dokununca üst kart o hesabı gösterir ve adres güncellenir', async () => {
    const w = await kur()
    const cipler = w.findAll('[data-test="hesap-cipi"]')
    expect(cipler.map((c) => c.text().replace(/\s+/g, ' '))).toEqual([
      expect.stringContaining('Tümü'),
      expect.stringContaining('Yatırım hesabım'),
      expect.stringContaining('Kasa'),
    ])
    expect(cipler[0]!.attributes('aria-pressed')).toBe('true')
    await cipler[2]!.trigger('click')
    await flushPromises()
    expect(w.get('[data-test="secili-baslik"]').text()).toBe('Kasa')
    expect(w.get('[data-test="secili-deger"]').text()).toContain('10.000')
    expect(w.find('[data-test="hesabi-ac"]').exists()).toBe(true)
    expect(degistir.mock.calls[degistir.mock.calls.length - 1]![0].query).toMatchObject({
      hesap: 2,
    })
    expect(w.findAll('[data-test="hesap-cipi"]')[2]!.attributes('aria-pressed')).toBe('true')
    w.unmount()
  })
})
