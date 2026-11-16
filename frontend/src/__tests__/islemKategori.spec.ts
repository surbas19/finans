import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import type { Kategori, KartIslemi, SirketGecmisi } from '@/api/aile'
import { useAileStore } from '@/stores/aile'

const atama = vi.fn<(id: number, kategori: number | null) => Promise<unknown>>()
const ekle = vi.fn<(ad: string, renk?: string | null) => Promise<Kategori>>()
const gecmis = vi.fn<(id: number) => Promise<SirketGecmisi>>()
const kategoriler: Kategori[] = [
  { id: 1, ad: 'Market', ikon: null, renk: 'emerald', fatura: false, sira: 1 },
  { id: 2, ad: 'Giyim', ikon: null, renk: 'pink', fatura: false, sira: 2 },
]
vi.mock('@/api/aile', async (asil) => ({
  ...(await asil<typeof import('@/api/aile')>()),
  aileApi: {
    kartIslemiKategori: (id: number, k: number | null) => atama(id, k),
    kartIslemiGecmisi: (id: number) => gecmis(id),
    kategoriEkle: (ad: string, renk?: string | null) => ekle(ad, renk),
    hane: () => Promise.resolve({ hane: { id: 1, ad: 'Aile' }, uyeler: [], ben_uye_id: 1 }),
    bankalar: () => Promise.resolve([]),
    kartlar: () => Promise.resolve([]),
    kategoriler: () => Promise.resolve(kategoriler),
    faturaTurleri: () => Promise.resolve([]),
    saglayicilar: () => Promise.resolve([]),
    gelirTurleri: () => Promise.resolve([]),
    kaynaklar: () => Promise.resolve([]),
    giderKaynaklari: () => Promise.resolve([]),
  },
}))

function islem(id: number, ek: Partial<KartIslemi> = {}): KartIslemi {
  return {
    id,
    kart_id: 1,
    ekstre_id: null,
    durum: 'onayli',
    islem_tarihi: '2026-11-03',
    aciklama: `KAHVE ${id}`,
    tutar: 100,
    tur: 'harcama',
    taksit_no: null,
    taksit_sayisi: null,
    kategori_id: null,
    ekstre_donemi: null,
    ...ek,
  } as unknown as KartIslemi
}

const genel = {
  plugins: [PrimeVue, ToastService],
  stubs: { KartEtiketi: true, UygulamaPenceresi: { template: '<div><slot /></div>' } },
}

// jsdom'da PointerEvent yok: konumlu fare olayı pointer olayı adıyla gönderilir
const nokta = (e: Element, ad: string, x: number, y: number): void => {
  e.dispatchEvent(new MouseEvent(ad, { clientX: x, clientY: y, bubbles: true }))
}

describe('Satıra basılı tutunca kategori', () => {
  beforeEach(() => {
    atama.mockReset()
    ekle.mockReset()
    vi.useFakeTimers({ shouldAdvanceTime: true })
    setActivePinia(createPinia())
    useAileStore().kategoriler = kategoriler
  })
  afterEach(() => vi.useRealTimers())

  async function liste(islemler: KartIslemi[]) {
    const { default: KartIslemListesi } = await import('@/components/aile/KartIslemListesi.vue')

    return mount(KartIslemListesi, { props: { islemler }, global: genel })
  }

  it('kısa dokunuş düzenleme olayı verir', async () => {
    const w = await liste([islem(1)])
    await w.get('[data-test="kart-islemi"]').trigger('pointerdown')
    vi.advanceTimersByTime(200)
    await w.get('[data-test="kart-islemi"]').trigger('pointerup')
    await w.get('[data-test="kart-islemi"]').trigger('click')
    vi.advanceTimersByTime(600)
    expect(w.emitted('sec')).toHaveLength(1)
    expect(w.emitted('kategori')).toBeUndefined()
  })

  it('450 ms basılı tutunca kategori olayı; sonraki tıklama düzenlemeyi açmaz', async () => {
    const i = islem(1)
    const w = await liste([i])
    const satir = w.get('[data-test="kart-islemi"]')
    await satir.trigger('pointerdown')
    vi.advanceTimersByTime(449)
    expect(w.emitted('kategori')).toBeUndefined()
    vi.advanceTimersByTime(2)
    expect(w.emitted('kategori')?.[0]?.[0]).toMatchObject({ id: 1 })
    await satir.trigger('pointerup')
    await satir.trigger('click')
    expect(w.emitted('sec')).toBeUndefined()
    // Sonraki gerçek dokunuş yine düzenleme açar
    await satir.trigger('click')
    expect(w.emitted('sec')).toHaveLength(1)
  })

  it('parmak kayarsa ya da bırakılırsa iptal; sağ tık (contextmenu) kategoriyi açar', async () => {
    const w = await liste([islem(1)])
    const satir = w.get('[data-test="kart-islemi"]')
    nokta(satir.element, 'pointerdown', 10, 10)
    nokta(satir.element, 'pointermove', 10, 40)
    vi.advanceTimersByTime(600)
    expect(w.emitted('kategori')).toBeUndefined()
    nokta(satir.element, 'pointerdown', 10, 10)
    vi.advanceTimersByTime(100)
    await satir.trigger('pointerup')
    vi.advanceTimersByTime(600)
    expect(w.emitted('kategori')).toBeUndefined()
    await satir.trigger('contextmenu')
    expect(w.emitted('kategori')).toHaveLength(1)
  })

  it('tarih kutusu kategori renginde (açık zemin, koyu yazı); kategorisizde gri; kategori adı gösterilir', async () => {
    const w = await liste([islem(1, { kategori_id: 1 }), islem(2), islem(3, { kategori_id: 2 })])
    const kutular = w.findAll('[data-test="tarih-kutusu"]')
    const stil = (n: number) => kutular[n]!.attributes('style') ?? ''
    expect(kutular[0]!.findAll('span').map((s) => s.text())).toEqual(['03', 'Kas'])
    expect(stil(0)).toContain('--p-emerald-100')
    expect(stil(0)).toContain('--p-emerald-700')
    expect(stil(1)).toContain('--p-surface-100')
    expect(stil(2)).toContain('pink')
    expect(w.find('[data-test="kategori-seridi"]').exists()).toBe(false)
    expect(w.findAll('[data-test="kategori-adi"]').map((e) => e.text())).toEqual([
      'Market',
      'Giyim',
    ])
  })

  async function dialog(i: KartIslemi) {
    const { default: KategoriSecDialog } = await import('@/components/aile/KategoriSecDialog.vue')
    const w = mount(KategoriSecDialog, { props: { islem: i, visible: true }, global: genel })
    await flushPromises()

    return w
  }

  it('kategori seçilince sunucuya gider, bildirim verir ve liste yenilensin diye olay gönderir', async () => {
    atama.mockResolvedValue({ message: '3 işlem "Market" olarak işaretlendi.' })
    const w = await dialog(islem(7))
    const secenekler = w.findAll('[data-test="kategori-secenegi"]')
    expect(secenekler.map((e) => e.text())).toEqual(['Market', 'Giyim'])
    expect(w.find('[data-test="kategorisiz-yap"]').exists()).toBe(false)
    await secenekler[0]!.trigger('click')
    await flushPromises()
    expect(atama).toHaveBeenCalledWith(7, 1)
    expect(w.emitted('degisti')).toHaveLength(1)
    expect(w.emitted('update:visible')?.[0]).toEqual([false])
  })

  it('kategorili işlemde "Kategorisiz yap" null gönderir', async () => {
    atama.mockResolvedValue({ message: 'kaldırıldı' })
    const w = await dialog(islem(7, { kategori_id: 2 }))
    await w.get('[data-test="kategorisiz-yap"]').trigger('click')
    await flushPromises()
    expect(atama).toHaveBeenCalledWith(7, null)
  })

  it('yeni kategori eklenir (kullanılmayan renk önerilir) ve işleme atanır', async () => {
    ekle.mockResolvedValue({ id: 9, ad: 'Tatil', ikon: null, renk: 'teal', fatura: false, sira: 3 })
    atama.mockResolvedValue({ message: 'tamam' })
    const w = await dialog(islem(7))
    await w.get('[data-test="yeni-kategori-ac"]').trigger('click')
    await w.get('[data-test="yeni-kategori-ad"]').setValue('Tatil')
    await w.get('[data-test="yeni-kategori-ekle"]').trigger('submit')
    await w.get('form').trigger('submit')
    await flushPromises()
    expect(ekle).toHaveBeenCalledTimes(1)
    const [ad, renk] = ekle.mock.calls[0]!
    expect(ad).toBe('Tatil')
    expect(['emerald', 'pink']).not.toContain(renk)
    expect(atama).toHaveBeenCalledWith(7, 9)
  })
})

describe('Şirket geçmişi', () => {
  const ornek: SirketGecmisi = {
    aciklama: 'KAHVE 1',
    kategori_id: 1,
    toplam: 1900,
    adet: 4,
    ilk_donem: '2026-09',
    aylik_ortalama: 633.33,
    bu_ay: 700,
    aylar: [
      {
        donem: '2026-11',
        etiket: 'Kasım 2026',
        toplam: 700,
        adet: 3,
        islemler: [
          {
            id: 4,
            kart_id: 1,
            islem_tarihi: '2026-11-08',
            aciklama: 'KAHVE IADE',
            tur: 'iade',
            tutar: 100,
            etki: -100,
            taksit_no: null,
            taksit_sayisi: null,
          },
          {
            id: 1,
            kart_id: 1,
            islem_tarihi: '2026-11-05',
            aciklama: 'KAHVE 1',
            tur: 'harcama',
            tutar: 500,
            etki: 500,
            taksit_no: null,
            taksit_sayisi: null,
          },
          {
            id: 2,
            kart_id: 2,
            islem_tarihi: '2026-11-02',
            aciklama: 'KAHVE 1',
            tur: 'harcama',
            tutar: 300,
            etki: 300,
            taksit_no: 2,
            taksit_sayisi: 6,
          },
        ],
      },
      {
        donem: '2026-09',
        etiket: 'Eylül 2026',
        toplam: 1200,
        adet: 1,
        islemler: [
          {
            id: 3,
            kart_id: 1,
            islem_tarihi: '2026-09-10',
            aciklama: 'KAHVE 1',
            tur: 'harcama',
            tutar: 1200,
            etki: 1200,
            taksit_no: null,
            taksit_sayisi: null,
          },
        ],
      },
    ],
  }
  const stublar = {
    ...genel,
    stubs: { ...genel.stubs, Dialog: { template: '<div><slot name="header" /><slot /></div>' } },
  }

  beforeEach(() => {
    gecmis.mockReset()
    gecmis.mockResolvedValue(ornek)
    setActivePinia(createPinia())
    useAileStore().kategoriler = kategoriler
  })

  it('gidere giren satırda grafik düğmesi; ödeme satırında yok; düğme düzenlemeyi açmaz, geçmişi ister', async () => {
    const { default: KartIslemListesi } = await import('@/components/aile/KartIslemListesi.vue')
    const w = mount(KartIslemListesi, {
      props: { islemler: [islem(1, { kategori_id: 1 }), islem(2, { tur: 'odeme' })] },
      global: stublar,
    })
    const dugmeler = w.findAll('[data-test="sirket-gecmisi-dugmesi"]')
    expect(dugmeler).toHaveLength(1)
    await dugmeler[0]!.trigger('click')
    await flushPromises()
    expect(gecmis).toHaveBeenCalledWith(1)
    expect(w.emitted('sec')).toBeUndefined()
    expect(w.findAll('[data-test="gecmis-ay"]')).toHaveLength(2)
  })

  it('pencere: özet, eğri (Eylül–bu ay, boş ay dahil), ay başlıkları toplamlarıyla, iade eksi', async () => {
    vi.useFakeTimers({ now: new Date('2026-11-13T10:00:00') })
    const { default: Pencere } = await import('@/components/aile/SirketGecmisiDialog.vue')
    const w = mount(Pencere, {
      props: { visible: true, islem: islem(1, { kategori_id: 1 }) },
      global: stublar,
    })
    await flushPromises()
    const ozet = w.get('[data-test="gecmis-ozet"]').text().replace(/\s+/g, ' ')
    expect(ozet).toContain('1.900,00')
    expect(ozet).toContain('633,33')
    expect(ozet).toContain('700,00')
    expect(w.get('[data-test="gecmis-egri"]').attributes('aria-label')).toContain('3 ay')
    expect(w.findAll('[data-test="gecmis-ay-toplam"]').map((e) => e.text())).toEqual([
      '₺700,00',
      '₺1.200,00',
    ])
    const kasim = w.findAll('[data-test="gecmis-ay"]')[0]!.text().replace(/\s+/g, ' ')
    expect(kasim).toContain('Kasım 2026')
    expect(kasim).toContain('−₺100,00')
    expect(kasim).toContain('taksit 2/6')
    expect(w.text()).toContain('ilki Eylül 2026')
    vi.useRealTimers()
  })
})
