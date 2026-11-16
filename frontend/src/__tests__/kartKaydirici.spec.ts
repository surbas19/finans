import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ConfirmationService from 'primevue/confirmationservice'
import ToastService from 'primevue/toastservice'
import type { Islem } from '@/api/finans'

function telefonKur(dar: boolean): void {
  vi.stubGlobal('matchMedia', () => ({
    matches: dar,
    addEventListener: () => {},
    removeEventListener: () => {},
  }))
  // jsdom'da yok; kaydırıcı şeridi kaydırır
  Element.prototype.scrollTo = vi.fn<() => void>() as unknown as typeof Element.prototype.scrollTo
}

describe('kartRengi', () => {
  it('seçili rengi kullanır, yoksa kimlikten sabit renk türetir, geçersizi yok sayar', async () => {
    const { kartRengi, kartRengiCss, KART_RENKLERI, sonrakiKartRengi } =
      await import('@/utils/kartRengi')
    expect(kartRengi({ id: 7, renk: 'violet' })).toBe('violet')
    expect(kartRengi({ id: 3, renk: null })).toBe(KART_RENKLERI[3])
    expect(kartRengi({ id: 3, renk: '#ff0000' })).toBe(KART_RENKLERI[3])
    expect(kartRengi({ id: 3, renk: 'olmayan-renk' })).toBe(KART_RENKLERI[3])
    expect(kartRengi(null)).toBe('slate')
    // Aynı kart her seferinde aynı renk
    expect(kartRengi({ id: 15 })).toBe(kartRengi({ id: 15 }))
    expect(kartRengiCss({ id: 1, renk: 'teal' })).toBe('var(--p-teal-500)')
    // Yeni kart için kullanılmayan ilk renk
    expect(sonrakiKartRengi(['teal', 'sky', null])).toBe('blue')
    expect(sonrakiKartRengi([])).toBe('teal')
  })
})

describe('RenkSecici', () => {
  it('rengi seçince v-model güncellenir ve seçili düğme işaretlenir', async () => {
    const { default: RenkSecici } = await import('@/components/aile/RenkSecici.vue')
    const sayfa = mount(RenkSecici, {
      props: { modelValue: 'sky', renkler: ['teal', 'sky', 'rose'] },
    })
    const dugmeler = sayfa.findAll('button')
    expect(dugmeler).toHaveLength(3)
    expect(dugmeler[1]!.attributes('aria-pressed')).toBe('true')
    await dugmeler[2]!.trigger('click')
    expect(sayfa.emitted('update:modelValue')?.[0]).toEqual(['rose'])
  })
})

describe('KaydirmaliPaneller', () => {
  beforeEach(() => {
    vi.resetModules()
  })

  it('telefonda noktalar çıkar, noktaya dokununca sıra değişir', async () => {
    telefonKur(true)
    const { default: Paneller } = await import('@/components/KaydirmaliPaneller.vue')
    const sayfa = mount(Paneller, {
      props: { ogeler: ['Gelir', 'Gider', 'Kart'], sira: 0 },
      slots: { panel: (p: { oge: unknown }) => h('span', { class: 'icerik' }, String(p.oge)) },
    })
    expect(sayfa.findAll('[data-test="kaydirici-panel"]')).toHaveLength(3)
    expect(sayfa.findAll('.icerik').map((x) => x.text())).toEqual(['Gelir', 'Gider', 'Kart'])
    const noktalar = sayfa.findAll('[data-test="kaydirici-nokta"]')
    expect(noktalar).toHaveLength(3)
    expect(noktalar[0]!.attributes('aria-selected')).toBe('true')
    await noktalar[2]!.trigger('click')
    expect(sayfa.emitted('update:sira')?.[0]).toEqual([2])
  })

  it('geniş ekranda nokta yok; tek panelde de nokta çıkmaz', async () => {
    telefonKur(false)
    const { default: Paneller } = await import('@/components/KaydirmaliPaneller.vue')
    const genis = mount(Paneller, {
      props: { ogeler: ['a', 'b'], sira: 0 },
      slots: { panel: (p: { oge: unknown }) => h('span', String(p.oge)) },
    })
    expect(genis.findAll('[data-test="kaydirici-nokta"]')).toHaveLength(0)
    genis.unmount()
    vi.resetModules()
    telefonKur(true)
    const { default: Tek } = await import('@/components/KaydirmaliPaneller.vue')
    const tek = mount(Tek, {
      props: { ogeler: ['a'], sira: 0 },
      slots: { panel: (p: { oge: unknown }) => h('span', String(p.oge)) },
    })
    expect(tek.findAll('[data-test="kaydirici-nokta"]')).toHaveLength(0)
  })
})

describe('KartEtiketi', () => {
  it('kartın rengiyle nokta ve adı gösterir', async () => {
    setActivePinia(createPinia())
    const { useAileStore } = await import('@/stores/aile')
    const aile = useAileStore()
    aile.kartlar = [
      {
        id: 4,
        ad: 'Bonus',
        banka_id: 1,
        sahip_uye_id: 1,
        kesim_gunu: null,
        son_odeme_gunu: null,
        asgari_oran: 40,
        renk: 'rose',
        aktif: true,
      },
    ]
    const { default: KartEtiketi } = await import('@/components/aile/KartEtiketi.vue')
    const sayfa = mount(KartEtiketi, { props: { kartId: 4 } })
    expect(sayfa.text()).toBe('Bonus')
    expect(sayfa.find('span[aria-hidden="true"]').attributes('style')).toContain('--p-rose-500')
    // Store'da olmayan kart yine renkli (kimlikten) ve verilen adı yazar
    const yok = mount(KartEtiketi, { props: { kartId: 99, ad: 'Eski kart' } })
    expect(yok.text()).toBe('Eski kart')
    expect(yok.find('span[aria-hidden="true"]').attributes('style')).toContain('var(--p-')
  })
})

describe('IslemListesi zekât satırları', () => {
  const islem = (id: number, tarih: string): Islem =>
    ({
      id,
      hesap_id: 1,
      varlik_id: 1,
      tur: 'alis',
      miktar: 1,
      birim_fiyat: 100,
      komisyon: 0,
      tutar: 100,
      kar_yuzde: null,
      tarih,
      not: null,
      varlik: { id: 1, tur: 'hisse', kod: 'THYAO', ad: 'Türk Hava Yolları', birim: 'adet' },
      hesap_ad: 'Yatırım',
    }) as unknown as Islem
  it("işlem listesi yalnız işlemleri gösterir; zekât ödemeleri Yönetim → Zekât'ta", async () => {
    telefonKur(true)
    const { default: IslemListesi } = await import('@/components/IslemListesi.vue')
    const sayfa = mount(IslemListesi, {
      props: { islemler: [islem(1, '2026-11-10')] },
      global: { plugins: [PrimeVue, ConfirmationService, ToastService] },
    })
    expect(sayfa.findAll('li')).toHaveLength(1)
    expect(sayfa.find('[data-test="zekat-satiri"]').exists()).toBe(false)
  })
})

describe('GelirGiderView', () => {
  it('iki panel: gelir yeşil, gider kırmızı çizgiyle; ?panel=gider ile gider açık başlar', async () => {
    telefonKur(true)
    vi.doMock('vue-router', () => ({
      useRoute: () => ({ query: { panel: 'gider' } }),
      useRouter: () => ({ replace: vi.fn<() => void>() }),
    }))
    const { default: GelirGiderView } = await import('@/views/aile/GelirGiderView.vue')
    const sayfa = mount(GelirGiderView, {
      global: {
        plugins: [PrimeVue],
        stubs: { GelirlerView: true, GiderlerView: true, AySecici: true },
      },
    })
    await flushPromises()
    expect(sayfa.find('[data-test="panel-cizgi-gelir"]').attributes('style')).toContain(
      '--p-emerald-500',
    )
    expect(sayfa.find('[data-test="panel-cizgi-gider"]').attributes('style')).toContain(
      '--p-red-500',
    )
    // Ekranda "Gelir" / "Gider" yazısı yok; çizgi dikey ve sağ kenarda
    expect(sayfa.text()).not.toMatch(/Gelir|Gider/)
    expect(sayfa.find('[data-test="panel-cizgi-gelir"]').classes().join(' ')).toContain('right-0')
    expect(sayfa.find('[data-test="panel-cizgi-gelir"]').classes()).toContain('w-[3px]')
    expect(sayfa.find('[data-test="kaydirici-nokta"]').exists()).toBe(false)
    expect(sayfa.find('[data-test="panel-gelir"]').exists()).toBe(false)
    // Ay çarkı en üstte, sınırsız (ileri aylar da seçilir)
    expect(sayfa.findComponent({ name: 'AySecici' }).props('ileriSinir')).toBeNull()
    vi.doUnmock('vue-router')
  })
})
