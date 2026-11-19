import type { RouteRecordNameGeneric } from 'vue-router'

/**
 * Sayfa geçişinin yönü. Alt çubuktaki ve Bütçe sekmelerindeki sıra: sağdaki sayfaya geçerken yeni sayfa sağdan gelir (+1),
 * soldakine dönerken soldan (−1). Detaya inerken +1, listeye dönerken −1. Sırası bilinmeyen geçişte 0 (yalnız solma).
 */
export const SAYFA_SIRASI: Record<string, number> = {
  ozet: 10,
  hesaplar: 11,
  islemler: 12,
  aile: 20,
  'aile-gelir-gider': 21,
  'aile-tasarruf': 22,
  'aile-borclar': 23,
  'aile-kartlar': 24,
  'aile-islemler': 25,
  'aile-faturalar': 26,
  'aile-ayarlar': 27,
  izleme: 30,
  profil: 40,
  yonetim: 41,
}

/** Detay sayfaları: sırası yok, ama oradan listeye dönmek "geri"dir */
const DETAY_SAYFALARI = new Set([
  'hesap',
  'varlik',
  'aile-kart',
  'aile-ekstreler',
  'aile-ekstre',
  'aile-hesap-ekstresi',
])

function ad(r: RouteRecordNameGeneric): string {
  return typeof r === 'string' ? r : ''
}

/**
 * @param eski  Geçilen sayfanın adı
 * @param yeniGeri  Gidilen sayfanın `meta.geri` değeri (detay sayfasıysa dolu)
 * @param yeni  Gidilen sayfanın adı
 */
export function sayfaYonu(
  eski: RouteRecordNameGeneric | undefined,
  yeniGeri: unknown,
  yeni: RouteRecordNameGeneric | undefined,
): number {
  const a = ad(eski)
  const b = ad(yeni)
  if (!a || !b || a === b) return 0
  // Detaya inerken ileri; detaydan çıkarken (hedefin sırası var, kaynağın yok) geri
  if (yeniGeri) return 1
  const sa = SAYFA_SIRASI[a]
  const sb = SAYFA_SIRASI[b]
  if (sa === undefined && sb !== undefined) return DETAY_SAYFALARI.has(a) ? -1 : 0
  if (sa === undefined || sb === undefined) return 0

  return sb > sa ? 1 : -1
}
