import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ConfirmationService from 'primevue/confirmationservice'
import ToastService from 'primevue/toastservice'
import type { AiCalisma, KartIslemi } from '@/api/aile'
import { useAnalizTakibi } from '@/composables/useAnalizTakibi'

const ekstreSatiri = vi.fn<(...a: unknown[]) => Promise<unknown>>()
const ekstreAnalizDurumu = vi.fn<(...a: unknown[]) => Promise<unknown>>()
const ekstreYapayZeka = vi.fn<(...a: unknown[]) => Promise<unknown>>()
vi.mock('@/api/aile', async (asil) => ({
  ...(await asil<typeof import('@/api/aile')>()),
  aileApi: {
    ekstreSatiri: (...a: unknown[]) => ekstreSatiri(...a),
    ekstreAnalizDurumu: (...a: unknown[]) => ekstreAnalizDurumu(...a),
    ekstreYapayZeka: (...a: unknown[]) => ekstreYapayZeka(...a),
    ekstreAnalizIptal: vi.fn<() => Promise<unknown>>(),
    ekstreDosyaAdresi: (id: number, sayfa?: number) =>
      `/api/v1/aile/ekstreler/${id}/dosya#page=${sayfa}`,
  },
}))

function calisma(alanlar: Partial<AiCalisma> = {}): AiCalisma {
  return {
    id: 1,
    durum: 'calisiyor',
    asama: 'okunuyor',
    etkin: true,
    hata_kodu: null,
    mesaj: null,
    kabul_satir: 0,
    reddedilen_satir: 0,
    model: 'test',
    basladi_at: null,
    bitti_at: null,
    sure_ms: null,
    ...alanlar,
  }
}

describe('useAnalizTakibi', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('bitene kadar sorar, gizli sekmede durur, kapanınca bırakır', async () => {
    const sor = vi
      .fn<() => Promise<AiCalisma | null>>()
      .mockResolvedValueOnce(calisma())
      .mockResolvedValueOnce(calisma({ durum: 'basarili', etkin: false }))
    const bitti = vi.fn<(c: AiCalisma | null) => void>()
    let izleyici: ReturnType<typeof useAnalizTakibi> | null = null
    const Bilesen = defineComponent({
      setup() {
        izleyici = useAnalizTakibi(sor, bitti, 1000)

        return () => h('div')
      },
    })
    const sayfa = mount(Bilesen)
    izleyici!.izle(calisma())

    // Sekme gizliyken sormaz; görünür olunca sürer
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => true })
    await vi.advanceTimersByTimeAsync(1000)
    expect(sor).toHaveBeenCalledTimes(1) // ilk tur zaten planlıydı
    await vi.advanceTimersByTimeAsync(3000)
    expect(sor).toHaveBeenCalledTimes(1)
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => false })
    document.dispatchEvent(new Event('visibilitychange'))
    await vi.advanceTimersByTimeAsync(1000)
    expect(sor).toHaveBeenCalledTimes(2)
    expect(bitti).toHaveBeenCalledWith(expect.objectContaining({ durum: 'basarili' }))

    // Bittikten sonra sormaz; kapanınca da
    await vi.advanceTimersByTimeAsync(5000)
    expect(sor).toHaveBeenCalledTimes(2)
    sayfa.unmount()
  })
})

describe('Yapay zekâ arayüzü', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    ekstreSatiri.mockReset()
    ekstreAnalizDurumu.mockReset()
    ekstreYapayZeka.mockReset()
  })

  const genel = { plugins: [PrimeVue, ToastService, ConfirmationService] }

  function satir(okuma: Partial<KartIslemi['okuma']>): KartIslemi {
    return {
      id: 7,
      kart_id: 1,
      ekstre_id: 9,
      sira: 1,
      durum: 'taslak',
      islem_tarihi: '2026-10-15',
      aciklama: 'MIGROS',
      tutar: 100,
      tur: 'harcama',
      taksit_no: null,
      taksit_sayisi: null,
      taksit_toplam: null,
      iade_islem_id: null,
      kategori_id: null,
      rapor_donemi: null,
      tekrar_islem_id: null,
      ham_metin: '15.10.2026 MIGROS 100,00',
      sayfa: 1,
      okuma: { alanlar: [], uyarilar: [], tutar_adaylari: [], ...okuma },
      fatura_onerisi: null,
    }
  }

  it('inceleme bekleyen satırda "Kontrol ettim" ve rozet; kontrol edilince yeşil', async () => {
    const { default: EkstreSatiri } = await import('@/components/aile/EkstreSatiri.vue')
    ekstreSatiri.mockResolvedValue({ islem: satir({ inceleme: true, incelendi: true }) })
    const sayfa = mount(EkstreSatiri, {
      props: {
        satir: satir({ inceleme: true, incelendi: false, uyarilar: ['Düşük güven (0.5).'] }),
        ekstreId: 9,
        duzenlenebilir: true,
      },
      global: genel,
    })
    expect(sayfa.find('[data-test="inceleme-gerekli"]').exists()).toBe(true)
    await sayfa.get('[data-test="kontrol-ettim"]').trigger('click')
    await flushPromises()
    expect(ekstreSatiri).toHaveBeenCalledWith(9, 7, { incelendi: true })
    expect(sayfa.emitted('degisti')).toHaveLength(1)

    await sayfa.setProps({ satir: satir({ inceleme: true, incelendi: true }) })
    expect(sayfa.find('[data-test="kontrol-ettim"]').exists()).toBe(false)
    expect(sayfa.text()).toContain('Kontrol edildi')
  })

  it('analiz paneli: onaysız başlamaz; sürerken aşamalar ve iptal', async () => {
    const { default: AiAnalizPaneli } = await import('@/components/aile/AiAnalizPaneli.vue')
    ekstreYapayZeka.mockResolvedValue({
      calisma: calisma({ asama: 'sirada', durum: 'bekliyor' }),
      message: 'Başladı',
    })
    ekstreAnalizDurumu.mockResolvedValue({ calisma: calisma() })
    const sayfa = mount(AiAnalizPaneli, {
      props: { ekstreId: 9, aiAcik: true, taslak: true, ilkCalisma: null, oncekiAnalizVar: false },
      global: genel,
    })
    const dugme = sayfa.get('[data-test="yapay-zeka"] button')
    expect(dugme.attributes('disabled')).toBeDefined()
    await sayfa.get('#ai-onay').setValue(true)
    expect(sayfa.get('[data-test="yapay-zeka"] button').attributes('disabled')).toBeUndefined()
    await sayfa.get('[data-test="yapay-zeka"] button').trigger('click')
    await flushPromises()

    expect(ekstreYapayZeka).toHaveBeenCalledWith(9)
    expect(sayfa.find('[data-test="ai-ilerleme"]').exists()).toBe(true)
    expect(sayfa.text()).toContain('Sırada')
    expect(sayfa.text()).toContain('İptal')
    expect(sayfa.emitted('surme')?.[0]).toEqual([true])
    sayfa.unmount()
  })
})
