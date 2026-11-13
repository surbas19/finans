/**
 * Türkçe sayı / para / yüzde / tarih biçimleri. Para birimi TL ("₺1.234,56").
 */

const tlBicimi = new Intl.NumberFormat('tr-TR', {
  style: 'currency',
  currency: 'TRY',
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
})

/** 1234.5 → "₺1.234,50"; null → "—" */
export function tl(deger: number | null | undefined): string {
  return deger === null || deger === undefined ? '—' : tlBicimi.format(deger)
}

/** İşaretli TL: 1234.5 → "+₺1.234,50", -10 → "-₺10,00", 0 → "₺0,00" */
export function isaretliTl(deger: number | null | undefined): string {
  if (deger === null || deger === undefined) return '—'
  const metin = tlBicimi.format(Math.abs(deger))
  if (Math.abs(deger) < 0.005) return metin

  return (deger > 0 ? '+' : '-') + metin
}

/**
 * Fiyat: büyüklüğüne göre hane sayısı (1.234,50 · 50,3600 · 0,003148). Para birimi işareti eklenmez.
 */
export function fiyat(deger: number | null | undefined, paraBirimi = 'TRY'): string {
  if (deger === null || deger === undefined) return '—'
  const mutlak = Math.abs(deger)
  const hane = mutlak >= 1000 ? 2 : mutlak >= 1 ? 4 : 6
  const metin = new Intl.NumberFormat('tr-TR', {
    minimumFractionDigits: mutlak >= 1000 ? 2 : Math.min(2, hane),
    maximumFractionDigits: hane,
  }).format(deger)

  return paraBirimi === 'USD' ? `$${metin}` : `₺${metin}`
}

/** Miktar: gereksiz sıfırlar olmadan, en çok 8 hane: 0.01 → "0,01", 1500 → "1.500" */
export function miktar(deger: number | null | undefined): string {
  if (deger === null || deger === undefined) return '—'

  return new Intl.NumberFormat('tr-TR', { maximumFractionDigits: 8 }).format(deger)
}

/** Yüzde (değer zaten yüzde): 1.234 → "+%1,23", -0.5 → "-%0,50" */
export function yuzde(deger: number | null | undefined): string {
  if (deger === null || deger === undefined) return '—'
  const metin = new Intl.NumberFormat('tr-TR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(Math.abs(deger))
  if (Math.abs(deger) < 0.005) return `%${metin}`

  return `${deger > 0 ? '+' : '-'}%${metin}`
}

/** Artış yeşil, düşüş kırmızı, değişim yok / bilinmiyor gri */
export function degisimRengi(deger: number | null | undefined): string {
  if (deger === null || deger === undefined || Math.abs(deger) < 0.005) return 'text-surface-500'

  return deger > 0 ? 'text-emerald-600' : 'text-red-600'
}

/** "2026-11-13" / ISO → "13.11.2026" */
export function tarih(deger: string | null | undefined): string {
  if (!deger) return '—'
  const d = new Date(deger.length === 10 ? `${deger}T00:00:00` : deger)

  return d.toLocaleDateString('tr-TR', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

/** ISO → "13.11.2026 14:05" */
export function tarihSaat(deger: string | null | undefined): string {
  if (!deger) return '—'

  return new Date(deger).toLocaleString('tr-TR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

/** ISO → "az önce", "5 dk önce", "3 sa önce", "2 gün önce" */
export function neZaman(deger: string | null | undefined, simdi: Date = new Date()): string {
  if (!deger) return '—'
  const fark = Math.max(0, (simdi.getTime() - new Date(deger).getTime()) / 1000)
  if (fark < 60) return 'az önce'
  if (fark < 3600) return `${Math.floor(fark / 60)} dk önce`
  if (fark < 86400) return `${Math.floor(fark / 3600)} sa önce`

  return `${Math.floor(fark / 86400)} gün önce`
}

/** Bugünün tarihi "YYYY-MM-DD" (yerel saat) */
export function bugun(simdi: Date = new Date()): string {
  const y = simdi.getFullYear()
  const a = String(simdi.getMonth() + 1).padStart(2, '0')
  const g = String(simdi.getDate()).padStart(2, '0')

  return `${y}-${a}-${g}`
}

/** Date → "YYYY-MM-DD" (yerel saat; toISOString UTC'ye çevirip günü kaydırır) */
export function tarihMetni(d: Date): string {
  return bugun(d)
}
