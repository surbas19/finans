import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import SayfaBasinaDon from '@/components/SayfaBasinaDon.vue'

describe('Sayfa başına dön düğmesi', () => {
  afterEach(() => {
    vi.restoreAllMocks()
    Object.defineProperty(window, 'scrollY', { value: 0, configurable: true })
  })

  it('bir ekran aşağı inilince görünür, basınca başa kaydırır', async () => {
    const kaydir = vi.fn<(secenek: ScrollToOptions) => void>()
    window.scrollTo = kaydir as unknown as typeof window.scrollTo
    Object.defineProperty(window, 'innerHeight', { value: 800, configurable: true })
    Object.defineProperty(window, 'scrollY', { value: 0, configurable: true })
    const w = mount(SayfaBasinaDon, { attachTo: document.body })
    expect(w.find('[data-test="basa-don"]').exists()).toBe(false)

    Object.defineProperty(window, 'scrollY', { value: 900, configurable: true })
    window.dispatchEvent(new Event('scroll'))
    await nextTick()
    const dugme = w.get('[data-test="basa-don"]')
    await dugme.trigger('click')
    expect(kaydir).toHaveBeenCalledWith(expect.objectContaining({ top: 0 }))

    Object.defineProperty(window, 'scrollY', { value: 100, configurable: true })
    window.dispatchEvent(new Event('scroll'))
    await nextTick()
    expect(w.find('[data-test="basa-don"]').exists()).toBe(false)
    w.unmount()
  })
})
