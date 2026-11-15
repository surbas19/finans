import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ConfirmationService from 'primevue/confirmationservice'
import ToastService from 'primevue/toastservice'
import type { Tasarruf, TasarrufOzeti } from '@/api/aile'
import type { Islem } from '@/api/finans'

function kalem(id: number, ek: Partial<Tasarruf> = {}): Tasarruf {
  return {
    id,
    no: `T-${id}`,
    uye_id: null,
    hesap_id: 2,
    hesap_ad: 'Evde',
    islem_id: 30 + id,
    islem_no: `İ-${30 + id}`,
    islem_dahil: true,
    hesaba_eklendi: true,
    gun_id: null,
    gun_ad: null,
    varlik_id: 5,
    varlik_ad: 'Gram altın',
    varlik_kod: 'GRAM',
    varlik_tur: 'altin',
    varlik_birim: 'gr',
    miktar: 5,
    islem_zamani: '2026-11-15T10:00:00+03:00',
    tarih: '2026-11-10',
    donem: '2026-11',
    tutar: 15000,
    not: null,
    ...ek,
  }
}

const ozetSon = vi.fn<(yil: number) => Promise<TasarrufOzeti>>()
vi.mock('@/api/aile', async (asil) => ({
  ...(await asil<typeof import('@/api/aile')>()),
  aileApi: {
    tasarrufOzeti: (yil: number) => ozetSon(yil),
    hane: () => Promise.resolve({ hane: { id: 1, ad: 'Aile' }, uyeler: [], ben_uye_id: 1 }),
  },
}))

function veri(): TasarrufOzeti {
  const bos = (donem: string, etiket: string) => ({
    donem,
    etiket,
    gelir: 0,
    gider: 0,
    tasarruf: 0,
    kalan: 0,
    gider_orani: null,
    tasarruf_orani: null,
    kalan_orani: null,
    kalemler: [] as Tasarruf[],
  })
  const kasim = {
    ...bos('2026-11', 'Kasım 2026'),
    gelir: 50000,
    gider: 20000,
    tasarruf: 21000,
    kalan: 9000,
    gider_orani: 40,
    tasarruf_orani: 42,
    kalan_orani: 18,
    kalemler: [
      kalem(1),
      kalem(2, {
        hesap_id: null,
        hesap_ad: null,
        islem_id: null,
        islem_no: null,
        islem_dahil: null,
        hesaba_eklendi: false,
        gun_ad: 'Mahalle günü',
        not: 'Ayşe teyze',
        miktar: 2,
        tutar: 6000,
      }),
    ],
  }

  return {
    yil: 2026,
    aylar: [bos('2026-10', 'Ekim 2026'), kasim],
    toplam: {
      gelir: 50000,
      gider: 20000,
      tasarruf: 21000,
      kalan: 9000,
      gider_orani: 40,
      tasarruf_orani: 42,
      kalan_orani: 18,
    },
  }
}

const genel = {
  plugins: [PrimeVue, ConfirmationService, ToastService],
  stubs: { TasarrufFormu: true, RouterLink: { template: '<a><slot /></a>' } },
}

describe('Bütçe → Tasarruf sekmesi', () => {
  beforeEach(() => {
    ozetSon.mockReset()
    setActivePinia(createPinia())
  })

  it('yıl özeti, aylık oranlar ve dönem akordiyonu; verilen alım adet + açıklamayla görünür', async () => {
    ozetSon.mockResolvedValue(veri())
    const { default: TasarrufView } = await import('@/views/aile/TasarrufView.vue')
    const w = mount(TasarrufView, { global: genel })
    await flushPromises()

    expect(w.get('[data-test="yil-tasarruf"]').text()).toContain('21.000')
    expect(w.get('[data-test="yil-tasarruf-orani"]').text()).toContain('%42')
    const oranlar = w.findAll('[data-test="ay-orani"]')
    expect(oranlar).toHaveLength(2)
    expect(oranlar[1]!.get('[data-test="oran-metni"]').text()).toContain('Gider %40')
    expect(oranlar[1]!.text()).toContain('Tasarruf %42')
    // Geliri olmayan ayda oran yok
    expect(oranlar[0]!.get('[data-test="oran-metni"]').text()).toContain('—')

    // Kalemler: hesaba alınan + verilen
    const alinan = w.get('[data-test="tasarruf-kalemi"]')
    expect(alinan.text()).toContain('Evde')
    expect(alinan.get('[data-test="tasarruf-adet"]').text()).toContain('5')
    const verilen = w.get('[data-test="tasarruf-kalemi-verilen"]')
    expect(verilen.find('[data-test="verildi-etiketi"]').exists()).toBe(true)
    expect(verilen.text()).toContain('hiçbir hesaba eklenmedi')
    expect(verilen.get('[data-test="tasarruf-aciklama"]').text()).toContain('Mahalle günü')
    expect(verilen.get('[data-test="tasarruf-aciklama"]').text()).toContain('Ayşe teyze')
    expect(verilen.get('[data-test="tasarruf-adet"]').text()).toContain('2')
    // Verilen kalemde işlem düğmesi (dahil) yok
    expect(verilen.find('[data-test="tasarruf-dahil"]').exists()).toBe(false)
    expect(alinan.find('[data-test="tasarruf-dahil"]').exists()).toBe(true)
    // Ay toplamı
    expect(w.get('[data-test="ay-toplami"]').text()).toContain('21.000')
    w.unmount()
  })

  it('işlem numaraları kırmızı, küçük, kalıcı; işlem zamanı alım tarihinden ayrı yazılır', async () => {
    ozetSon.mockResolvedValue(veri())
    const { default: TasarrufView } = await import('@/views/aile/TasarrufView.vue')
    const w = mount(TasarrufView, { global: genel })
    await flushPromises()
    const alinan = w.get('[data-test="tasarruf-kalemi"]')
    const numaralar = alinan.findAll('[data-test="islem-no"]')
    expect(numaralar.map((n) => n.text())).toEqual(['T-1', 'İ-31'])
    for (const n of numaralar) {
      expect(n.classes()).toContain('text-red-600')
      expect(n.classes()).toContain('text-[10px]')
      expect(n.classes()).toContain('lowercase')
    }
    expect(alinan.text()).toContain('Alım: 10.11.2026')
    expect(alinan.get('[data-test="islem-zamani"]').text()).toMatch(
      /İşlem: 15\.11\.2026 \d{2}:\d{2}/,
    )
    w.unmount()
  })
})

describe('İşlem listesi: dahil düğmesi, işlem no ve tarihler', () => {
  function islem(id: number, ek: Partial<Islem> = {}): Islem {
    return {
      id,
      no: `İ-${id}`,
      dahil: true,
      islem_zamani: '2026-11-15T10:00:00+03:00',
      hesap_id: 1,
      varlik_id: 2,
      tur: 'alis',
      miktar: 10,
      birim_fiyat: 100,
      komisyon: 0,
      tutar: 1000,
      tarih: '2026-10-05',
      not: null,
      varlik: {
        id: 2,
        tur: 'altin',
        kod: 'GRAM',
        ad: 'Gram altın',
        birim: 'gr',
        para_birimi: 'TRY',
        kaynak: 'elle',
        gecikmeli: false,
        fiyat: null,
      },
      ...ek,
    }
  }

  it('numara kırmızı, alım tarihi ve işlem zamanı ayrı; hariç satır soluk ve "Hariç" etiketli', async () => {
    const { default: IslemListesi } = await import('@/components/IslemListesi.vue')
    const w = mount(IslemListesi, {
      props: { islemler: [islem(7), islem(8, { dahil: false, tur: 'satis' })] },
      global: genel,
    })
    const satirlar = w.findAll('li')
    expect(satirlar[0]!.attributes('data-dahil')).toBe('evet')
    expect(satirlar[1]!.attributes('data-dahil')).toBe('hayir')
    expect(satirlar[1]!.classes()).toContain('opacity-55')
    expect(satirlar[1]!.find('[data-test="haric-etiketi"]').exists()).toBe(true)
    expect(satirlar[0]!.find('[data-test="haric-etiketi"]').exists()).toBe(false)
    const no = satirlar[0]!.find('[data-test="islem-no"]')
    expect(no.text()).toBe('İ-7')
    expect(no.classes()).toContain('text-red-600')
    expect(satirlar[0]!.text()).toContain('Alım: 05.10.2026')
    expect(satirlar[1]!.text()).toContain('Satım: 05.10.2026')
    expect(satirlar[0]!.find('[data-test="islem-zamani"]').text()).toContain('15.11.2026')
    expect(satirlar[0]!.findAll('[data-test="islem-dahil"]').length).toBeGreaterThan(0)
    w.unmount()
  })
})

describe('Tasarruf formu: kime verildi yazılınca hesap alanı gizlenir', () => {
  it('gün alımında not doluyken hesaba eklenmez uyarısı çıkar', async () => {
    vi.stubGlobal(
      'ResizeObserver',
      class {
        observe(): void {}
        unobserve(): void {}
        disconnect(): void {}
      },
    )
    vi.stubGlobal('matchMedia', () => ({
      matches: false,
      addEventListener: () => {},
      removeEventListener: () => {},
    }))
    vi.doMock('@/api/finans', async (asil) => ({
      ...(await asil<typeof import('@/api/finans')>()),
      finansApi: { hesaplar: () => Promise.resolve({ hesaplar: [{ id: 1, ad: 'Evde' }] }) },
    }))
    vi.doMock('@/stores/katalog', () => ({
      useKatalogStore: () => ({ varliklar: [], yukle: () => Promise.resolve() }),
    }))
    const { default: TasarrufFormu } = await import('@/components/aile/TasarrufFormu.vue')
    const gun = {
      gun: {
        id: 1,
        ad: 'Mahalle günü',
        hesap_id: 1,
        hesap_ad: 'Evde',
        varlik_id: 9,
        varlik_ad: 'Gram altın',
        varlik_kod: 'GRAM',
        varlik_birim: 'gr',
        miktar: '5',
        ay_gunu: 10,
        baslangic: '2026-09-01',
        ay_sayisi: 6,
        bizde_ay: null,
        aktif: true,
      },
      donem: '2026-11',
      tarih: '2026-11-10',
      durum: 'bekliyor' as const,
      vadesi_geldi: true,
      tasarruf_id: null,
    }
    const w = mount(TasarrufFormu, {
      props: { visible: true, gun },
      global: {
        plugins: [PrimeVue, ConfirmationService, ToastService],
        stubs: { UygulamaPenceresi: { template: '<div><slot /></div>' } },
      },
    })
    await flushPromises()
    expect(w.find('[data-test="verilen-uyari"]').exists()).toBe(false)
    expect(w.find('#tas-hesap').exists()).toBe(true)
    await w.get('[data-test="tasarruf-not"]').setValue('Ayşe teyze')
    expect(w.find('[data-test="verilen-uyari"]').exists()).toBe(true)
    expect(w.find('#tas-hesap').exists()).toBe(false)
    vi.doUnmock('@/api/finans')
    vi.doUnmock('@/stores/katalog')
    w.unmount()
  })
})
