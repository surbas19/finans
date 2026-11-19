import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import type { KartIslemi, Kategori } from '@/api/aile'
import { useAileStore } from '@/stores/aile'

const atama = vi.fn<(id: number, kategori: number | null) => Promise<unknown>>()
const kat = (id: number, ad: string, kullanim: number, ek: Partial<Kategori> = {}): Kategori => ({
  id,
  ad,
  ikon: null,
  renk: 'sky',
  fatura: false,
  sira: id,
  kullanim,
  ...ek,
})
// Ayar sırası: Giyim, Market, Eğlence, Yeme-içme, Faturalar, Eğitim, Akaryakıt; kullanım: Market 142 > Yeme-içme 87 > Akaryakıt 54 > Giyim 21
const kategoriler = [
  kat(1, 'Giyim', 21),
  kat(2, 'Market', 142),
  kat(3, 'Eğlence', 0),
  kat(4, 'Yeme-içme', 87),
  kat(5, 'Faturalar', 500, { fatura: true }),
  kat(6, 'Eğitim', 0),
  kat(7, 'Akaryakıt', 54),
]
const yenile = vi.fn<() => Promise<Kategori[]>>()
vi.mock('@/api/aile', async (asil) => ({
  ...(await asil<typeof import('@/api/aile')>()),
  aileApi: {
    kartIslemiKategori: (id: number, k: number | null) => atama(id, k),
    kategoriler: () => yenile(),
    kategoriEkle: () => Promise.reject(new Error('yok')),
  },
}))

function islem(id: number): KartIslemi {
  return {
    id,
    kart_id: 1,
    islem_tarihi: '2026-11-03',
    aciklama: `ISYERI ${id}`,
    tutar: 85,
    tur: 'harcama',
    kategori_id: null,
  } as unknown as KartIslemi
}

const genel = {
  plugins: [PrimeVue, ToastService],
  stubs: { KartEtiketi: true, UygulamaPenceresi: { template: '<div><slot /></div>' } },
}

describe('Kategorisiz harcamalar ve en çok kullanılana göre kategori sırası', () => {
  beforeEach(() => {
    atama.mockReset()
    yenile.mockReset()
    yenile.mockResolvedValue(kategoriler)
    setActivePinia(createPinia())
    useAileStore().kategoriler = kategoriler
  })

  it('store: kategoriler kullanım sayısına göre; sık kullanılan 4 (fatura kategorisi hariç)', () => {
    const aile = useAileStore()
    expect(aile.kategorilerKullanima.map((k) => k.ad)).toEqual([
      'Faturalar',
      'Market',
      'Yeme-içme',
      'Akaryakıt',
      'Giyim',
      'Eğlence',
      'Eğitim',
    ])
    expect(aile.sikKategoriler.map((k) => k.ad)).toEqual([
      'Market',
      'Yeme-içme',
      'Akaryakıt',
      'Giyim',
    ])
  })

  async function bolum(islemler: KartIslemi[]) {
    const { default: KategorisizBolumu } = await import('@/components/aile/KategorisizBolumu.vue')
    const w = mount(KategorisizBolumu, { props: { islemler }, global: genel })
    await flushPromises()

    return w
  }

  it('başlıkta sayı; her satırda en çok kullanılan 4 kategori çipi ve Diğer', async () => {
    const w = await bolum([islem(1), islem(2)])
    expect(w.get('[data-test="kategorisiz-baslik"]').text()).toContain('(2)')
    const satirlar = w.findAll('[data-test="kategorisiz-satir"]')
    expect(satirlar).toHaveLength(2)
    expect(satirlar[0]!.findAll('[data-test="kategorisiz-cip"]').map((c) => c.text())).toEqual([
      'Market',
      'Yeme-içme',
      'Akaryakıt',
      'Giyim',
    ])
    expect(satirlar[0]!.find('[data-test="kategorisiz-diger"]').exists()).toBe(true)
    expect(satirlar[0]!.text()).toContain('ISYERI 1')
    w.unmount()
  })

  it('çipe dokununca kategori sunucuya gider, sayılar yenilenir, liste yenilensin diye olay gelir', async () => {
    atama.mockResolvedValue({ message: '3 işlem "Market" olarak işaretlendi.' })
    const w = await bolum([islem(7)])
    await w.get('[data-test="kategorisiz-cip"]').trigger('click')
    await flushPromises()
    expect(atama).toHaveBeenCalledWith(7, 2)
    expect(yenile).toHaveBeenCalledTimes(1)
    expect(w.emitted('degisti')).toHaveLength(1)
    w.unmount()
  })

  it('Diğer tam seçiciyi açtıracak olayı verir; 15’ten fazlasında "Tümünü göster"', async () => {
    const liste = Array.from({ length: 20 }, (_, i) => islem(i + 1))
    const w = await bolum(liste)
    expect(w.findAll('[data-test="kategorisiz-satir"]')).toHaveLength(15)
    await w.get('[data-test="kategorisiz-tumu"]').trigger('click')
    expect(w.findAll('[data-test="kategorisiz-satir"]')).toHaveLength(20)
    await w.get('[data-test="kategorisiz-diger"]').trigger('click')
    expect(w.emitted('diger')?.[0]?.[0]).toMatchObject({ id: 1 })
    w.unmount()
  })

  it('kategori seçici: ızgara kullanım sırasıyla, otomatik atanan fatura kategorisi sonda', async () => {
    const { default: KategoriSecDialog } = await import('@/components/aile/KategoriSecDialog.vue')
    const w = mount(KategoriSecDialog, { props: { islem: islem(7), visible: true }, global: genel })
    await flushPromises()
    const adlar = w.findAll('[data-test="kategori-secenegi"] span.truncate').map((e) => e.text())
    // Kullanım sırası; otomatik atanan fatura kategorisi en sonda
    expect(adlar).toEqual([
      'Market',
      'Yeme-içme',
      'Akaryakıt',
      'Giyim',
      'Eğlence',
      'Eğitim',
      'Faturalar',
    ])
    w.unmount()
  })
})
