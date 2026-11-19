import type { Fatura, FaturaGirdisi } from '@/api/aile'
import { tarihMetnindenDonem } from '@/utils/donem'
import { kartRengi, type KartRengi } from '@/utils/kartRengi'

/**
 * Faturalar ekranı yardımcıları: güncelleme girdisi (tek alan değişirken ötekiler korunur), dönemi bilinmeyen
 * faturaya önerilen ay, gün farkı, tür rengi, kuruşla toplam.
 */

/** Faturanın mevcut alanlarıyla güncelleme girdisi; `degisiklik` üzerine yazılır */
export function faturaGirdisi(f: Fatura, degisiklik: Partial<FaturaGirdisi> = {}): FaturaGirdisi {
  return {
    saglayici_id: f.saglayici_id,
    hizmet_donemi: f.hizmet_donemi,
    tutar: f.tutar,
    son_odeme_tarihi: f.son_odeme_tarihi,
    durum: f.durum,
    odeme_tarihi: f.odeme_tarihi,
    not: f.not,
    ...degisiklik,
  }
}

/**
 * Dönemi bilinmeyen faturaya önerilen hizmet ayı: ödeme (yoksa son ödeme, o da yoksa kart satırı) tarihinin ayı.
 * Kart satırına bağlanan dönemsiz faturada uygulamanın kuralıyla aynı (ekstrenin ayı = ödeme ayı). Tarih yoksa null.
 */
export function oneriDonem(f: Fatura): string | null {
  return tarihMetnindenDonem(
    f.odeme_tarihi ?? f.son_odeme_tarihi ?? f.kart_islemi?.islem_tarihi ?? null,
  )
}

/** Bugünden tarihe gün (geçmişte eksi) */
export function gunFarki(tarih: string, bugun: Date = new Date()): number {
  const hedef = new Date(`${tarih}T00:00:00`)
  const gun = new Date(bugun.getFullYear(), bugun.getMonth(), bugun.getDate())

  return Math.round((hedef.getTime() - gun.getTime()) / 86_400_000)
}

/** "14 Kas" */
export function gunAy(tarih: string | null): string {
  return tarih
    ? new Date(`${tarih}T00:00:00`).toLocaleDateString('tr-TR', { day: 'numeric', month: 'short' })
    : '—'
}

/** Fatura türünün sabit rengi (paletten, kimliğe göre) */
export function turRengi(turId: number): KartRengi {
  return kartRengi({ id: turId })
}

/** Durum rozeti: gecikmiş / ödendi / ödenmedi */
export function durumRozeti(f: Fatura): { metin: string; renk: string } {
  if (f.gecikmis) return { metin: 'Gecikti', renk: 'bg-red-100 text-red-700' }

  return f.durum === 'odendi'
    ? { metin: 'Ödendi', renk: 'bg-emerald-100 text-emerald-800' }
    : { metin: 'Bekliyor', renk: 'bg-amber-100 text-amber-800' }
}

/** TL tutarlarını kuruşla toplar */
export function toplamTl(tutarlar: number[]): number {
  return tutarlar.reduce((t, n) => t + Math.round(n * 100), 0) / 100
}
