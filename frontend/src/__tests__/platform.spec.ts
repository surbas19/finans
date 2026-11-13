import { describe, expect, it } from 'vitest'
import { iosMu, kuruluMu, mobilMu } from '@/utils/platform'

const IPHONE =
  'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1'
const MAC =
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15'
const ANDROID =
  'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36'
const WINDOWS =
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36'

describe('platform', () => {
  it('iPhone ve masaüstü gibi görünen iPad iOS sayılır, Mac sayılmaz', () => {
    expect(iosMu(IPHONE)).toBe(true)
    expect(iosMu(MAC, 5)).toBe(true) // iPadOS Safari
    expect(iosMu(MAC, 0)).toBe(false)
    expect(iosMu(ANDROID)).toBe(false)
  })

  it('telefon tespiti', () => {
    expect(mobilMu(IPHONE)).toBe(true)
    expect(mobilMu(ANDROID)).toBe(true)
    expect(mobilMu(WINDOWS)).toBe(false)
    expect(mobilMu(MAC)).toBe(false)
  })

  it('ana ekrandan açılış', () => {
    expect(kuruluMu(true)).toBe(true)
    expect(kuruluMu(false, true)).toBe(true)
    expect(kuruluMu(false, false)).toBe(false)
    expect(kuruluMu(false)).toBe(false)
  })
})
