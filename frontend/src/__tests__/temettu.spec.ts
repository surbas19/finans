import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import PrimeVue from 'primevue/config'
import ConfirmationService from 'primevue/confirmationservice'
import ToastService from 'primevue/toastservice'
import { HISSE_OLAYLARI, ISLEM_TURLERI, islemTurleri, type Islem } from '@/api/finans'

beforeEach(() => {
  vi.resetModules()
  vi.stubGlobal('matchMedia', () => ({
    matches: false,
    addEventListener: () => {},
    removeEventListener: () => {},
  }))
})
afterEach(() => {
  vi.unstubAllGlobals()
})

describe('temettü / bedelsiz türleri', () => {
  it('yalnızca hissede sunulur', () => {
    expect(islemTurleri('hisse')).toEqual(['alis', 'satis', 'mevcut', 'temettu', 'bedelsiz'])
    for (const tur of ['altin', 'doviz', 'kripto', 'fon', 'nakit'] as const) {
      expect(islemTurleri(tur)).not.toContain('temettu')
      expect(islemTurleri(tur)).not.toContain('bedelsiz')
    }
    expect(islemTurleri('bes')).toEqual(['deger'])
    expect(HISSE_OLAYLARI.map((t) => ISLEM_TURLERI[t])).toEqual(['Temettü', 'Bedelsiz'])
  })
})

describe('IslemListesi temettü ve bedelsiz satırları', () => {
  const ortak = {
    hesap_id: 1,
    birim_fiyat: null,
    komisyon: 0,
    kar_yuzde: null,
    not: null,
    dahil: true,
    islem_zamani: null,
    hesap_ad: 'Yatırım',
  }
  const thyao = { id: 1, tur: 'hisse', kod: 'THYAO', ad: 'Türk Hava Yolları', birim: 'pay' }
  const satirlar = [
    {
      ...ortak,
      id: 1,
      no: 'İ-1',
      varlik_id: 1,
      tur: 'temettu',
      miktar: 85,
      komisyon: 15,
      tutar: 85,
      stopaj: 15,
      brut: 100,
      tarih: '2026-11-03',
      aktarim_kodu: 'k1',
      bag_turu: 'temettu',
      varlik: thyao,
    },
    {
      ...ortak,
      id: 2,
      no: 'İ-2',
      varlik_id: 9,
      tur: 'alis',
      miktar: 85,
      birim_fiyat: 1,
      tutar: 85,
      tarih: '2026-11-03',
      not: 'THYAO temettüsü',
      aktarim_kodu: 'k1',
      bag_turu: 'temettu',
      varlik: { id: 9, tur: 'nakit', kod: 'TL', ad: 'Nakit', birim: 'TL' },
    },
    {
      ...ortak,
      id: 3,
      no: 'İ-3',
      varlik_id: 1,
      tur: 'bedelsiz',
      miktar: 100,
      tutar: null,
      oran_yuzde: 100,
      tarih: '2026-11-01',
      varlik: thyao,
    },
  ] as unknown as Islem[]

  it('temettüde brüt / stopaj, nakitte "Temettü" rozeti, bedelsizde oran', async () => {
    const { default: IslemListesi } = await import('@/components/IslemListesi.vue')
    const sayfa = mount(IslemListesi, {
      props: { islemler: satirlar },
      global: { plugins: [PrimeVue, ConfirmationService, ToastService] },
    })
    const li = sayfa.findAll('li')
    expect(li).toHaveLength(3)

    const [temettu, nakit, bedelsiz] = li
    expect(temettu!.text()).toContain('Temettü')
    expect(temettu!.text()).toContain('stopaj')
    expect(temettu!.text()).not.toContain('komisyon')
    // Hisse satırında ikinci rozet yok; nakit satırında "Temettü" rozeti (Aktarım değil)
    expect(temettu!.find('[data-test="aktarim-rozeti"]').exists()).toBe(false)
    const rozet = nakit!.find('[data-test="aktarim-rozeti"]')
    expect(rozet.exists()).toBe(true)
    expect(rozet.text()).toBe('Temettü')
    expect(nakit!.text()).not.toContain('Aktarım')

    expect(bedelsiz!.text()).toContain('Bedelsiz')
    expect(bedelsiz!.text()).toMatch(/%100/)
    expect(bedelsiz!.text()).not.toContain('—')
  })
})
