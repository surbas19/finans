import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { sayfaYonu } from '@/composables/useSayfaYonu'
import { acilisiKapat } from '@/utils/acilisEkrani'

describe('Sayfa geçiş yönü', () => {
  it('sağdaki sekmeye ileri, soldakine geri', () => {
    expect(sayfaYonu('ozet', undefined, 'hesaplar')).toBe(1)
    expect(sayfaYonu('hesaplar', undefined, 'ozet')).toBe(-1)
    expect(sayfaYonu('aile', undefined, 'aile-faturalar')).toBe(1)
    expect(sayfaYonu('aile-faturalar', undefined, 'aile-borclar')).toBe(-1)
    // Bölümler arası: Portföy → Bütçe → İzleme
    expect(sayfaYonu('islemler', undefined, 'aile')).toBe(1)
    expect(sayfaYonu('izleme', undefined, 'aile-kartlar')).toBe(-1)
  })

  it('detaya inerken ileri, detaydan dönerken geri; aynı sayfa ve bilinmeyen 0', () => {
    expect(sayfaYonu('hesaplar', 'hesaplar', 'hesap')).toBe(1)
    expect(sayfaYonu('hesap', undefined, 'hesaplar')).toBe(-1)
    expect(sayfaYonu('aile-kartlar', 'aile-kartlar', 'aile-kart')).toBe(1)
    expect(sayfaYonu('ozet', undefined, 'ozet')).toBe(0)
    expect(sayfaYonu(undefined, undefined, 'ozet')).toBe(0)
    expect(sayfaYonu('giris', undefined, 'ozet')).toBe(0)
  })
})

describe('Açılış ekranı', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    document.body.innerHTML = '<div id="acilis" style="opacity:1"></div>'
  })
  afterEach(() => {
    vi.useRealTimers()
    delete (window as unknown as { __acilis?: unknown }).__acilis
    document.body.innerHTML = ''
  })

  const yonlendirici = (hazir: Promise<void>) => ({ isReady: () => hazir }) as never

  it('uygulama hazır olsa da en az süre görünür, sonra solup kaldırılır', async () => {
    ;(window as unknown as { __acilis: unknown }).__acilis = { basla: Date.now(), en_az: 1200 }
    const bitti = acilisiKapat(yonlendirici(Promise.resolve()))
    await bitti
    vi.advanceTimersByTime(1000)
    expect(document.getElementById('acilis')?.style.opacity).toBe('1')
    vi.advanceTimersByTime(250)
    expect(document.getElementById('acilis')?.style.opacity).toBe('0')
    vi.advanceTimersByTime(500)
    expect(document.getElementById('acilis')).toBeNull()
  })

  it('ekran yoksa (oturumun ilk açılışı değil) hiçbir şey yapmaz; rota hatası açılışı tutmaz', async () => {
    document.body.innerHTML = ''
    await expect(acilisiKapat(yonlendirici(Promise.resolve()))).resolves.toBeUndefined()
    document.body.innerHTML = '<div id="acilis"></div>'
    await acilisiKapat(yonlendirici(Promise.reject(new Error('rota'))))
    vi.advanceTimersByTime(600)
    expect(document.getElementById('acilis')).toBeNull()
  })
})
