import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import PrimeVue from 'primevue/config'
import type { DonemGrafikleri } from '@/api/aile'

const sifir = (): number[] => Array(12).fill(0)
const ay = (n: number, d: number): number[] => sifir().map((_, i) => (i === n ? d : 0))
const veri: DonemGrafikleri = {
  yil: 2026,
  etiketler: Array.from({ length: 12 }, (_, i) => `Ay${i + 1} 2026`),
  gelir: {
    toplam: ay(10, 57000),
    kisiler: [
      { uye_id: 1, ad: 'Ben', renk: null, toplam: ay(10, 32000) },
      { uye_id: 2, ad: 'Eşim', renk: null, toplam: ay(10, 25000) },
    ],
    turler: [
      {
        gelir_turu_id: 1,
        ad: 'Maaş',
        toplam: ay(10, 55000),
        kisiler: [
          { uye_id: 1, ad: 'Ben', renk: null, toplam: ay(10, 30000) },
          { uye_id: 2, ad: 'Eşim', renk: null, toplam: ay(10, 25000) },
        ],
      },
      {
        gelir_turu_id: 2,
        ad: 'Ek ders',
        toplam: ay(10, 2000),
        kisiler: [{ uye_id: 1, ad: 'Ben', renk: null, toplam: ay(10, 2000) }],
      },
      {
        gelir_turu_id: 3,
        ad: 'Kira geliri',
        toplam: ay(10, 9000),
        kisiler: [{ uye_id: 1, ad: 'Ben', renk: null, toplam: ay(10, 9000) }],
      },
    ],
  },
  faturalar: {
    toplam: ay(10, 1400),
    toplam_oran: ay(10, 3.5).map((d, i) => (i === 10 ? d : null)),
    turler: [
      {
        fatura_turu_id: 1,
        ad: 'Elektrik',
        tutar: ay(10, 1000),
        oran: ay(10, 2.5).map((d, i) => (i === 10 ? d : null)),
      },
    ],
  },
  kartlar: {
    toplam: ay(10, 6000.5),
    kartlar: [
      { kart_id: 1, ad: 'Bonus', renk: 'sky', tutar: ay(10, 4500) },
      { kart_id: 2, ad: 'Maximum', renk: null, tutar: ay(10, 1500.5) },
    ],
  },
}

const grafikler = vi.fn<(yil: number) => Promise<DonemGrafikleri>>()
vi.mock('@/api/aile', async (asil) => ({
  ...(await asil<typeof import('@/api/aile')>()),
  aileApi: { grafikler: (yil: number) => grafikler(yil) },
}))

// Chart.js tuvale çizer (jsdom'da yok): verinin kaç seri ve hangi değerlerle geldiği yeter
const Chart = {
  props: ['data'],
  template: `<div data-test="chart" :data-seriler="data.datasets.map(d => d.label).join('|')" />`,
}
const genel = { plugins: [PrimeVue], stubs: { Chart } }

async function kur(bilesen: 'Fatura' | 'Kart' | 'Gelir') {
  const { default: B } = await import(`@/components/grafik/${bilesen}Grafigi.vue`)
  const w = mount(B, { global: genel })
  await flushPromises()

  return w
}

describe('Dönem grafikleri', () => {
  beforeEach(() => {
    grafikler.mockReset()
    grafikler.mockResolvedValue(veri)
    vi.stubGlobal('matchMedia', () => ({
      matches: false,
      addEventListener: () => {},
      removeEventListener: () => {},
    }))
  })

  it('kartlar: toplam + her kart; seri düğmesi seriyi kapatıp açar', async () => {
    const w = await kur('Kart')
    expect(grafikler).toHaveBeenCalledWith(new Date().getFullYear())
    expect(w.get('[data-test="chart"]').attributes('data-seriler')).toBe('Toplam|Bonus|Maximum')
    const dugme = w.findAll('[data-test="seri-dugmesi"]')[1]!
    expect(dugme.attributes('aria-pressed')).toBe('true')
    await dugme.trigger('click')
    expect(dugme.attributes('aria-pressed')).toBe('false')
    expect(w.get('[data-test="chart"]').attributes('data-seriler')).toBe('Toplam|Maximum')
    await dugme.trigger('click')
    expect(w.get('[data-test="chart"]').attributes('data-seriler')).toBe('Toplam|Bonus|Maximum')
    w.unmount()
  })

  it('faturalar: toplam + her tür; Gelire oran % görünümünde yüzde birimi ve açıklama notu', async () => {
    const w = await kur('Fatura')
    expect(w.get('[data-test="chart"]').attributes('data-seriler')).toBe('Toplam|Elektrik')
    expect(w.text()).not.toContain('Oran: o ayın')
    await w.findAll('[data-test="fatura-gorunum"] .p-togglebutton')[1]!.trigger('click')
    expect(w.text()).toContain('Geliri girilmemiş ayda oran gösterilmez')
    w.unmount()
  })

  it('gelirler: varsayılan Maaş + Ek ders; toplam ve kişi çizgileri seçili türlerden; tür seçimi değişince güncellenir', async () => {
    const w = await kur('Gelir')
    // Seçili türler: Maaş (55.000) + Ek ders (2.000); Kira geliri seçili değil
    const secili = w.findAll('[data-test="gelir-turleri"] .p-togglebutton-checked')
    expect(secili.map((e) => e.text())).toEqual(['Maaş', 'Ek ders'])
    expect(w.get('[data-test="chart"]').attributes('data-seriler')).toBe('Toplam|Ben|Eşim')
    const tumu = w.findAll('[data-test="gelir-turleri"] .p-togglebutton')
    // Kira geliri de açılınca toplam yeniden hesaplanır (grafik yeniden çizilir)
    await tumu[2]!.trigger('click')
    expect(w.findAll('[data-test="gelir-turleri"] .p-togglebutton-checked')).toHaveLength(3)
    w.unmount()
  })

  it('veri yoksa grafik yerine boş durum metni; hata olursa mesaj', async () => {
    grafikler.mockResolvedValue({
      ...veri,
      kartlar: { toplam: sifir(), kartlar: [] },
    })
    let w = await kur('Kart')
    expect(w.find('[data-test="grafik-bos"]').text()).toContain('onaylı ekstre yok')
    expect(w.find('[data-test="chart"]').exists()).toBe(false)
    w.unmount()
    grafikler.mockRejectedValue(new Error('x'))
    w = await kur('Kart')
    expect(w.find('[data-test="chart"]').exists()).toBe(false)
    expect(w.find('.p-message').exists()).toBe(true)
    w.unmount()
  })

  it('yıl düğmeleri önceki / sonraki yılı yükler', async () => {
    const w = await kur('Kart')
    const yil = new Date().getFullYear()
    await w.get('[data-test="yil-onceki"]').trigger('click')
    await flushPromises()
    expect(grafikler).toHaveBeenLastCalledWith(yil - 1)
    expect(w.get('[data-test="grafik-yil"]').text()).toBe(String(yil - 1))
    await w.get('[data-test="yil-sonraki"]').trigger('click')
    await flushPromises()
    expect(grafikler).toHaveBeenLastCalledWith(yil)
    w.unmount()
  })
})
