import { afterEach, describe, expect, it } from 'vitest'
import {
  dinamikYuklemeHatasiMi,
  yeniSurumMu,
  yenilemeGuvenliMi,
  yenilemeIzinliMi,
} from '@/utils/surumDenetimi'

function sahteDepo() {
  const veri = new Map<string, string>()

  return {
    getItem: (k: string) => veri.get(k) ?? null,
    setItem: (k: string, d: string) => void veri.set(k, d),
  }
}

describe('yeniSurumMu', () => {
  it('yalnızca iki sürüm de biliniyor ve farklıysa', () => {
    expect(yeniSurumMu('b77c1e8', 'a54db21')).toBe(true)
    expect(yeniSurumMu('b77c1e8', 'b77c1e8')).toBe(false)
    expect(yeniSurumMu(null, 'b77c1e8')).toBe(false)
    expect(yeniSurumMu('b77c1e8', '')).toBe(false)
  })
})

describe('yenilemeGuvenliMi', () => {
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('boş sayfa ve boş alanlar güvenli', () => {
    document.body.innerHTML = '<main><input type="text" value=""><textarea></textarea></main>'
    expect(yenilemeGuvenliMi(document)).toBe(true)
  })

  it('açık pencere ya da açılır panel varken değil', () => {
    document.body.innerHTML = '<main></main><div class="p-dialog-mask"></div>'
    expect(yenilemeGuvenliMi(document)).toBe(false)
    document.body.innerHTML = '<main></main><div class="p-select-overlay"></div>'
    expect(yenilemeGuvenliMi(document)).toBe(false)
  })

  it('sayfada yazılmış alan varken değil (ör. Profil şifre formu)', () => {
    document.body.innerHTML = '<main><input type="password" value="gizli1"></main>'
    expect(yenilemeGuvenliMi(document)).toBe(false)
    document.body.innerHTML = '<main><textarea>not</textarea></main>'
    expect(yenilemeGuvenliMi(document)).toBe(false)
  })

  it('onay kutusu, salt okunur ve main dışındaki alanlar sayılmaz', () => {
    document.body.innerHTML =
      '<main><input type="checkbox" value="on" checked><input type="text" value="x" readonly></main><input value="dışarıda">'
    expect(yenilemeGuvenliMi(document)).toBe(true)
  })
})

describe('yenilemeIzinliMi', () => {
  it('aynı sunucu sürümü için saatte bir kez', () => {
    const depo = sahteDepo()
    expect(yenilemeIzinliMi('surum:b77c1e8', 1_000, depo)).toBe(true)
    expect(yenilemeIzinliMi('surum:b77c1e8', 1_000 + 30 * 60_000, depo)).toBe(false)
    expect(yenilemeIzinliMi('surum:c0ffee1', 1_000 + 30 * 60_000, depo)).toBe(true)
  })

  it('dosya hatası için 20 sn aralık', () => {
    const depo = sahteDepo()
    expect(yenilemeIzinliMi('dosya-hatasi', 0, depo)).toBe(true)
    expect(yenilemeIzinliMi('dosya-hatasi', 10_000, depo)).toBe(false)
    expect(yenilemeIzinliMi('dosya-hatasi', 25_000, depo)).toBe(true)
  })

  it('depolama yoksa ya da hata verirse kendiliğinden yenilenmez', () => {
    expect(yenilemeIzinliMi('surum:x', 0, null)).toBe(false)
    const bozuk = {
      getItem: () => {
        throw new Error('engelli')
      },
      setItem: () => undefined,
    }
    expect(yenilemeIzinliMi('surum:x', 0, bozuk)).toBe(false)
  })
})

describe('dinamikYuklemeHatasiMi', () => {
  it('tarayıcıların tembel yükleme hata mesajları', () => {
    expect(
      dinamikYuklemeHatasiMi(
        new TypeError('Failed to fetch dynamically imported module: /assets/x.js'),
      ),
    ).toBe(true)
    expect(dinamikYuklemeHatasiMi(new TypeError('Importing a module script failed.'))).toBe(true)
    expect(dinamikYuklemeHatasiMi(new Error('Unable to preload CSS for /assets/x.css'))).toBe(true)
    expect(dinamikYuklemeHatasiMi(new Error('Request failed with status code 500'))).toBe(false)
  })
})
