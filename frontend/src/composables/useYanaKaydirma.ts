/**
 * Yana kaydırma (parmak / kalem): yatay hareket `esik` px'i ve dikey hareketin 1,5 katını geçince `sola` ya da `saga`
 * çağrılır. Fare sürüklemesi sayılmaz (metin seçimi bozulmasın). Öğeye `touch-action: pan-y` verilmeli: dikey sayfa
 * kaydırması tarayıcıda kalır, yatay hareket bize gelir.
 *
 *   const kaydir = useYanaKaydirma({ sola: sonraki, saga: onceki })
 *   <section v-bind="kaydir" class="touch-pan-y">
 */
export function useYanaKaydirma(
  geri: { sola: () => void; saga: () => void },
  esik = 60,
): {
  onPointerdown: (e: PointerEvent) => void
  onPointerup: (e: PointerEvent) => void
  onPointercancel: () => void
} {
  let baslangic: { x: number; y: number; id: number } | null = null

  return {
    onPointerdown(e: PointerEvent): void {
      baslangic = e.pointerType === 'mouse' ? null : { x: e.clientX, y: e.clientY, id: e.pointerId }
    },
    onPointerup(e: PointerEvent): void {
      const b = baslangic
      baslangic = null
      if (!b || b.id !== e.pointerId) return
      const dx = e.clientX - b.x
      const dy = e.clientY - b.y
      if (Math.abs(dx) < esik || Math.abs(dx) < Math.abs(dy) * 1.5) return
      if (dx < 0) geri.sola()
      else geri.saga()
    },
    onPointercancel(): void {
      baslangic = null
    },
  }
}
