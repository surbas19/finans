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

const bos = { islemler: [], toplam: { gider: 0, gidere_girmeyen: 0, adet: 0, kartlar: [] } }
const satir = (id: number, kart: number) => ({
  id,
  kart_id: kart,
  aciklama: `SATIR ${id}`,
  tutar: 10,
  tur: 'harcama',
  islem_tarihi: '2026-11-03',
  kategori_id: null,
})
const iki = {
  islemler: [satir(1, 1), satir(2, 1), satir(3, 2)],
  toplam: {
    gider: 30,
    gidere_girmeyen: 0,
    adet: 3,
    kartlar: [
      { kart_id: 1, gider: 20, adet: 2, ekstre_donemi: 'Kasım 2026' },
      { kart_id: 2, gider: 10, adet: 1, ekstre_donemi: null },
    ],
  },
}

async function kur() {
  const { default: AileIslemlerView } = await import('@/views/aile/AileIslemlerView.vue')
  setActivePinia(createPinia())
  const w = mount(AileIslemlerView, {
    global: {
      plugins: [PrimeVue],
      stubs: {
        KartIslemFormu: true,
        KartIslemListesi: true,
        KartEtiketi: true,
        KategoriSecDialog: true,
      },
    },
  })
  await flushPromises()

  return w
}

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

  it('açılışta bütün ekstre işlemleri istenir (tarih / dönem süzgeci yok); tarih seçici, kısayol çipleri, Özet ayı düğmesi yok', async () => {
    const w = await kur()
    expect(sorgu).toHaveBeenCalledTimes(1)
    const istek = sorgu.mock.calls[0]![0]
    expect(istek).toMatchObject({ ay: null, ara: null })
    for (const anahtar of ['guncel', 'donem_turu', 'tarih_bas', 'tarih_bit']) {
      expect(istek[anahtar] ?? null).toBeNull()
    }
    for (const yok of [
      'Özet ayı',
      'Ekstre dönemi',
      'İşlem ekle',
      'Bu ay',
      'Geçen ay',
      'Son 30 gün',
    ]) {
      expect(w.text()).not.toContain(yok)
    }
    expect(w.find('[data-test="hizli-tarih"]').exists()).toBe(false)
    expect(w.find('[data-test="islem-ara"]').exists()).toBe(true)
    expect(w.find('[data-test="islem-tarih"]').exists()).toBe(false)
    expect(w.find('.p-datepicker').exists()).toBe(false)
    w.unmount()
  })

  it('kart başına akordiyon: süzgeçsizken yalnız ilk kart açık; arama yapılınca hepsi açık', async () => {
    sorgu.mockResolvedValue(iki)
    const w = await kur()
    expect(w.findAll('[data-test="kart-grubu-baslik"]')).toHaveLength(2)
    const aktif = () => w.findAll('.p-accordionpanel-active').length
    expect(aktif()).toBe(1)
    expect(w.get('[data-test="kart-grubu-baslik"]').text()).toContain('2 satır')
    expect(w.get('[data-test="kart-grubu-baslik"]').text()).toContain('Kasım 2026 ekstresi')
    // Süzgeç uygulanınca (arama) bütün gruplar açık
    sorgu.mockClear()
    await w.get('[data-test="islem-ara"]').setValue('satir')
    vi.advanceTimersByTime(400)
    await flushPromises()
    expect(sorgu.mock.calls[0]![0]).toMatchObject({ ara: 'satir' })
    expect(aktif()).toBe(2)
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
    expect(sorgu.mock.calls[sorgu.mock.calls.length - 1]![0]).toMatchObject({ ara: null, ay: null })
    w.unmount()
  })

  it('Özet bağlantısındaki ?ay= çip olarak görünür ve istekte ay gider; çip kaldırılınca bütün işlemler', async () => {
    route.query = { ay: '2026-02' }
    sorgu.mockResolvedValue(iki)
    const w = await kur()
    expect(sorgu.mock.calls[0]![0]).toMatchObject({ ay: '2026-02' })
    expect(w.get('[data-test="ay-cipi"]').text()).toContain('Şubat 2026')
    // Süzgeç olduğundan bütün kart grupları açık
    expect(w.findAll('.p-accordionpanel-active')).toHaveLength(2)
    sorgu.mockClear()
    await w.get('[data-test="ay-cipi"]').trigger('click')
    await flushPromises()
    expect(sorgu.mock.calls[0]![0]).toMatchObject({ ay: null })
    expect(w.find('[data-test="ay-cipi"]').exists()).toBe(false)
    expect(degistir.mock.calls[degistir.mock.calls.length - 1]![0].query.ay).toBeUndefined()
    w.unmount()
  })
})
