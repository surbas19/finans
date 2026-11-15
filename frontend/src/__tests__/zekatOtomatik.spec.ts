import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AxiosError, type AxiosResponse } from 'axios'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ConfirmationService from 'primevue/confirmationservice'
import ToastService from 'primevue/toastservice'

const hesapla = vi.fn<() => Promise<unknown>>()
vi.mock('@/api/aile', async (asil) => ({
  ...(await asil<typeof import('@/api/aile')>()),
  aileApi: {
    zekatAyar: () =>
      Promise.resolve({
        ayar: {
          nisab_olcutu: 'altin',
          nisab_altin_gram: 80.18,
          nisab_gumus_gram: 561,
          oran_binde: 25,
          zekat_gunu_turu: 'hicri',
        },
        zekat_gunu: { tarih: '2027-02-08', tahmini: true, yontem: 'intl', gun_kaldi: 85 },
        turler: [],
        hesaplar: [],
        varsayilanlar: { nisab_altin_gram: 80.18, nisab_gumus_gram: 561, oran_binde: 25 },
        uyari: '',
      }),
    zekatYillari: () => Promise.resolve([]),
    zekatDonemleri: () =>
      Promise.resolve([
        {
          baslangic: '2026-02-18',
          bitis: '2027-02-07',
          etiket: 'Ramazan 1447 → Ramazan 1448',
          hicri: true,
          guncel: true,
          yil: null,
          gereken: null,
          verilen: 0,
          kalan: null,
          fazla: null,
          odemeler: [],
        },
      ]),
    zekatHesapla: () => hesapla(),
  },
}))

const sonuc = {
  tarih: '2026-11-15',
  brut: 630000,
  borc: 0,
  net: 630000,
  nisab: { olcut: 'altin', gram: 80.18, birim_fiyat: 6300, tl: 505134 },
  nisab_ustu: true,
  zekat: 15750,
  oran_binde: 25,
  borc_dusuldu: false,
  kalemler: [
    {
      hesap_id: 1,
      hesap_ad: 'Yatırım',
      varlik_kod: 'GRAM',
      varlik_ad: 'Gram altın',
      varlik_tur: 'altin',
      miktar: 100,
      birim_fiyat_tl: 6300,
      deger: 630000,
      dahil_edilen: true,
      kapsam_yuzde: 100,
      hesaba_giren: 630000,
      dislanma_nedeni: null,
    },
  ],
  borclar: [],
  eksikler: [],
  eksik_veri: false,
  notlar: [],
  tahmini: true,
  zekat_gunu: { tarih: '2027-02-08', tahmini: true, yontem: 'intl', gun_kaldi: 85 },
}

const genel = {
  plugins: [PrimeVue, ConfirmationService, ToastService],
  stubs: { ZekatAyarlari: true, ZekatOdemeFormu: true, UygulamaPenceresi: true },
}

async function kur() {
  const { default: ZekatView } = await import('@/views/aile/ZekatView.vue')
  setActivePinia(createPinia())
  const w = mount(ZekatView, { global: genel })
  await flushPromises()

  return w
}

describe('Zekât ekranı: otomatik anlık hesap', () => {
  beforeEach(() => {
    hesapla.mockReset()
    vi.stubGlobal('matchMedia', () => ({
      matches: false,
      addEventListener: () => {},
      removeEventListener: () => {},
    }))
  })

  it('ekran açılınca düğmesiz hesaplar; varlık ayrıntısı yok; ödenen zekâtların üstünde', async () => {
    hesapla.mockResolvedValue(sonuc)
    const w = await kur()
    expect(hesapla).toHaveBeenCalledTimes(1)
    expect(w.find('[data-test="zekat-hesapla"]').exists()).toBe(false)
    expect(w.get('[data-test="zekat-tutar"]').text()).toContain('15.750,00')
    expect(w.find('[data-test="zekat-kalemler"]').exists()).toBe(false)
    expect(w.text()).not.toContain('Gram altın')
    // Sıra: anlık hesap → verilen zekât bölümü
    const html = w.html()
    expect(html.indexOf('data-test="anlik-hesap-baslik"')).toBeGreaterThan(-1)
    expect(html.indexOf('data-test="anlik-hesap-baslik"')).toBeLessThan(
      html.indexOf('data-test="zekat-verilen-bolumu"'),
    )
    expect(w.find('[data-test="zekat-kaydet"]').exists()).toBe(true)
    w.unmount()
  })

  it('nisab fiyatı yoksa sunucu hatası gösterilir, hesap uydurulmaz', async () => {
    hesapla.mockRejectedValue(
      new AxiosError('422', '422', undefined, undefined, {
        status: 422,
        data: {
          message: 'Altın fiyatı yok',
          errors: { nisab: ['Nisab için altın fiyatı bulunamadı.'] },
        },
      } as AxiosResponse),
    )
    const w = await kur()
    expect(w.find('[data-test="zekat-tutar"]').exists()).toBe(false)
    expect(w.get('[data-test="zekat-hata"]').text()).toContain('Nisab')
    w.unmount()
  })
})
