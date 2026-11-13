import type { HesapOzeti, Pozisyon } from '@/api/finans'

/**
 * Özet kaydırıcısındaki halka dilimleri. Hesap yapılmaz; sunucunun değerleri yalnızca dizilir ve gruplanır.
 */

export interface HalkaDilimi {
  etiket: string
  deger: number
  /** CSS rengi ya da `--p-...` değişkeni */
  renk: string
}

/** Varlık dilimleri: kâr / zarar anlamındaki yeşil ve kırmızı yok; sırayla en büyükten küçüğe verilir */
export const HALKA_RENKLERI = [
  '--p-sky-500',
  '--p-violet-500',
  '--p-amber-400',
  '--p-pink-500',
  '--p-cyan-500',
  '--p-indigo-400',
] as const
/** Küçük varlıkların toplandığı "Diğer" dilimi */
export const DIGER_RENGI = '--p-surface-300'

/**
 * Değeri olan pozisyonlar büyükten küçüğe; ilk `enCok` tanesi renkli, kalanları tek gri "Diğer" diliminde.
 * `renkler`: varlık kimliği → satırdaki şeridin rengi (Diğer'e düşenler gri).
 */
export function varlikDilimleri(
  pozisyonlar: Pozisyon[],
  enCok: number = HALKA_RENKLERI.length,
): { dilimler: HalkaDilimi[]; renkler: Map<number, string> } {
  const sirali = pozisyonlar
    .filter((p): p is Pozisyon & { deger: number } => p.deger !== null && p.deger > 0)
    .sort((a, b) => b.deger - a.deger)
  const renkler = new Map<number, string>()
  const dilimler: HalkaDilimi[] = []
  let diger = 0

  sirali.forEach((p, i) => {
    if (i < enCok) {
      const renk = HALKA_RENKLERI[i % HALKA_RENKLERI.length]!
      renkler.set(p.varlik.id, renk)
      dilimler.push({ etiket: p.varlik.kod, deger: p.deger, renk })
    } else {
      renkler.set(p.varlik.id, DIGER_RENGI)
      diger += p.deger
    }
  })
  if (diger > 0) dilimler.push({ etiket: 'Diğer', deger: diger, renk: DIGER_RENGI })

  return { dilimler, renkler }
}

/** Hesabın kart rengi (`teal`, `sky`…) → CSS değişkeni */
export function hesapRengi(hesap: Pick<HesapOzeti, 'renk'>): string {
  return `--p-${hesap.renk ?? 'teal'}-500`
}

/** Değeri sıfırdan büyük her hesap bir dilim, hesabın kendi renginde */
export function hesapDilimleri(hesaplar: HesapOzeti[]): HalkaDilimi[] {
  return hesaplar
    .filter((h) => h.deger > 0)
    .map((h) => ({ etiket: h.ad, deger: h.deger, renk: hesapRengi(h) }))
}
