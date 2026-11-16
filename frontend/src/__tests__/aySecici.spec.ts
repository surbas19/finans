import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import AySecici from '@/components/aile/AySecici.vue'

function kur(props: Record<string, unknown> = {}) {
  return mount(AySecici, {
    props: { modelValue: '2026-10', ileriSinir: '2026-11', ...props },
    slots: { ek: '<button type="button" data-test="ek">Tümü</button>' },
  })
}

const yayilan = (w: ReturnType<typeof kur>): string[] =>
  (w.emitted('update:modelValue') ?? []).map((a) => a[0] as string)

describe('AySecici: dikey ay çarkı', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('seçili ay ortada; liste sınırdan sonrasını içermez; ek yuva görünür', () => {
    const w = kur()
    expect(w.get('[data-test="ay-secici"]').text()).toBe('Ekim 2026')
    const aylar = w.findAll('[role="option"]').map((o) => o.text())
    expect(aylar).toContain('Eylül 2026')
    expect(aylar[aylar.length - 1]).toBe('Kasım 2026')
    expect(aylar).not.toContain('Aralık 2026')
    expect(w.get('[aria-selected="true"]').text()).toBe('Ekim 2026')
    expect(w.find('[data-test="ek"]').exists()).toBe(true)
    expect(w.find('[data-test="ay-secici-bos"]').exists()).toBe(false)
  })

  it('önceki / sonraki ay düğmeleri; sınırda sonraki kapalı', async () => {
    const w = kur()
    await w.get('button[aria-label="Önceki ay"]').trigger('click')
    await w.get('button[aria-label="Sonraki ay"]').trigger('click')
    expect(yayilan(w)).toEqual(['2026-09', '2026-11'])
    await w.setProps({ modelValue: '2026-11' })
    expect(w.get('button[aria-label="Sonraki ay"]').attributes('disabled')).toBeDefined()
  })

  it('soluk aya dokununca o ay seçilir; seçili aya dokunmak bir şey yaymaz', async () => {
    const w = kur()
    const satir = (metin: string) => w.findAll('[role="option"]').find((o) => o.text() === metin)!
    await satir('Ekim 2026').trigger('click')
    expect(yayilan(w)).toEqual([])
    await satir('Mart 2026').trigger('click')
    expect(yayilan(w)).toEqual(['2026-03'])
  })

  it('klavye: Yukarı önceki, Aşağı sonraki ay', async () => {
    const w = kur()
    const liste = w.get('[role="listbox"]')
    await liste.trigger('keydown', { key: 'ArrowUp' })
    await liste.trigger('keydown', { key: 'ArrowDown' })
    expect(yayilan(w)).toEqual(['2026-09', '2026-11'])
  })

  it('fare tekerleği: bir tıkta tek ay; kilit süresince ikinci tık yok sayılır; sınırda ileri gidilmez', async () => {
    const w = kur({ ileriSinir: '2026-12' })
    const liste = w.get('[role="listbox"]')
    const tekerle = (deltaY: number, deltaMode = 0) =>
      liste.element.dispatchEvent(
        new WheelEvent('wheel', { deltaY, deltaMode, bubbles: true, cancelable: true }),
      )
    // Bir tıkta (100 px) tam bir ay ileri
    tekerle(100)
    expect(yayilan(w)).toEqual(['2026-11'])
    // Kilit süresi içindeki ikinci tık yeni ay atlatmaz (ivmeli tekerlek)
    tekerle(100)
    tekerle(100)
    expect(yayilan(w)).toEqual(['2026-11'])
    // Kilit bitince yukarı: bir ay geri
    vi.advanceTimersByTime(200)
    tekerle(-100)
    expect(yayilan(w)).toEqual(['2026-11', '2026-09'])
    // Satır modundaki küçük kaydırma (deltaMode 1): 3 satır ≈ 48 px → bir ay
    vi.advanceTimersByTime(200)
    tekerle(3, 1)
    expect(yayilan(w)).toEqual(['2026-11', '2026-09', '2026-11'])
  })

  it('tekerlek: küçük kaydırmalar eşiğe kadar birikir; sayfa kaymaz (preventDefault); pasifken işlem yok', async () => {
    const w = kur()
    const liste = w.get('[role="listbox"]')
    const olay = (deltaY: number) => {
      const e = new WheelEvent('wheel', { deltaY, bubbles: true, cancelable: true })
      liste.element.dispatchEvent(e)

      return e
    }
    expect(olay(10).defaultPrevented).toBe(true)
    olay(10)
    expect(yayilan(w)).toEqual([])
    olay(15)
    expect(yayilan(w)).toEqual(['2026-11'])
    await w.setProps({ pasif: true, modelValue: '2026-11' })
    vi.advanceTimersByTime(200)
    olay(100)
    expect(yayilan(w)).toEqual(['2026-11'])
  })

  it('tekerlek ileri sınırda ileri gitmez', () => {
    const w = kur({ modelValue: '2026-11' })
    w.get('[role="listbox"]').element.dispatchEvent(
      new WheelEvent('wheel', { deltaY: 100, bubbles: true, cancelable: true }),
    )
    expect(yayilan(w)).toEqual([])
  })

  it('kaydırma durunca ortaya oturan ay seçilir (satır 24 px)', async () => {
    const w = kur()
    const liste = w.get('[role="listbox"]')
    const el = liste.element as HTMLElement
    const aylar = w.findAll('[role="option"]').map((o) => o.text())
    const hedef = aylar.indexOf('Haziran 2026')
    el.scrollTop = hedef * 24
    await liste.trigger('scroll')
    expect(yayilan(w)).toEqual([])
    vi.advanceTimersByTime(130)
    expect(yayilan(w)).toEqual(['2026-06'])
  })

  it('sınırsız (null): ileri aylar da listede', () => {
    const w = kur({ ileriSinir: null })
    const aylar = w.findAll('[role="option"]').map((o) => o.text())
    expect(aylar).toContain('Ekim 2028')
  })

  it('bos: "Tüm dönemler" yazar, seçili ay yok; aynı aya dokunmak bile o ayı seçer', async () => {
    const w = kur({ bos: true })
    expect(w.get('[data-test="ay-secici-bos"]').text()).toBe('Tüm dönemler')
    expect(w.find('[data-test="ay-secici"]').exists()).toBe(false)
    expect(w.find('[aria-selected="true"]').exists()).toBe(false)
    const ekim = w.findAll('[role="option"]').find((o) => o.text() === 'Ekim 2026')!
    await ekim.trigger('click')
    expect(yayilan(w)).toEqual(['2026-10'])
  })

  it('pasif: soluk ve seçim yapılmaz', async () => {
    const w = kur({ pasif: true })
    expect(w.get('[data-test="ay-carki"]').classes()).toContain('opacity-40')
    await w.get('button[aria-label="Önceki ay"]').trigger('click')
    expect(yayilan(w)).toEqual([])
  })

  it('yükseklik 50 px (100 px sınırının altında)', () => {
    const w = kur()
    expect(w.get('[data-test="ay-carki"]').classes()).toContain('h-[50px]')
  })
})
