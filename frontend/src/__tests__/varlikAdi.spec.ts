import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import PrimeVue from 'primevue/config'
import ConfirmationService from 'primevue/confirmationservice'
import ToastService from 'primevue/toastservice'
import type { Islem, Pozisyon, Varlik } from '@/api/finans'

const ASELS: Varlik = {
  id: 3,
  tur: 'hisse',
  kod: 'ASELS',
  ad: 'Aselsan Elektronik Sanayi ve Ticaret A.Ş.',
  birim: 'pay',
  para_birimi: 'TRY',
  kaynak: 'yahoo',
  gecikmeli: true,
  fiyat: null,
}
const genel = {
  plugins: [PrimeVue, ConfirmationService, ToastService],
  stubs: { RouterLink: { template: '<a><slot /></a>' } },
}

beforeEach(() => {
  vi.resetModules()
  vi.stubGlobal('matchMedia', () => ({
    matches: true,
    addEventListener: () => {},
    removeEventListener: () => {},
  }))
})
afterEach(() => {
  vi.unstubAllGlobals()
})

/** Satırdaki ilk varlık yazımının özeti: kod vurgulu ve önce; ad küçük, sığmazsa kısalır */
function yazim(kok: Element) {
  const kod = kok.querySelector('[data-test="varlik-kodu"]')!
  const ad = kok.querySelector('[data-test="varlik-adi"]')!

  return {
    kod: kod.textContent?.trim(),
    kodVurgulu: kod.classList.contains('font-semibold'),
    ad: ad.textContent?.trim(),
    adKucuk: ad.classList.contains('text-xs') && ad.classList.contains('truncate'),
    kodOnce: Boolean(kod.compareDocumentPosition(ad) & Node.DOCUMENT_POSITION_FOLLOWING),
  }
}
const BEKLENEN = { kod: 'ASELS', kodVurgulu: true, ad: ASELS.ad, adKucuk: true, kodOnce: true }

describe('varlık yazımı: kod vurgulu, ad küçük', () => {
  it('pozisyon listesi', async () => {
    const { default: PozisyonListesi } = await import('@/components/PozisyonListesi.vue')
    const poz: Pozisyon = {
      hesap_id: 1,
      varlik: ASELS,
      miktar: 200,
      maliyeti_bilinmeyen_miktar: 0,
      ortalama_maliyet: 140,
      maliyet: 28000,
      fiyat_tl: 182.4,
      deger: 36480,
      kz: 8480,
      kz_yuzde: 30.29,
      gunluk_degisim: null,
      gerceklesen_kz: null,
      gerceklesen_kz_eksik: false,
    }
    const w = mount(PozisyonListesi, { props: { pozisyonlar: [poz] }, global: genel })
    expect(yazim(w.element)).toEqual(BEKLENEN)
  })

  it('işlem listesi (telefon kartı)', async () => {
    const { default: IslemListesi } = await import('@/components/IslemListesi.vue')
    const islem: Islem = {
      id: 1,
      hesap_id: 1,
      varlik_id: ASELS.id,
      tur: 'alis',
      miktar: 200,
      birim_fiyat: 140,
      komisyon: 0,
      tutar: 28000,
      tarih: '2026-11-01',
      not: null,
      varlik: ASELS,
    }
    const w = mount(IslemListesi, { props: { islemler: [islem] }, global: genel })
    const kart = w.get('[data-test="islem-karti"]')
    expect(kart.attributes('aria-label')).toContain('ASELS')
    expect(yazim(kart.element)).toEqual(BEKLENEN)
  })
})
