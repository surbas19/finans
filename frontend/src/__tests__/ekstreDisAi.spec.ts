import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { AxiosError, type AxiosResponse } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import type { Ekstre } from '@/api/aile'
import AiDenetimPaneli from '@/components/aile/AiDenetimPaneli.vue'
import EkstreYukleDialog from '@/components/aile/EkstreYukleDialog.vue'
import { useAileStore } from '@/stores/aile'

const ekstreIstemi = vi.fn<() => Promise<{ istem: string; sema_surumu: string }>>()
const ekstreJsonIle = vi.fn<(...a: unknown[]) => Promise<unknown>>()
vi.mock('@/api/aile', async (asil) => ({
  ...(await asil<typeof import('@/api/aile')>()),
  aileApi: {
    ekstreIstemi: () => ekstreIstemi(),
    ekstreJsonIle: (...a: unknown[]) => ekstreJsonIle(...a),
  },
}))
const git = vi.fn<(...a: unknown[]) => Promise<void>>().mockResolvedValue()
vi.mock('vue-router', () => ({ useRouter: () => ({ push: git }) }))

const ISTEM = 'Sen deneyimli bir finans uzmanısın... {"bank_name": null}'

function dialog() {
  setActivePinia(createPinia())
  const aile = useAileStore()
  aile.hane = { yetenekler: { ai_acik: false, ekstre_max_mb: 10 } } as never
  aile.kartlar = [{ id: 7, ad: 'Bonus' }] as never
  const sayfa = mount(EkstreYukleDialog, {
    props: { visible: false, baslangicTuru: 'yapistir' },
    global: {
      plugins: [PrimeVue, ToastService],
      stubs: { UygulamaPenceresi: { template: '<div><slot /></div>' } },
    },
  })

  return sayfa
}

async function ac(sayfa: ReturnType<typeof dialog>) {
  await sayfa.setProps({ visible: true })
  await flushPromises()
}

describe('Dış yapay zekâ (JSON yapıştır) sekmesi', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    // jsdom'da yok; PrimeVue Select ekran yönünü dinler
    vi.stubGlobal('matchMedia', () => ({
      matches: false,
      addEventListener: () => {},
      removeEventListener: () => {},
    }))
    ekstreIstemi.mockResolvedValue({ istem: ISTEM, sema_surumu: 'v2' })
  })

  it('istemi kopyalar (OpenAI anahtarı kapalıyken de)', async () => {
    const yaz = vi.fn<(m: string) => Promise<void>>().mockResolvedValue()
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: yaz } })
    const sayfa = dialog()
    await ac(sayfa)

    expect(sayfa.find('[data-test="yapistir-istem"]').exists()).toBe(true)
    await sayfa.find('[data-test="istem-kopyala"]').trigger('click')
    await flushPromises()
    expect(yaz).toHaveBeenCalledWith(ISTEM)
    expect(sayfa.find('[data-test="istem-metni"]').exists()).toBe(false)
  })

  it('pano kullanılamazsa istemi seçilebilir kutuda gösterir', async () => {
    Object.defineProperty(navigator, 'clipboard', {
      configurable: true,
      value: { writeText: vi.fn<() => Promise<void>>().mockRejectedValue(new Error('izin yok')) },
    })
    const sayfa = dialog()
    await ac(sayfa)
    await sayfa.find('[data-test="istem-kopyala"]').trigger('click')
    await flushPromises()

    const kutu = sayfa.find('[data-test="istem-metni"]')
    expect(kutu.exists()).toBe(true)
    expect((kutu.element as HTMLTextAreaElement).value).toBe(ISTEM)
  })

  it('panodan yapıştırır; pano okunamazsa kutuya elle yazılır', async () => {
    Object.defineProperty(navigator, 'clipboard', {
      configurable: true,
      value: {
        readText: vi
          .fn<() => Promise<string>>()
          .mockResolvedValue('{"statement_type":"credit_card"}'),
      },
    })
    const sayfa = dialog()
    await ac(sayfa)
    await sayfa.find('[data-test="panodan-yapistir"]').trigger('click')
    await flushPromises()
    expect((sayfa.find('[data-test="json-kutusu"]').element as HTMLTextAreaElement).value).toBe(
      '{"statement_type":"credit_card"}',
    )

    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: undefined })
    await sayfa.find('[data-test="json-kutusu"]').setValue('el ile')
    await sayfa.find('[data-test="panodan-yapistir"]').trigger('click')
    await flushPromises()
    expect((sayfa.find('[data-test="json-kutusu"]').element as HTMLTextAreaElement).value).toBe(
      'el ile',
    )
  })

  it('kart ve JSON olmadan gönderilemez; hata listesini satır satır gösterir', async () => {
    const sayfa = dialog()
    await ac(sayfa)
    const gonder = sayfa.find('[data-test="yukle-gonder"]')
    // Tek kart otomatik seçilir; JSON boş
    expect(gonder.attributes('disabled')).toBeDefined()

    await sayfa.find('[data-test="json-kutusu"]').setValue('{"bozuk"')
    expect(gonder.attributes('disabled')).toBeUndefined()
    ekstreJsonIle.mockRejectedValueOnce(
      new AxiosError('422', '422', undefined, undefined, {
        status: 422,
        data: {
          message: 'x',
          errors: {
            json: [
              'transactions.3.amount: sayı olmalı',
              'yorum: fazla anahtar (şemada yok, silin)',
            ],
          },
        },
      } as AxiosResponse),
    )
    await sayfa.find('form').trigger('submit')
    await flushPromises()

    const liste = sayfa.find('[data-test="json-hatalari"]')
    expect(liste.exists()).toBe(true)
    expect(liste.findAll('li').map((l) => l.text())).toEqual([
      'transactions.3.amount: sayı olmalı',
      'yorum: fazla anahtar (şemada yok, silin)',
    ])
    expect(git).not.toHaveBeenCalled()
  })

  it('başarıda ekstre inceleme sayfasına gider; PDF eklenmemişse göndermez', async () => {
    ekstreJsonIle.mockResolvedValueOnce({
      ekstre: { id: 42 },
      ozet: { satir: 75, inceleme: 0, reddedilen: 0 },
      uyarilar: [],
      message: 'JSON okundu: 75 satır incelemeye hazır.',
    })
    const sayfa = dialog()
    await ac(sayfa)
    await sayfa.find('[data-test="json-kutusu"]').setValue('{"ok":true}')
    await sayfa.find('form').trigger('submit')
    await flushPromises()

    expect(ekstreJsonIle).toHaveBeenCalledWith(7, '{"ok":true}', null)
    expect(git).toHaveBeenCalledWith({ name: 'aile-ekstre', params: { id: 42 } })
  })
})

describe('Harici JSON rozeti', () => {
  it("denetim panelinde rozet ve PDF'siz uyarısı görünür, PDF okuyucu karşılaştırması gizlenir", () => {
    const ekstre = {
      id: 1,
      denetim: {
        ai: {
          model: 'harici',
          harici: true,
          metinsiz: true,
          banka: 'Garanti BBVA',
          inceleme_gerekli: false,
          uyarilar: [],
          bonus_hareketi: 0,
          reddedilen: [],
          sayfa_denetimi: { sayfalar: [], pdf_sayfa: 4, analiz_edilen_sayfa: 4 },
        },
        capraz: {
          durum: 'php_yok',
          php: {},
          ai: {},
          farklar: [],
          secilen: 'ai',
          kesim_farkli: false,
        },
      },
    } as unknown as Ekstre
    const sayfa = mount(AiDenetimPaneli, {
      props: { ekstre },
      global: { plugins: [PrimeVue, ToastService] },
    })

    expect(sayfa.find('[data-test="harici-rozet"]').text()).toContain('Harici JSON ile okundu')
    expect(sayfa.find('[data-test="harici-rozet"]').text()).toContain("PDF'siz")
    expect(sayfa.find('[data-test="capraz"]').exists()).toBe(false)
  })
})
