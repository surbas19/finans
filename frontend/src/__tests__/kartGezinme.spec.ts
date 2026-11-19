import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ConfirmationService from 'primevue/confirmationservice'
import ToastService from 'primevue/toastservice'
import type { Kart } from '@/api/aile'
import { gecisYonu } from '@/composables/useSayfaYonu'
import { useYanaKaydirma } from '@/composables/useYanaKaydirma'
import KartDetayView from '@/views/aile/KartDetayView.vue'

function kart(id: number, banka: number, ad: string, aktif = true): Kart {
  return { id, banka_id: banka, sahip_uye_id: 1, ad, renk: null, aktif } as unknown as Kart
}
// API sırası karışık: sayfa banka, sonra kimlik sırasına dizer (Kartlar sayfasıyla aynı)
const KARTLAR = [kart(3, 2, 'World'), kart(1, 1, 'Bonus'), kart(2, 1, 'Maximum', false)]

let kartListesi = KARTLAR
const degistir = vi.fn<(hedef: unknown) => void>()
vi.mock('vue-router', () => ({
  useRouter: () => ({ replace: (h: unknown) => degistir(h), push: vi.fn<() => void>() }),
}))
vi.mock('@/api/aile', async (asil) => ({
  ...(await asil<typeof import('@/api/aile')>()),
  aileApi: {
    hane: () => Promise.resolve({ hane: { id: 1, ad: 'Aile' }, uyeler: [], ben_uye_id: 1 }),
    bankalar: () => Promise.resolve([]),
    kartlar: () => Promise.resolve(kartListesi),
    kategoriler: () => Promise.resolve([]),
    faturaTurleri: () => Promise.resolve([]),
    saglayicilar: () => Promise.resolve([]),
    gelirTurleri: () => Promise.resolve([]),
    kart: (id: number) =>
      Promise.resolve({ kart: KARTLAR.find((k) => k.id === id), ekstreler: [] }),
    kartIslemleri: () => Promise.resolve({ islemler: [] }),
    faturalar: () => Promise.resolve({ faturalar: [], toplam: 0 }),
  },
}))

/** jsdom'da PointerEvent yok: bileşenin kullandığı alanlarla düz nesne */
const isaret = (x: number, y: number, pointerType = 'touch') =>
  ({ clientX: x, clientY: y, pointerId: 1, pointerType }) as PointerEvent

describe('Kart detayı: kartlar arası gezinme', () => {
  beforeEach(() => {
    degistir.mockReset()
    kartListesi = KARTLAR
    setActivePinia(createPinia())
    vi.stubGlobal('matchMedia', () => ({
      matches: false,
      addEventListener: () => {},
      removeEventListener: () => {},
    }))
  })

  async function kur(id: number) {
    const w = mount(KartDetayView, {
      props: { id },
      global: {
        plugins: [PrimeVue, ToastService, ConfirmationService],
        stubs: {
          RouterLink: { template: '<a><slot /></a>' },
          ElleEkstreDialog: true,
          EkstreYukleDialog: true,
          KartFormu: true,
          KartIslemFormu: true,
          KartIslemListesi: true,
          KisiEtiketi: true,
        },
      },
    })
    await flushPromises()

    return w
  }

  it('şerit Kartlar sırasıyla, seçili kart işaretli; çipe dokununca yönüyle o karta geçer', async () => {
    const w = await kur(2)
    const cipler = w.findAll('[data-test="kart-cipi"]')
    expect(cipler.map((c) => c.text())).toEqual(['Bonus', 'Maximum', 'World'])
    expect(cipler[1]!.attributes('aria-current')).toBe('page')
    await cipler[2]!.trigger('click')
    expect(degistir).toHaveBeenCalledWith({
      name: 'aile-kart',
      params: { id: 3 },
      state: { sayfaYonu: 1 },
    })
    await cipler[0]!.trigger('click')
    expect(degistir).toHaveBeenLastCalledWith({
      name: 'aile-kart',
      params: { id: 1 },
      state: { sayfaYonu: -1 },
    })
    // Seçili çip bir şey yapmaz
    degistir.mockReset()
    await cipler[1]!.trigger('click')
    expect(degistir).not.toHaveBeenCalled()
    w.unmount()
  })

  it('tek kart varsa şerit görünmez', async () => {
    kartListesi = [KARTLAR[1]!]
    const w = await kur(1)
    expect(w.find('[data-test="kart-seridi"]').exists()).toBe(false)
    w.unmount()
  })

  it('yana kaydırma: sola sonraki, sağa önceki; kısa / dikey / fare hareketi sayılmaz', () => {
    const sola = vi.fn<() => void>()
    const saga = vi.fn<() => void>()
    const k = useYanaKaydirma({ sola, saga })
    k.onPointerdown(isaret(200, 100))
    k.onPointerup(isaret(100, 110))
    expect(sola).toHaveBeenCalledTimes(1)
    k.onPointerdown(isaret(100, 100))
    k.onPointerup(isaret(200, 100))
    expect(saga).toHaveBeenCalledTimes(1)
    // Kısa
    k.onPointerdown(isaret(100, 100))
    k.onPointerup(isaret(140, 100))
    // Daha çok dikey (sayfa kaydırması)
    k.onPointerdown(isaret(100, 100))
    k.onPointerup(isaret(180, 200))
    // Fare sürüklemesi
    k.onPointerdown(isaret(200, 100, 'mouse'))
    k.onPointerup(isaret(50, 100, 'mouse'))
    // İptal edilen dokunuş
    k.onPointerdown(isaret(200, 100))
    k.onPointercancel()
    k.onPointerup(isaret(50, 100))
    expect(sola).toHaveBeenCalledTimes(1)
    expect(saga).toHaveBeenCalledTimes(1)
  })

  it('başlık kartında kaydırınca komşu karta geçer; uçta bir şey olmaz', async () => {
    const w = await kur(1)
    const baslik = w.get('[data-test="kart-baslik"]').element
    // jsdom'da PointerEvent yok: düz olaya bileşenin okuduğu alanlar eklenir
    const gonder = (tur: string, x: number, y: number) =>
      baslik.dispatchEvent(
        Object.assign(new Event(tur), {
          clientX: x,
          clientY: y,
          pointerId: 1,
          pointerType: 'touch',
        }),
      )
    const bas = (e: PointerEvent) => gonder('pointerdown', e.clientX, e.clientY)
    const birak = (e: PointerEvent) => gonder('pointerup', e.clientX, e.clientY)
    // Sağa kaydır (önceki): ilk kartta önceki yok
    bas(isaret(100, 100))
    birak(isaret(220, 100))
    expect(degistir).not.toHaveBeenCalled()
    // Sola kaydır: sonraki (Maximum)
    bas(isaret(220, 100))
    birak(isaret(100, 100))
    expect(degistir).toHaveBeenCalledWith({
      name: 'aile-kart',
      params: { id: 2 },
      state: { sayfaYonu: 1 },
    })
    w.unmount()
  })

  it('geçiş yönü: aynı sayfada history.state.sayfaYonu, farklı sayfada sayfa sırası', () => {
    expect(gecisYonu('aile-kart', 'aile-kart', 'aile-kartlar', { sayfaYonu: 1 })).toBe(1)
    expect(gecisYonu('aile-kart', 'aile-kart', 'aile-kartlar', { sayfaYonu: -1 })).toBe(-1)
    expect(gecisYonu('aile-kart', 'aile-kart', 'aile-kartlar', {})).toBe(0)
    expect(gecisYonu('aile-kart', 'aile-kart', 'aile-kartlar', null)).toBe(0)
    expect(gecisYonu('aile-kartlar', 'aile-kart', 'aile-kartlar', null)).toBe(1)
    expect(gecisYonu('aile-kart', 'aile-kartlar', undefined, null)).toBe(-1)
  })
})
