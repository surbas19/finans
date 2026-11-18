import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import PrimeVue from 'primevue/config'
import ConfirmationService from 'primevue/confirmationservice'
import ToastService from 'primevue/toastservice'
import type { Borc, EvNakdiOzeti } from '@/api/aile'

const evNakdi = vi.fn<() => Promise<EvNakdiOzeti>>()
const borclar = vi.fn<() => Promise<unknown>>()
vi.mock('@/api/aile', async (asil) => ({
  ...(await asil<typeof import('@/api/aile')>()),
  aileApi: { evNakdi: () => evNakdi(), borclar: () => borclar() },
}))

const stubs = { UygulamaPenceresi: { template: '<div><slot /></div>' } }
const plugins = [PrimeVue, ToastService, ConfirmationService]

const borc = (alan: Partial<Borc>): Borc => ({
  id: 1,
  yon: 'borc',
  kisi: 'Ahmet',
  tutar: 1000,
  odenen: 400,
  kalan: 600,
  durum: 'acik',
  tarih: '2026-11-01',
  vade: null,
  vade_gecti: false,
  not: null,
  odemeler: [],
  ...alan,
})

beforeEach(() => {
  vi.stubGlobal('matchMedia', () => ({
    matches: false,
    addEventListener: () => {},
    removeEventListener: () => {},
  }))
})

describe('Ev nakdi kartı', () => {
  beforeEach(() => evNakdi.mockReset())

  it('bakiyeyi ve hareketleri gösterir; eksiye düşünce uyarır', async () => {
    evNakdi.mockResolvedValue({
      bakiye: -25,
      giren: 100,
      cikan: 0,
      harcama: 125,
      hareketler: [
        { tip: 'harcama', id: 3, tutar: 125, tarih: '2026-11-12', aciklama: 'Simit' },
        { tip: 'ekleme', id: 1, tutar: 100, tarih: '2026-11-10', aciklama: null },
      ],
    })
    const { default: EvNakdiKarti } = await import('@/components/aile/EvNakdiKarti.vue')
    const w = mount(EvNakdiKarti, {
      global: { plugins, stubs: { ...stubs, RouterLink: { template: '<a><slot /></a>' } } },
    })
    await flushPromises()
    expect(w.get('[data-test="ev-bakiye"]').text()).toContain('25')
    expect(w.get('[data-test="ev-bakiye"]').classes()).toContain('text-red-600')
    expect(w.find('[data-test="ev-eksi"]').exists()).toBe(true)
    // Harcama satırı silinemez, para ekleme silinebilir
    expect(w.findAll('[data-test="ev-hareket-sil"]')).toHaveLength(1)
    // Tüm nakit harcamalar listesine bağlantı (Gelir / Gider → Gider paneli)
    expect(w.find('[data-test="ev-tum-nakit"]').exists()).toBe(true)
    w.unmount()
  })
})

describe('Borç / alacak sayfası', () => {
  beforeEach(() => borclar.mockReset())

  it('özet toplamları ve kayıtlar; kapanan kayıt soluk, vadesi geçen etiketli', async () => {
    borclar.mockResolvedValue({
      borclar: [
        borc({ id: 1, vade: '2026-10-01', vade_gecti: true }),
        borc({
          id: 2,
          yon: 'alacak',
          kisi: 'Ayşe',
          tutar: 250,
          odenen: 250,
          kalan: 0,
          durum: 'kapali',
        }),
      ],
      toplam_borc: 600,
      toplam_alacak: 0,
    })
    const { default: BorclarView } = await import('@/views/aile/BorclarView.vue')
    const w = mount(BorclarView, { global: { plugins, stubs } })
    await flushPromises()
    expect(w.get('[data-test="toplam-borc"]').text()).toContain('600')
    const satirlar = w.findAll('[data-test="borc-satir"]')
    expect(satirlar).toHaveLength(2)
    expect(satirlar[0]!.text()).toContain('Vadesi geçti')
    expect(satirlar[1]!.text()).toContain('Kapandı')
    expect(satirlar[1]!.classes()).toContain('opacity-60')
    w.unmount()
  })
})
