import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import PrimeVue from 'primevue/config'
import ConfirmationService from 'primevue/confirmationservice'
import Dialog from 'primevue/dialog'
import ToastService from 'primevue/toastservice'
import type { Islem } from '@/api/finans'

/** Sahte matchMedia: `dar` başlangıç değeri; `degistir` ile ekran döndürme taklidi */
function matchMediaKur(dar: boolean) {
  const dinleyiciler: ((o: { matches: boolean }) => void)[] = []
  const eslesme = {
    matches: dar,
    addEventListener: (_: string, f: (o: { matches: boolean }) => void) => dinleyiciler.push(f),
  }
  vi.stubGlobal('matchMedia', () => eslesme)
  return {
    degistir(yeni: boolean) {
      eslesme.matches = yeni
      dinleyiciler.forEach((f) => f({ matches: yeni }))
    },
  }
}

beforeEach(() => {
  // useDarEkran modül düzeyinde tek dinleyici tutar; her test temiz modülle başlar
  vi.resetModules()
})
afterEach(() => {
  vi.unstubAllGlobals()
})

describe('useDarEkran', () => {
  it('telefon genişliğini okur ve döndürünce güncellenir', async () => {
    const ekran = matchMediaKur(true)
    const { useDarEkran } = await import('@/composables/useDarEkran')
    const dar = useDarEkran()
    expect(dar.value).toBe(true)
    ekran.degistir(false)
    expect(dar.value).toBe(false)
    // Aynı değer paylaşılır
    expect(useDarEkran().value).toBe(false)
  })
})

describe('UygulamaPenceresi', () => {
  async function penceresiniKur(dar: boolean) {
    matchMediaKur(dar)
    const { default: UygulamaPenceresi } = await import('@/components/UygulamaPenceresi.vue')
    return mount(UygulamaPenceresi, {
      props: { visible: false, baslik: 'Deneme', genislik: 30 },
      global: { plugins: [PrimeVue] },
    }).findComponent(Dialog)
  }

  it('telefonda alttan açılır, boşluğa dokununca kapanır, tam genişlik', async () => {
    const dialog = await penceresiniKur(true)
    expect(dialog.props('position')).toBe('bottom')
    expect(dialog.props('dismissableMask')).toBe(true)
    expect(dialog.vm.$attrs.style).toEqual({ width: '100%' })
  })

  it('masaüstünde ortada, verilen genişlikte', async () => {
    const dialog = await penceresiniKur(false)
    expect(dialog.props('position')).toBe('center')
    expect(dialog.props('dismissableMask')).toBe(false)
    expect(dialog.vm.$attrs.style).toEqual({ width: 'min(30rem, calc(100vw - 2rem))' })
  })
})

describe('IslemListesi', () => {
  it('telefon kartına dokununca düzenleme istenir', async () => {
    matchMediaKur(true)
    const { default: IslemListesi } = await import('@/components/IslemListesi.vue')
    const islem: Islem = {
      id: 7,
      hesap_id: 1,
      varlik_id: 2,
      tur: 'alis',
      miktar: 10,
      birim_fiyat: 100,
      komisyon: 0,
      tutar: 1000,
      tarih: '2026-11-01',
      not: null,
      varlik: {
        id: 2,
        tur: 'altin',
        kod: 'GRAM',
        ad: 'Gram altın',
        birim: 'gram',
        para_birimi: 'TRY',
        kaynak: 'elle',
        gecikmeli: false,
        fiyat: null,
      },
      hesap_ad: 'Kasa',
    }
    const w = mount(IslemListesi, {
      props: { islemler: [islem], hesapGoster: true },
      global: { plugins: [PrimeVue, ConfirmationService, ToastService] },
    })
    const kart = w.get('[data-test="islem-karti"]')
    expect(kart.text()).toContain('Gram altın')
    expect(kart.text()).toContain('Kasa')
    await kart.trigger('click')
    expect(w.emitted('duzenle')?.[0]).toEqual([islem])
  })
})
