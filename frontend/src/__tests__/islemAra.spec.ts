import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'

const sorgu = vi.fn<(s: Record<string, unknown>) => Promise<unknown>>()
const route = { query: {} as Record<string, unknown> }
const degistir = vi.fn<(a: { query: Record<string, unknown> }) => void>()

vi.mock('vue-router', () => ({
  useRoute: () => route,
  useRouter: () => ({ replace: degistir }),
}))
vi.mock('@/api/aile', async (asil) => ({
  ...(await asil<typeof import('@/api/aile')>()),
  aileApi: {
    kartIslemleri: (s: Record<string, unknown>) => sorgu(s),
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

const bos = { islemler: [], toplam: { gider: 0, gidere_girmeyen: 0, adet: 0 } }

async function kur() {
  const { default: AileIslemlerView } = await import('@/views/aile/AileIslemlerView.vue')
  setActivePinia(createPinia())
  const w = mount(AileIslemlerView, {
    global: {
      plugins: [PrimeVue],
      stubs: { KartIslemFormu: true, KartIslemListesi: true },
    },
  })
  await flushPromises()

  return w
}

const ay = (d: Date): string =>
  `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

describe('Bütçe → İşlemler: arama ve tarih aralığı', () => {
  beforeEach(() => {
    sorgu.mockReset()
    sorgu.mockResolvedValue(bos)
    degistir.mockReset()
    route.query = {}
    vi.useFakeTimers({ shouldAdvanceTime: true })
    vi.stubGlobal('matchMedia', () => ({
      matches: false,
      addEventListener: () => {},
      removeEventListener: () => {},
    }))
  })
  afterEach(() => vi.useRealTimers())

  it('açılışta bu ayın başı-sonu işlem tarihi aralığıyla yüklenir; ay / Özet ayı düğmesi yok', async () => {
    const w = await kur()
    const s = new Date()
    expect(sorgu).toHaveBeenCalledTimes(1)
    expect(sorgu.mock.calls[0]![0]).toMatchObject({
      tarih_bas: ay(new Date(s.getFullYear(), s.getMonth(), 1)),
      tarih_bit: ay(new Date(s.getFullYear(), s.getMonth() + 1, 0)),
      ara: null,
    })
    expect(sorgu.mock.calls[0]![0]).not.toHaveProperty('donem_turu')
    expect(w.text()).not.toContain('Özet ayı')
    expect(w.text()).not.toContain('Ekstre dönemi')
    expect(w.text()).not.toContain('İşlem ekle')
    expect(w.find('[data-test="islem-ara"]').exists()).toBe(true)
    expect(w.find('[data-test="islem-tarih"]').exists()).toBe(true)
    w.unmount()
  })

  it('yazarken 300 ms sonra tek istek, ara parametresiyle; adres güncellenir', async () => {
    const w = await kur()
    sorgu.mockClear()
    const kutu = w.get('[data-test="islem-ara"]')
    await kutu.setValue('mig')
    await kutu.setValue('migros')
    vi.advanceTimersByTime(299)
    expect(sorgu).not.toHaveBeenCalled()
    vi.advanceTimersByTime(2)
    await flushPromises()
    expect(sorgu).toHaveBeenCalledTimes(1)
    expect(sorgu.mock.calls[0]![0]).toMatchObject({ ara: 'migros' })
    expect(degistir.mock.calls[degistir.mock.calls.length - 1]![0].query).toMatchObject({
      ara: 'migros',
    })
    w.unmount()
  })

  it('eşleşme yoksa "Eşleşen işlem yok" ve süzgeçleri temizle düğmesi; temizleyince arama sıfırlanır', async () => {
    const w = await kur()
    await w.get('[data-test="islem-ara"]').setValue('yok')
    vi.advanceTimersByTime(400)
    await flushPromises()
    expect(w.text()).toContain('Eşleşen işlem yok')
    sorgu.mockClear()
    await w.get('[data-test="suzgec-temizle"]').trigger('click')
    vi.advanceTimersByTime(400)
    await flushPromises()
    expect((w.get('[data-test="islem-ara"]').element as HTMLInputElement).value).toBe('')
    expect(sorgu.mock.calls[sorgu.mock.calls.length - 1]![0]).toMatchObject({
      ara: null,
      tarih_bas: null,
      tarih_bit: null,
    })
    w.unmount()
  })

  it('Özet bağlantısındaki ?ay= o ayın tamamına çevrilir; adresteki tarih aralığı korunur', async () => {
    route.query = { ay: '2026-02' }
    let w = await kur()
    expect(sorgu.mock.calls[0]![0]).toMatchObject({
      tarih_bas: '2026-02-01',
      tarih_bit: '2026-02-28',
    })
    w.unmount()
    sorgu.mockClear()
    route.query = { tarih_bas: '2026-03-05', tarih_bit: '2026-03-09', ara: 'shell' }
    w = await kur()
    expect(sorgu.mock.calls[0]![0]).toMatchObject({
      tarih_bas: '2026-03-05',
      tarih_bit: '2026-03-09',
      ara: 'shell',
    })
    w.unmount()
  })
})
