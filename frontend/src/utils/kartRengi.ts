/**
 * Kredi kartı renkleri: PrimeVue paletindeki 12 renk (`var(--p-<renk>-500)`). Rengi seçilmemiş kart, kimliğine göre
 * sabit bir renk alır (migration gerekmez; bütün kartlar renkli görünür).
 */
export const KART_RENKLERI = [
  'teal',
  'sky',
  'blue',
  'indigo',
  'violet',
  'pink',
  'rose',
  'red',
  'orange',
  'amber',
  'emerald',
  'slate',
] as const

export type KartRengi = (typeof KART_RENKLERI)[number]

interface RenkliKart {
  id: number
  renk?: string | null
}

function gecerliMi(renk: string | null | undefined): renk is KartRengi {
  return !!renk && (KART_RENKLERI as readonly string[]).includes(renk)
}

/** Kartın rengi: seçilmişse o, değilse kimliğinden türeyen sabit renk */
export function kartRengi(kart: RenkliKart | null | undefined): KartRengi {
  if (!kart) return 'slate'
  if (gecerliMi(kart.renk)) return kart.renk

  return KART_RENKLERI[Math.abs(kart.id) % KART_RENKLERI.length]!
}

/** Düz renk (nokta, şerit, çizgi): `var(--p-teal-500)` */
export function kartRengiCss(kart: RenkliKart | null | undefined): string {
  return `var(--p-${kartRengi(kart)}-500)`
}

/** Kredi kartı görünümü için çapraz degrade (koyu → açık) */
export function kartDegradesi(kart: RenkliKart | null | undefined): string {
  const r = kartRengi(kart)

  return `linear-gradient(135deg, var(--p-${r}-700), var(--p-${r}-500))`
}

/** Yeni kart için henüz kullanılmayan ilk renk (hepsi kullanılıyorsa sıradakini döndürür) */
export function sonrakiKartRengi(kullanilan: (string | null | undefined)[]): KartRengi {
  const dolu = new Set(kullanilan.filter(gecerliMi))

  return (
    KART_RENKLERI.find((r) => !dolu.has(r)) ??
    KART_RENKLERI[kullanilan.length % KART_RENKLERI.length]!
  )
}
