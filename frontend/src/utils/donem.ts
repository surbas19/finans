/**
 * Ay dönemi "YYYY-AA": gelirin ait olduğu ay, faturanın hizmet ayı, kart harcamasının rapor ayı (sunucudaki
 * App\Support\Donem ile aynı).
 */

const AYLAR = [
  'Ocak',
  'Şubat',
  'Mart',
  'Nisan',
  'Mayıs',
  'Haziran',
  'Temmuz',
  'Ağustos',
  'Eylül',
  'Ekim',
  'Kasım',
  'Aralık',
]

export const DONEM_DESENI = /^\d{4}-(0[1-9]|1[0-2])$/

export function gecerliDonem(donem: string | null | undefined): donem is string {
  return !!donem && DONEM_DESENI.test(donem)
}

export function buAy(simdi: Date = new Date()): string {
  return tarihtenDonem(simdi)
}

export function tarihtenDonem(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

/** "2026-01" + 1 → "2026-02"; eksi olabilir */
export function donemEkle(donem: string, ay: number): string {
  const [y, a] = donem.split('-').map(Number) as [number, number]
  const toplam = y * 12 + (a - 1) + ay

  return `${Math.floor(toplam / 12)}-${String((toplam % 12) + 1).padStart(2, '0')}`
}

/** "2026-02" → "Şubat 2026"; boşsa "Dönem bilinmiyor" */
export function donemEtiketi(donem: string | null | undefined): string {
  if (!gecerliDonem(donem)) return 'Dönem bilinmiyor'
  const [y, a] = donem.split('-').map(Number) as [number, number]

  return `${AYLAR[a - 1]} ${y}`
}

/** Ay seçici (PrimeVue DatePicker view="month") ile dönem arasında */
export function donemdenTarih(donem: string): Date {
  const [y, a] = donem.split('-').map(Number) as [number, number]

  return new Date(y, a - 1, 1)
}

/** "YYYY-MM-DD" tarihinin dönemi */
export function tarihMetnindenDonem(tarih: string | null | undefined): string | null {
  return tarih && /^\d{4}-\d{2}-\d{2}/.test(tarih) ? tarih.slice(0, 7) : null
}
