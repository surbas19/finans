import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import ZekatSonucKarti from '@/components/zekat/ZekatSonucKarti.vue'
import { ZEKAT_UYARISI, type ZekatSonucu } from '@/api/aile'

const odemeEkle = vi.fn<(veri: unknown) => Promise<unknown>>()
vi.mock('@/api/aile', async (asil) => ({
  ...(await asil<typeof import('@/api/aile')>()),
  aileApi: { zekatOdemeEkle: (veri: unknown) => odemeEkle(veri) },
}))

function sonuc(ek: Partial<ZekatSonucu> = {}): ZekatSonucu {
  return {
    brut: 600000,
    borc: 0,
    net: 600000,
    nisab: { olcut: 'altin', gram: 80.18, birim_fiyat: 6300, tl: 505134 },
    nisab_ustu: true,
    zekat: 15000,
    oran_binde: 25,
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
      {
        hesap_id: 1,
        hesap_ad: 'Yatırım',
        varlik_kod: 'THYAO',
        varlik_ad: 'THY',
        varlik_tur: 'hisse',
        miktar: 10,
        birim_fiyat_tl: null,
        deger: null,
        dahil_edilen: true,
        kapsam_yuzde: 100,
        hesaba_giren: 0,
        dislanma_nedeni: null,
      },
    ],
    borclar: [],
    eksikler: [
      { hesap_ad: 'Yatırım', varlik_kod: 'THYAO', varlik_ad: 'THY', neden: 'Fiyat bulunamadı' },
    ],
    eksik_veri: true,
    ...ek,
  }
}

describe('ZekatSonucKarti', () => {
  const kur = (s: ZekatSonucu, tahmini = false) =>
    mount(ZekatSonucKarti, { props: { sonuc: s, tahmini }, global: { plugins: [PrimeVue] } })

  it('nisab üstünde zekât tutarını ve sabit uyarıyı gösterir', () => {
    const w = kur(sonuc({ eksik_veri: false, eksikler: [] }))
    expect(w.get('[data-test="zekat-tutar"]').text()).toContain('15.000,00')
    expect(w.get('[data-test="zekat-nisab"]').text()).toContain('505.134,00')
    expect(w.get('[data-test="zekat-uyari"]').text()).toBe(ZEKAT_UYARISI)
    expect(w.find('[data-test="zekat-eksik"]').exists()).toBe(false)
    expect(w.find('[data-test="zekat-tahmini"]').exists()).toBe(false)
  })

  it('nisab altında zekât çıkmadığını söyler', () => {
    const w = kur(sonuc({ nisab_ustu: false, zekat: 0, net: 100000, brut: 100000 }))
    expect(w.find('[data-test="zekat-tutar"]').exists()).toBe(false)
    expect(w.get('[data-test="zekat-alti"]').text()).toContain('zekât çıkmıyor')
  })

  it('fiyatı olmayan kalemi sıfır saymaz, eksik veri uyarısı ve "Fiyat yok" gösterir', () => {
    const w = kur(sonuc())
    expect(w.get('[data-test="zekat-eksik"]').text()).toContain('Eksik veri, kesin değil')
    expect(w.get('[data-test="zekat-kalemler"]').text()).toContain('Fiyat yok')
    expect(w.get('[data-test="zekat-kalemler"]').text()).toContain('THYAO')
  })

  it('zekât günü dışında hesaplanınca tahmini uyarısı verir; hesaba girmeyen kalem gri ve nedenli', () => {
    const s = sonuc()
    s.kalemler[0]!.dahil_edilen = false
    s.kalemler[0]!.dislanma_nedeni = 'Hesap zekât hesabına dahil değil'
    const w = kur(s, true)
    expect(w.get('[data-test="zekat-tahmini"]').text()).toContain('tahmin')
    expect(w.get('[data-test="zekat-kalemler"]').text()).toContain(
      'Hesap zekât hesabına dahil değil',
    )
  })
})

describe('ZekatOdemeFormu', () => {
  beforeEach(() => {
    odemeEkle.mockReset()
    vi.stubGlobal('matchMedia', () => ({
      matches: false,
      addEventListener: () => {},
      removeEventListener: () => {},
    }))
  })

  async function kur() {
    const { default: ZekatOdemeFormu } = await import('@/components/zekat/ZekatOdemeFormu.vue')

    return mount(ZekatOdemeFormu, {
      props: { visible: false, yilId: 7, kalan: null },
      global: { plugins: [PrimeVue, ToastService] },
      attachTo: document.body,
    })
  }

  it('tutar boşken göndermez ve hata yazar', async () => {
    const w = await kur()
    await w.setProps({ visible: true })
    await flushPromises()
    await document.body
      .querySelector('form')!
      .dispatchEvent(new Event('submit', { cancelable: true }))
    await flushPromises()
    expect(odemeEkle).not.toHaveBeenCalled()
    expect(document.body.textContent).toContain('Tutar sıfırdan büyük olmalı.')
    w.unmount()
  })

  it('geçerli tutarla yıla bağlı ödemeyi gönderir ve kaydedildi bildirir', async () => {
    odemeEkle.mockResolvedValue({ id: 1 })
    const w = await kur()
    await w.setProps({ visible: true, kalan: 15000 })
    await flushPromises()
    // Kalan tutar önerilir
    const girdi = document.body.querySelector<HTMLInputElement>('input#zo-tutar')!
    expect(girdi.value).toContain('15.000')
    await document.body
      .querySelector('form')!
      .dispatchEvent(new Event('submit', { cancelable: true }))
    await flushPromises()
    expect(odemeEkle).toHaveBeenCalledTimes(1)
    expect(odemeEkle.mock.calls[0]![0]).toMatchObject({
      zekat_yili_id: 7,
      tutar: 15000,
      alici: null,
    })
    expect(w.emitted('kaydedildi')).toHaveLength(1)
    w.unmount()
  })
})
