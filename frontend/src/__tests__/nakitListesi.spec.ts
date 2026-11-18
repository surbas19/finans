import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import type { DuzenliGider } from '@/api/aile'

const g = (id: number, aciklama: string, tutar: number, alan: Partial<DuzenliGider> = {}) => ({
  id,
  gider_kaynagi_id: null,
  kategori_id: null,
  aciklama,
  tutar,
  tarih: '2026-11-10',
  odeme_yeri: null,
  donem: '2026-11',
  not: null,
  ...alan,
})
const kalemler = [
  g(1, 'Okul taksiti', 15000, { gider_kaynagi_id: 7 }),
  g(2, 'Simit', 40, { odeme_yeri: 'ev' }),
  g(3, 'Market', 250.5, { odeme_yeri: 'ev' }),
  g(4, 'Berber', 300, { odeme_yeri: 'banka' }),
  g(5, 'Eski kayıt', 10),
]
const ozet = {
  gider: {
    toplam: 15600.5,
    kart_harcamasi: 0,
    kartsiz_faturalar: 0,
    kartlar: [],
    kategoriler: [],
    beklenenler: [],
    duzenli: { toplam: 15600.5, kalemler },
  },
}
vi.mock('@/api/aile', async (asil) => ({
  ...(await asil<typeof import('@/api/aile')>()),
  aileApi: {
    ozet: () => Promise.resolve(ozet),
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

async function kur() {
  const { default: GiderlerView } = await import('@/views/aile/GiderlerView.vue')
  setActivePinia(createPinia())
  const w = mount(GiderlerView, {
    props: { donem: '2026-11' },
    global: {
      plugins: [PrimeVue, ToastService],
      stubs: {
        GiderFormu: true,
        GiderKaynagiFormu: true,
        NakitHarcamaFormu: true,
        ElleEkstreDialog: true,
        KartEtiketi: true,
        KisiEtiketi: true,
        RouterLink: true,
      },
    },
  })
  await flushPromises()

  return w
}

describe('Gider panelinde nakit harcamalar listesi', () => {
  beforeEach(() => {
    vi.stubGlobal('matchMedia', () => ({
      matches: false,
      addEventListener: () => {},
      removeEventListener: () => {},
    }))
  })

  it('düzenli ödemeler ayrı, nakit harcamalar (kaynaksız) kendi bölümünde; toplam ve etiketler', async () => {
    const w = await kur()
    const duzenli = w.get('[data-test="gider-duzenli-liste"]').text()
    expect(duzenli).toContain('Okul taksiti')
    expect(duzenli).not.toContain('Simit')
    const satirlar = w.findAll('[data-test="nakit-satir"]')
    expect(satirlar.map((s) => s.text())).toEqual([
      expect.stringContaining('Simit'),
      expect.stringContaining('Market'),
      expect.stringContaining('Berber'),
      expect.stringContaining('Eski kayıt'),
    ])
    expect(satirlar[0]!.text()).toContain('Ev')
    expect(satirlar[2]!.text()).toContain('Banka')
    expect(w.get('[data-test="nakit-toplam"]').text()).toContain('600,50')
    w.unmount()
  })

  it('Ev / Banka süzgeci listeyi ve toplamı süzer', async () => {
    const w = await kur()
    const dugmeler = w.findAll('[data-test="nakit-suzgec"] .p-togglebutton')
    await dugmeler[1]!.trigger('click')
    expect(w.findAll('[data-test="nakit-satir"]')).toHaveLength(2)
    expect(w.get('[data-test="nakit-toplam"]').text()).toContain('290,50')
    expect(w.text()).toContain('ev nakdinden düşer')
    await dugmeler[2]!.trigger('click')
    expect(w.findAll('[data-test="nakit-satir"]')).toHaveLength(1)
    expect(w.get('[data-test="nakit-toplam"]').text()).toContain('300')
    w.unmount()
  })
})
