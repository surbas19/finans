import { kartRengi } from '@/utils/kartRengi'

/**
 * Harcama kategorisi renkleri: kategorinin kendi rengi (kart paletindeki 12 PrimeVue rengi); rengi olmayan kategori
 * kimliğinden sabit bir renk alır. Her kategori ayrışan bir renk taşır (sunucu yeni kategoriye kullanılmayan rengi verir).
 */
interface RenkliKategori {
  id: number
  renk?: string | null
}

/** Küçük nokta ve seçili işaret rengi */
export function kategoriNoktasi(k: RenkliKategori | null | undefined): string {
  return k ? `var(--p-${kartRengi(k)}-500)` : 'var(--p-surface-400)'
}

/**
 * İşlem satırının solundaki çok yumuşak, yarı saydam (%50) gradient: kenarda kategori rengi, sağa doğru tamamen
 * saydama söner. Kategorisiz satır aynı yumuşaklıkta nötr gri.
 */
export function kategoriSeridi(k: RenkliKategori | null | undefined): string {
  const renk = k ? `var(--p-${kartRengi(k)}-400)` : 'var(--p-surface-400)'

  return `linear-gradient(to right, color-mix(in srgb, ${renk} 50%, transparent), transparent)`
}
