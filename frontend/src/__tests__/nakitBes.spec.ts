import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ConfirmationService from 'primevue/confirmationservice'
import InputNumber from 'primevue/inputnumber'
import SelectButton from 'primevue/selectbutton'
import ToastService from 'primevue/toastservice'
import {
  islemEtiketi,
  islemTurleri,
  sabitFiyatli,
  type Islem,
  type Pozisyon,
  type Varlik,
  type VarlikTuru,
} from '@/api/finans'
import { satirlariCoz } from '@/utils/topluIslem'

function varlik(id: number, tur: VarlikTuru, kod: string, ad: string, birim = 'adet'): Varlik {
  return {
    id,
    tur,
    kod,
    ad,
    birim,
    para_birimi: 'TRY',
    kaynak: 'elle',
    gecikmeli: false,
    fiyat: null,
  }
}
const NAKIT = varlik(10, 'nakit', 'TL', 'Nakit', 'TL')
const BES = varlik(11, 'bes', 'BES', 'Bireysel emeklilik', 'TL')
const GRAM = varlik(1, 'altin', 'GRAM', 'Gram altın', 'gram')

beforeEach(() => {
  vi.resetModules()
  vi.stubGlobal('matchMedia', () => ({
    matches: false,
    addEventListener: () => {},
    removeEventListener: () => {},
  }))
  // Textarea (auto-resize) ister; test ortamında yok
  vi.stubGlobal(
    'ResizeObserver',
    class {
      observe() {}
      unobserve() {}
      disconnect() {}
    },
  )
})
afterEach(() => {
  vi.unstubAllGlobals()
  vi.doUnmock('@/api/finans')
})

describe('nakit / bireysel emeklilik yardımcıları', () => {
  it('türler ve etiketler', () => {
    expect(sabitFiyatli('nakit')).toBe(true)
    expect(sabitFiyatli('bes')).toBe(true)
    expect(sabitFiyatli('altin')).toBe(false)
    expect(islemTurleri('bes')).toEqual(['deger'])
    expect(islemTurleri('nakit')).toEqual(['alis', 'satis', 'mevcut'])
    expect(islemEtiketi('alis', 'nakit')).toBe('Yatırma')
    expect(islemEtiketi('satis', 'nakit')).toBe('Çekme')
    expect(islemEtiketi('alis', 'altin')).toBe('Alış')
    expect(islemEtiketi('deger', 'bes')).toBe('Güncel tutar')
  })
})

describe('toplu eklemede nakit ve BES', () => {
  const coz = (metin: string) => satirlariCoz(metin, [GRAM, NAKIT, BES], '2026-11-13', '2026-11-13')

  it('nakit kodla ya da adla eşleşir, birim fiyat yok sayılır', () => {
    const [tl, nakit] = coz('TL;5000\nnakit;2.500;3')
    expect(tl!.varlik?.id).toBe(NAKIT.id)
    expect(tl!.hata).toBeNull()
    expect(nakit!.varlik?.id).toBe(NAKIT.id)
    expect(nakit!.birimFiyat).toBeNull()
    expect(nakit!.hata).toBeNull()
  })

  it('bireysel emeklilik satırı hatalı', () => {
    const [satir] = coz('BES;150000')
    expect(satir!.hata).toContain('İşlem ekle')
  })
})

describe('listelerde nakit ve BES', () => {
  const genel = { plugins: [PrimeVue, ConfirmationService, ToastService] }

  it('işlem listesi: nakitte "Yatırma" ve fiyat yok, BES\'te kâr yüzdesi', async () => {
    const { default: IslemListesi } = await import('@/components/IslemListesi.vue')
    const islem = (id: number, v: Varlik, tur: Islem['tur'], ek: Partial<Islem> = {}): Islem => ({
      id,
      hesap_id: 1,
      varlik_id: v.id,
      tur,
      miktar: 5000,
      birim_fiyat: 1,
      komisyon: 0,
      tutar: 5000,
      tarih: '2026-11-01',
      not: null,
      varlik: v,
      ...ek,
    })
    const w = mount(IslemListesi, {
      props: {
        islemler: [
          islem(1, NAKIT, 'alis'),
          islem(2, BES, 'deger', {
            miktar: 150000,
            birim_fiyat: 0.8,
            tutar: 150000,
            kar_yuzde: 25,
          }),
        ],
      },
      global: genel,
    })
    const [nakit, bes] = w.findAll('[data-test="islem-karti"]')
    expect(nakit!.text()).toContain('Yatırma')
    expect(nakit!.text()).not.toContain('×')
    expect(bes!.text()).toContain('Güncel tutar')
    expect(bes!.text()).toContain('+%25,00 kâr')
  })

  it("pozisyon listesi: nakitte K/Z yok, BES'te ödenen tutar", async () => {
    const { default: PozisyonListesi } = await import('@/components/PozisyonListesi.vue')
    const poz = (v: Varlik, ek: Partial<Pozisyon>): Pozisyon => ({
      hesap_id: 1,
      varlik: v,
      miktar: 12000,
      maliyeti_bilinmeyen_miktar: 0,
      ortalama_maliyet: 1,
      maliyet: 12000,
      fiyat_tl: 1,
      deger: 12000,
      kz: 0,
      kz_yuzde: 0,
      gunluk_degisim: null,
      gerceklesen_kz: null,
      gerceklesen_kz_eksik: false,
      ...ek,
    })
    const w = mount(PozisyonListesi, {
      props: {
        pozisyonlar: [
          poz(NAKIT, {}),
          poz(BES, { miktar: 150000, deger: 150000, maliyet: 120000, kz: 30000, kz_yuzde: 25 }),
        ],
      },
      global: { ...genel, stubs: { RouterLink: { template: '<a><slot /></a>' } } },
    })
    const [nakit, bes] = w.findAll('li')
    expect(nakit!.text()).not.toContain('K/Z')
    expect(nakit!.text()).not.toContain('×')
    expect(bes!.text()).toContain('Ödenen')
    expect(bes!.text()).toContain('120.000')
  })
})

describe('İşlem formu', () => {
  async function kur(varsayilan: Record<string, unknown>) {
    const islemEkle = vi.fn<(veri: unknown) => Promise<unknown>>().mockResolvedValue({})
    vi.doMock('@/api/finans', async (asil) => {
      const m = await asil<typeof import('@/api/finans')>()
      return {
        ...m,
        finansApi: {
          ...m.finansApi,
          varliklar: vi
            .fn<() => Promise<unknown>>()
            .mockResolvedValue({ varliklar: [GRAM, NAKIT, BES], turler: [] }),
          hesaplar: vi
            .fn<() => Promise<unknown>>()
            .mockResolvedValue({ hesaplar: [{ id: 1, ad: 'Yatırım' }], turler: [] }),
          islemEkle,
        },
      }
    })
    setActivePinia(createPinia())
    const { default: IslemFormu } = await import('@/components/IslemFormu.vue')
    const w = mount(IslemFormu, {
      props: { visible: false, varsayilan },
      global: { plugins: [PrimeVue, ConfirmationService, ToastService], stubs: { teleport: true } },
    })
    await w.setProps({ visible: true })
    await flushPromises()

    return { w, islemEkle }
  }

  it('nakitte yalnızca tutar; türler Yatırma / Çekme / Elde olan', async () => {
    const { w } = await kur({ hesap_id: 1, varlik_tur: 'nakit', tur: 'mevcut' })
    const turler = w.findComponent(SelectButton).props('options') as { etiket: string }[]
    expect(turler.map((t) => t.etiket)).toEqual(['Yatırma', 'Çekme', 'Elde olan'])
    expect(w.text()).toContain('Tutar (TL)')
    expect(w.text()).not.toContain('Birim fiyat')
    expect(w.text()).not.toContain('Komisyon')
  })

  it('BES: toplam tutar ve kâr yüzdesi, ödenen hesaplanır ve gönderilir', async () => {
    const { w, islemEkle } = await kur({ hesap_id: 1, varlik_tur: 'bes', tur: 'deger' })
    expect(w.findComponent(SelectButton).exists()).toBe(false)
    expect(w.find('[data-test="bes-aciklama"]').exists()).toBe(true)
    expect(w.text()).toContain('Toplam tutar (TL)')
    expect(w.text()).not.toContain('Birim fiyat')

    const [miktar, kar] = w.findAllComponents(InputNumber)
    miktar!.vm.$emit('update:modelValue', 150000)
    kar!.vm.$emit('update:modelValue', 25)
    await flushPromises()
    expect(w.get('[data-test="bes-ozeti"]').text()).toContain('120.000')

    await w.get('form').trigger('submit')
    await flushPromises()
    expect(islemEkle).toHaveBeenCalledWith(
      expect.objectContaining({
        hesap_id: 1,
        varlik_id: BES.id,
        tur: 'deger',
        miktar: 150000,
        kar_yuzde: 25,
        birim_fiyat: null,
      }),
    )
  })
})
