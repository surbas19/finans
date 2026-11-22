import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import PrimeVue from 'primevue/config'
import ConfirmationService from 'primevue/confirmationservice'
import ToastService from 'primevue/toastservice'
import type { TemettuListesi, TemettuPlani, TemettuSecenegi } from '@/api/finans'

const temettuPlanlari = vi.fn<() => Promise<TemettuListesi>>()
const temettuSecenekleri = vi.fn<() => Promise<TemettuSecenegi[]>>()
const temettuPlanla = vi.fn<(v: Record<string, unknown>) => Promise<unknown>>()
const temettuYatti = vi.fn<(id: number, net?: number | null) => Promise<{ message: string }>>()

vi.mock('@/api/finans', async (asil) => ({
  ...(await asil<typeof import('@/api/finans')>()),
  finansApi: {
    temettuPlanlari: () => temettuPlanlari(),
    temettuSecenekleri: () => temettuSecenekleri(),
    temettuPlanla: (v: Record<string, unknown>) => temettuPlanla(v),
    temettuYatti: (id: number, net?: number | null) => temettuYatti(id, net),
  },
}))

const stubs = {
  UygulamaPenceresi: { template: '<div><slot /></div>' },
  RouterLink: { template: '<a><slot /></a>' },
}
const plugins = [PrimeVue, ToastService, ConfirmationService]

const plan = (id: number, alan: Partial<TemettuPlani> = {}): TemettuPlani => ({
  id,
  hesap_id: 1,
  hesap_ad: 'Yatırım',
  varlik: { id: 7, kod: 'THYAO', ad: 'Türk Hava Yolları' },
  tarih: '2026-11-25',
  gun_kaldi: 3,
  durum: 'bekliyor',
  lot: 500,
  hisse_basi: 9.44,
  oran_yuzde: 2.8,
  brut: 4720,
  stopaj_yuzde: 10,
  stopaj: 472,
  net: 4248,
  not: null,
  yatti_at: null,
  ...alan,
})
const liste = (alan: Partial<TemettuListesi> = {}): TemettuListesi => ({
  bekleyenler: [plan(1)],
  yatanlar: [],
  toplam_net: 4248,
  serit: [],
  hatirlatma_gun: 3,
  ...alan,
})

beforeEach(() => {
  vi.stubGlobal('matchMedia', () => ({
    matches: false,
    addEventListener: () => {},
    removeEventListener: () => {},
  }))
  temettuPlanlari.mockReset()
  temettuSecenekleri.mockReset()
  temettuPlanla.mockReset()
  temettuYatti.mockReset()
  localStorage.clear()
})

describe('Temettü sayfası', () => {
  it('kartlarda hisse, kalan gün, yatacak net, lot, hisse başı, oran ve stopaj; toplam ve yatanlar', async () => {
    temettuPlanlari.mockResolvedValue(
      liste({
        yatanlar: [
          plan(2, {
            durum: 'yatti',
            gun_kaldi: -10,
            lot: null,
            net: 1020,
            varlik: { id: 8, kod: 'ASELS', ad: 'Aselsan' },
          }),
        ],
      }),
    )
    const { default: TemettuView } = await import('@/views/TemettuView.vue')
    const w = mount(TemettuView, { global: { plugins, stubs } })
    await flushPromises()
    expect(w.get('[data-test="temettu-toplam"]').text()).toContain('4.248')
    const kart = w.get('[data-test="temettu-bekleyenler"] [data-test="temettu-karti"]')
    expect(kart.get('[data-test="temettu-kod"]').text()).toBe('THYAO')
    expect(kart.get('[data-test="temettu-gun"]').text()).toContain('3 gün')
    expect(kart.get('[data-test="temettu-net"]').text()).toContain('4.248')
    const ayrinti = kart.get('[data-test="temettu-ayrinti"]').text()
    expect(ayrinti).toContain('500 lot')
    expect(ayrinti).toContain('%2,8')
    expect(ayrinti).toContain('472')
    // Yatanlarda eylem düğmesi yok
    const yatan = w.get('[data-test="temettu-yatanlar"] [data-test="temettu-karti"]')
    expect(yatan.find('[data-test="temettu-yatti"]').exists()).toBe(false)
    expect(yatan.get('[data-test="temettu-kod"]').text()).toBe('ASELS')
    w.unmount()
  })

  it('boşken yönlendirici boş durum', async () => {
    temettuPlanlari.mockResolvedValue(liste({ bekleyenler: [], toplam_net: 0 }))
    const { default: TemettuView } = await import('@/views/TemettuView.vue')
    const w = mount(TemettuView, { global: { plugins, stubs } })
    await flushPromises()
    expect(w.text()).toContain('Planlı temettü yok')
    w.unmount()
  })
})

describe('Temettü şeridi (Özet)', () => {
  it('kalan güne göre başlık: 3 gün sonra, bugün, günü geçmiş "yattı mı?"; Sonra aynı günü gizler', async () => {
    temettuPlanlari.mockResolvedValue(
      liste({
        serit: [plan(1, { gun_kaldi: 3 }), plan(2, { gun_kaldi: 0 }), plan(3, { gun_kaldi: -2 })],
      }),
    )
    const { default: TemettuSeridi } = await import('@/components/TemettuSeridi.vue')
    const w = mount(TemettuSeridi, { global: { plugins, stubs } })
    await flushPromises()
    const basliklar = w.findAll('[data-test="temettu-serit-baslik"]').map((b) => b.text())
    expect(basliklar).toEqual([
      '3 gün sonra THYAO temettüsü yatıyor',
      'Bugün THYAO temettüsü yatıyor',
      'THYAO temettüsü yattı mı?',
    ])
    await w.findAll('[data-test="serit-sonra"]')[0]!.trigger('click')
    expect(w.findAll('[data-test="temettu-serit"]')).toHaveLength(2)
    expect(localStorage.getItem('finans-temettu-sonra')).toContain('"1"')
    w.unmount()
  })

  it('şerit yoksa hiçbir şey çizilmez', async () => {
    temettuPlanlari.mockResolvedValue(liste({ serit: [] }))
    const { default: TemettuSeridi } = await import('@/components/TemettuSeridi.vue')
    const w = mount(TemettuSeridi, { global: { plugins, stubs } })
    await flushPromises()
    expect(w.find('[data-test="temettu-seridi"]').exists()).toBe(false)
    w.unmount()
  })
})

describe('Temettü planla formu', () => {
  it('hisse + hisse başı tutar girilince lot, oran, brüt, stopaj ve yatacak net canlı hesaplanır; plan gönderilir', async () => {
    temettuSecenekleri.mockResolvedValue([
      {
        hesap_id: 1,
        hesap_ad: 'Yatırım',
        varlik_id: 7,
        kod: 'THYAO',
        ad: 'THY',
        lot: 500,
        fiyat_tl: 337,
      },
    ])
    temettuPlanla.mockResolvedValue({ plan: plan(9) })
    const { default: TemettuPlanFormu } = await import('@/components/TemettuPlanFormu.vue')
    const w = mount(TemettuPlanFormu, { props: { visible: false }, global: { plugins, stubs } })
    await w.setProps({ visible: true })
    await flushPromises()
    // Tek seçenek otomatik seçilir
    await w.get('#tp-hisse-basi').setValue('9,44')
    await w.get('#tp-hisse-basi').trigger('blur')
    const hesap = w.get('[data-test="temettu-hesap"]').text()
    expect(hesap).toContain('500')
    expect(hesap).toContain('%2,8')
    expect(hesap).toContain('4.720')
    expect(w.get('[data-test="temettu-net-onizleme"]').text()).toContain('4.248')
    await w.get('[data-test="temettu-form"]').trigger('submit')
    await flushPromises()
    expect(temettuPlanla).toHaveBeenCalledTimes(1)
    expect(temettuPlanla.mock.calls[0]![0]).toMatchObject({
      hesap_id: 1,
      varlik_id: 7,
      hisse_basi: 9.44,
      stopaj_yuzde: 10,
    })
    expect(w.emitted('kaydedildi')).toHaveLength(1)
    w.unmount()
  })
})

describe('Temettü yattı penceresi', () => {
  it('net değişmediyse sunucu hesabı yazılır (ezme yok); değiştirilirse banka tutarı gönderilir', async () => {
    temettuYatti.mockResolvedValue({ message: 'Temettü kaydedildi.' })
    const { default: TemettuYattiDialog } = await import('@/components/TemettuYattiDialog.vue')
    const w = mount(TemettuYattiDialog, {
      props: { visible: false, plan: plan(5) },
      global: { plugins, stubs },
    })
    await w.setProps({ visible: true })
    await w.get('[data-test="temettu-yatti-form"]').trigger('submit')
    await flushPromises()
    expect(temettuYatti).toHaveBeenLastCalledWith(5, null)

    await w.setProps({ visible: false })
    await w.setProps({ visible: true })
    await w.get('#ty-net').setValue('4.000')
    await w.get('#ty-net').trigger('blur')
    await w.get('[data-test="temettu-yatti-form"]').trigger('submit')
    await flushPromises()
    expect(temettuYatti).toHaveBeenLastCalledWith(5, 4000)
    w.unmount()
  })
})
