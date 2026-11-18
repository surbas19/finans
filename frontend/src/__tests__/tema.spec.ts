import { beforeEach, describe, expect, it, vi } from 'vitest'

/** Görünüm seçimi: html.koyu sınıfı, tarayıcıda saklama, sistem ayarını izleme, grafik yenileme sayacı */
describe('useTema', () => {
  let karanlik = false
  let dinleyici: (() => void) | null = null

  beforeEach(() => {
    vi.resetModules()
    localStorage.clear()
    document.documentElement.classList.remove('koyu')
    document.head.innerHTML = '<meta name="theme-color" content="#0f766e">'
    karanlik = false
    dinleyici = null
    vi.stubGlobal('matchMedia', () => ({
      get matches() {
        return karanlik
      },
      addEventListener: (_: string, f: () => void) => {
        dinleyici = f
      },
      removeEventListener: () => {},
    }))
  })

  it('sistem: cihaz koyuya geçince html.koyu eklenir, açığa dönünce kalkar', async () => {
    const { temayiBaslat, useTema } = await import('@/composables/useTema')
    temayiBaslat()
    expect(useTema().tema.value).toBe('sistem')
    expect(document.documentElement.classList.contains('koyu')).toBe(false)
    karanlik = true
    dinleyici?.()
    expect(document.documentElement.classList.contains('koyu')).toBe(true)
    expect(document.querySelector('meta[name="theme-color"]')!.getAttribute('content')).toBe(
      '#020617',
    )
  })

  it('elle seçim saklanır ve cihaz ayarını ezer; "sistem" saklananı siler', async () => {
    const { temayiBaslat, useTema, temaSurumu } = await import('@/composables/useTema')
    temayiBaslat()
    const { temaSec } = useTema()
    const once = temaSurumu.value
    temaSec('koyu')
    expect(localStorage.getItem('finans-tema')).toBe('koyu')
    expect(document.documentElement.classList.contains('koyu')).toBe(true)
    expect(temaSurumu.value).toBeGreaterThan(once)
    karanlik = true
    temaSec('acik')
    expect(document.documentElement.classList.contains('koyu')).toBe(false)
    temaSec('sistem')
    expect(localStorage.getItem('finans-tema')).toBeNull()
    expect(document.documentElement.classList.contains('koyu')).toBe(true)
  })

  it('saklanan seçim açılışta okunur', async () => {
    localStorage.setItem('finans-tema', 'koyu')
    const { temayiBaslat, useTema } = await import('@/composables/useTema')
    temayiBaslat()
    expect(useTema().tema.value).toBe('koyu')
    expect(document.documentElement.classList.contains('koyu')).toBe(true)
  })
})
