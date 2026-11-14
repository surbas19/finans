/**
 * Profil resmi tarayıcıda hazırlanır: fotoğrafın ortasından kare kesilir, `boyut`×`boyut`'a küçültülür. Sunucuda resim
 * işleme (GD) gerekmez; sunucu yalnızca doğrular ve saklar.
 */

/** Dikdörtgenin ortasındaki en büyük kare (kaynak koordinatları) */
export function kareAlan(
  genislik: number,
  yukseklik: number,
): { x: number; y: number; kenar: number } {
  const kenar = Math.min(genislik, yukseklik)

  return {
    x: Math.floor((genislik - kenar) / 2),
    y: Math.floor((yukseklik - kenar) / 2),
    kenar,
  }
}

function blobAl(tuval: HTMLCanvasElement, tur: string, kalite: number): Promise<Blob | null> {
  return new Promise((coz) => tuval.toBlob(coz, tur, kalite))
}

/**
 * Kare kırpılmış, küçültülmüş resim: WebP; tarayıcı WebP üretemiyorsa JPEG. Saydam alanlar beyaz olur.
 * Telefon fotoğrafının yönü (EXIF) düzeltilir. Açılamayan dosyada hata fırlatır.
 */
export async function kareKucult(dosya: Blob, boyut = 256): Promise<Blob> {
  let resim: ImageBitmap
  try {
    resim = await createImageBitmap(dosya, { imageOrientation: 'from-image' })
  } catch {
    throw new Error('Bu resim açılamadı. JPEG ya da PNG bir fotoğraf seçin.')
  }
  try {
    const { x, y, kenar } = kareAlan(resim.width, resim.height)
    const tuval = document.createElement('canvas')
    tuval.width = boyut
    tuval.height = boyut
    const ciz = tuval.getContext('2d')
    if (!ciz) throw new Error('Resim hazırlanamadı.')
    ciz.fillStyle = '#ffffff'
    ciz.fillRect(0, 0, boyut, boyut)
    ciz.imageSmoothingQuality = 'high'
    ciz.drawImage(resim, x, y, kenar, kenar, 0, 0, boyut, boyut)

    const webp = await blobAl(tuval, 'image/webp', 0.85)
    if (webp?.type === 'image/webp') return webp
    const jpeg = await blobAl(tuval, 'image/jpeg', 0.88)
    if (!jpeg) throw new Error('Resim hazırlanamadı.')

    return jpeg
  } finally {
    resim.close()
  }
}
