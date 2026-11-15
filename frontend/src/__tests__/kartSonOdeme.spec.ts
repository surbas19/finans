import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import type { Ekstre } from '@/api/aile'

const gun = (fark: number): string => {
  const d = new Date()
  d.setDate(d.getDate() + fark)

  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function ekstre(id: number, ek: Partial<Ekstre>): Ekstre {
  return {
    id,
    kart_id: 1,
    durum: 'onaylandi',
    donem: '2026-11',
    donem_etiketi: 'Kasım 2026',
    donem_baslangic: null,
    kesim_tarihi: null,
    son_odeme_tarihi: gun(3),
    donem_borcu: 4500,
    asgari_odeme: null,
    alan_kaynaklari: {},
    okunamayan: [],
    odendi: false,
    dosya_var: false,
    dosya_adi: null,
    sayfa_sayisi: null,
    onaylandi_at: null,
    olusturuldu: null,
    ...ek,
  }
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
      ]),
    kategoriler: () => Promise.resolve([]),
    faturaTurleri: () => Promise.resolve([]),
    saglayicilar: () => Promise.resolve([]),
    gelirTurleri: () => Promise.resolve([]),
    kaynaklar: () => Promise.resolve([]),
    giderKaynaklari: () => Promise.resolve([]),
  },
}))

describe('Kartlar: son ödeme hatırlatıcıları akordiyonu', () => {
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
  })

  it('yalnız onaylı, ödenmemiş, tarihli ekstreler; tarihe göre ve kalan gün metniyle', async () => {
    liste.mockResolvedValue([
      ekstre(1, { son_odeme_tarihi: gun(3) }),
      ekstre(2, { son_odeme_tarihi: gun(-2), donem_borcu: 1000 }),
      ekstre(3, { odendi: true }),
      ekstre(4, { durum: 'taslak' }),
      ekstre(5, { son_odeme_tarihi: null }),
    ])
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
        },
      },
    })
    await flushPromises()
    const akordiyon = w.get('[data-test="son-odeme-akordiyon"]')
    expect(w.get('[data-test="son-odeme-baslik"]').text()).toContain('(2)')
    const satirlar = akordiyon.findAll('[data-test="son-odeme-satiri"]')
    expect(satirlar).toHaveLength(2)
    // Geçen önce: kırmızı
    expect(satirlar[0]!.text()).toContain('2 gün geçti')
    expect(satirlar[0]!.classes().join(' ')).toContain('bg-red-50')
    expect(satirlar[1]!.text()).toContain('3 gün kaldı')
    expect(satirlar[1]!.classes().join(' ')).toContain('bg-amber-50')
    w.unmount()
  })

  it('hatırlatılacak ekstre yoksa akordiyon görünmez', async () => {
    liste.mockResolvedValue([ekstre(3, { odendi: true })])
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
        },
      },
    })
    await flushPromises()
    expect(w.find('[data-test="son-odeme-akordiyon"]').exists()).toBe(false)
    w.unmount()
  })
})
