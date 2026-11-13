import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import PrimeVue from 'primevue/config'
import type { PortfoySlayti } from '@/components/PortfoyKaydirici.vue'

/** useDarEkran modül düzeyinde tek dinleyici tutar: her test temiz modül ve kendi ekran genişliğiyle başlar */
function ekran(dar: boolean): void {
  vi.stubGlobal('matchMedia', () => ({ matches: dar, addEventListener: () => {} }))
}

const scrollTo = vi.fn<(secenek?: ScrollToOptions) => void>()
beforeEach(() => {
  vi.resetModules()
  scrollTo.mockClear()
  Element.prototype.scrollTo = scrollTo as unknown as typeof Element.prototype.scrollTo
})
afterEach(() => {
  vi.unstubAllGlobals()
})

function slayt(id: number | null, ad: string): PortfoySlayti {
  return {
    id,
    ad,
    altBaslik: '2 varlık',
    deger: 1000,
    kz: 100,
    kz_yuzde: 10,
    gunluk_degisim: 5,
    dilimler: [{ etiket: 'GRAM', deger: 1000, renk: '--p-sky-500' }],
    merkez: { ust: 'Payı', alt: '%50' },
  }
}
const SLAYTLAR = [slayt(null, 'Tüm hesaplar'), slayt(5, 'Yatırım'), slayt(9, 'Kasa')]

async function kur(dar: boolean, secilen: number | null = null) {
  ekran(dar)
  const { default: PortfoyKaydirici } = await import('@/components/PortfoyKaydirici.vue')

  return mount(PortfoyKaydirici, {
    props: {
      slaytlar: SLAYTLAR,
      secilen,
      'onUpdate:secilen': () => {},
    },
    // Grafik (chart.js / canvas) jsdom'da çizilmez
    global: { plugins: [PrimeVue], stubs: { VeriGrafigi: true } },
  })
}

describe('PortfoyKaydirici', () => {
  it('slaytlar sırayla, seçilen işaretli; masaüstünde tıklanan seçilir, nokta yok', async () => {
    const w = await kur(false)
    const slaytlar = w.findAll('[data-test="kaydirici-slayt"]')

    expect(slaytlar.map((s) => s.attributes('aria-label'))).toEqual([
      '1 / 3: Tüm hesaplar',
      '2 / 3: Yatırım',
      '3 / 3: Kasa',
    ])
    expect(slaytlar[0]!.attributes('aria-current')).toBe('true')
    expect(slaytlar[1]!.attributes('aria-current')).toBeUndefined()
    expect(w.findAll('[data-test="kaydirici-nokta"]')).toHaveLength(0)

    await slaytlar[1]!.trigger('click')
    expect(w.emitted('update:secilen')?.[0]).toEqual([5])
  })

  it('telefonda noktalar; noktaya dokununca seçilir ve şerit o slayta kayar', async () => {
    const w = await kur(true, 5)
    const noktalar = w.findAll('[data-test="kaydirici-nokta"]')

    expect(noktalar.map((n) => n.attributes('aria-label'))).toEqual([
      'Tüm hesaplar',
      'Yatırım',
      'Kasa',
    ])
    expect(noktalar[1]!.attributes('aria-current')).toBe('true')

    scrollTo.mockClear()
    await noktalar[2]!.trigger('click')
    expect(w.emitted('update:secilen')?.[0]).toEqual([9])
    await w.setProps({ secilen: 9 })
    expect(scrollTo).toHaveBeenCalled()
    expect(w.findAll('[data-test="kaydirici-slayt"]')[2]!.attributes('aria-current')).toBe('true')
  })
})
