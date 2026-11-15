import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import type { Ekstre } from '@/api/aile'

function ekstre(id: number, donem: string, borc: number, ek: Partial<Ekstre> = {}): Ekstre {
  return {
    id,
    kart_id: 1,
    durum: 'onaylandi',
    donem,
    donem_etiketi: donem,
    kesim_tarihi: `${donem}-10`,
    son_odeme_tarihi: null,
    donem_borcu: borc,
    odendi: true,
    ...ek,
  } as unknown as Ekstre
}

const liste = vi.fn<() => Promise<Ekstre[]>>()
vi.mock('@/api/aile', async (asil) => ({
  ...(await asil<typeof import('@/api/aile')>()),
  aileApi: {
    ekstreler: () => liste(),
    hane: () => Promise.resolve({ hane: { id: 1, ad: 'Aile' }, uyeler: [], ben_uye_id: 1 }),
    bankalar: () => Promise.resolve([{ id: 1, ad: 'Garanti' }]),
    kartlar: () =>
      Promise.resolve([
        { id: 1, banka_id: 1, sahip_uye_id: 1, ad: 'Bonus', renk: null, aktif: true },
        { id: 2, banka_id: 1, sahip_uye_id: 1, ad: 'Maximum', renk: null, aktif: true },
      ]),
    kategoriler: () => Promise.resolve([]),
    faturaTurleri: () => Promise.resolve([]),
    saglayicilar: () => Promise.resolve([]),
    gelirTurleri: () => Promise.resolve([]),
    kaynaklar: () => Promise.resolve([]),
    giderKaynaklari: () => Promise.resolve([]),
    grafikler: () => new Promise(() => {}),
  },
}))

describe('Kartlar: ekstre dönemi seçici', () => {
  beforeEach(() => {
    liste.mockReset()
    vi.stubGlobal('matchMedia', () => ({
      matches: false,
      addEventListener: () => {},
      removeEventListener: () => {},
    }))
    vi.stubGlobal(
      'IntersectionObserver',
      class {
        observe(): void {}
        disconnect(): void {}
      },
    )
    vi.stubGlobal(
      'ResizeObserver',
      class {
        observe(): void {}
        disconnect(): void {}
        unobserve(): void {}
      },
    )
  })

  async function kur() {
    const { default: KartlarView } = await import('@/views/aile/KartlarView.vue')
    setActivePinia(createPinia())
    const w = mount(KartlarView, {
      global: {
        plugins: [PrimeVue],
        stubs: {
          RouterLink: { template: '<a><slot /></a>' },
          KartFormu: true,
          EkstreYukleDialog: true,
          ElleEkstreDialog: true,
          KartGrafigi: true,
        },
      },
    })
    await flushPromises()

    return w
  }

  it('varsayılan en yeni dönem; geri gidince o dönemin ekstre borcu; ileri en yeni dönemde kapalı', async () => {
    liste.mockResolvedValue([
      ekstre(1, '2026-10', 3000),
      ekstre(2, '2026-11', 4500),
      ekstre(3, '2026-12', 999, { durum: 'taslak' }),
      ekstre(4, '2026-11', 800, { kart_id: 2 }),
    ])
    const w = await kur()
    const secici = w.get('[data-test="kart-donem-secici"]')
    expect(secici.get('[data-test="ay-secici"]').text()).toBe('Kasım 2026')
    const kartMetni = () =>
      w.findAll('[data-test="kart-satiri"]').map((e) => e.text().replace(/\s+/g, ' '))
    expect(kartMetni()[0]).toContain('Kasım 2026 ekstre borcu')
    expect(kartMetni()[0]).toContain('4.500,00')
    expect(kartMetni()[1]).toContain('800,00')
    expect(secici.get('button[aria-label="Sonraki ay"]').attributes('disabled')).toBeDefined()
    expect(w.find('[data-test="kart-donem-en-yeni"]').exists()).toBe(false)
    // Geri: Ekim 2026 — Bonus'un Ekim ekstresi, Maximum'un o dönem ekstresi yok
    await secici.get('button[aria-label="Önceki ay"]').trigger('click')
    expect(secici.get('[data-test="ay-secici"]').text()).toBe('Ekim 2026')
    expect(kartMetni()[0]).toContain('Ekim 2026 ekstre borcu')
    expect(kartMetni()[0]).toContain('3.000,00')
    expect(kartMetni()[1]).toContain('Bu dönem ekstre yok')
    // En yeni'ye dön
    await w.get('[data-test="kart-donem-en-yeni"]').trigger('click')
    expect(secici.get('[data-test="ay-secici"]').text()).toBe('Kasım 2026')
    w.unmount()
  })

  it('onaylı ekstre yoksa seçici görünmez', async () => {
    liste.mockResolvedValue([ekstre(3, '2026-12', 999, { durum: 'taslak' })])
    const w = await kur()
    expect(w.find('[data-test="kart-donem-secici"]').exists()).toBe(false)
    expect(w.findAll('[data-test="kart-satiri"]')[0]!.text()).toContain('Bu dönem ekstre yok')
    w.unmount()
  })
})
