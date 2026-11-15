/**
 * Uzun basma: bir öğeye 450 ms basılı tutulunca `tetikle(oge)` çağrılır. Parmak 8 px'ten fazla kayarsa, bırakılırsa ya
 * da tarayıcı kaydırmayı devralırsa (pointercancel) iptal olur. Uzun basmadan sonra gelen tıklama yutulur (öğenin kısa
 * dokunuş eylemi çalışmaz). Sağ tık / menü tuşu (`contextmenu`) aynı işi görür. Tetiklenince kısa titreşim (destekleyen
 * telefonlarda).
 *
 *   const uzun = useUzunBasma<KartIslemi>((i) => kategoriSec(i))
 *   <button @pointerdown="uzun.basla($event, i)" @pointermove="uzun.hareket"
 *           @pointerup="uzun.iptal" @pointercancel="uzun.iptal" @pointerleave="uzun.iptal"
 *           @contextmenu="uzun.menu($event, i)" @click="uzun.tiklamaYut() || kisaDokunus(i)">
 */
export function useUzunBasma<T>(tetikle: (oge: T) => void, sure = 450) {
  let zaman: ReturnType<typeof setTimeout> | undefined
  let baslangic: { x: number; y: number } | null = null
  let tetiklendi = false
  let sifirlama: ReturnType<typeof setTimeout> | undefined

  function iptal(): void {
    clearTimeout(zaman)
    zaman = undefined
    baslangic = null
  }

  function atesle(oge: T): void {
    tetiklendi = true
    // Tıklama hiç gelmezse bayrak takılı kalmasın
    clearTimeout(sifirlama)
    sifirlama = setTimeout(() => (tetiklendi = false), 800)
    if (typeof navigator !== 'undefined' && typeof navigator.vibrate === 'function') {
      navigator.vibrate(15)
    }
    tetikle(oge)
  }

  return {
    basla(e: PointerEvent, oge: T): void {
      // Fare ile yalnız sol tuş; sağ tık contextmenu ile gelir
      if (e.pointerType === 'mouse') return
      iptal()
      tetiklendi = false
      baslangic = { x: e.clientX, y: e.clientY }
      zaman = setTimeout(() => {
        zaman = undefined
        atesle(oge)
      }, sure)
    },
    hareket(e: PointerEvent): void {
      if (baslangic && Math.hypot(e.clientX - baslangic.x, e.clientY - baslangic.y) > 8) iptal()
    },
    iptal,
    /** Uzun basma sonrası gelen tıklamayı yutar; true dönerse kısa dokunuş eylemi çalıştırılmaz */
    tiklamaYut(): boolean {
      if (!tetiklendi) return false
      tetiklendi = false

      return true
    },
    /** Sağ tık / menü tuşu / (Android) uzun basmanın tarayıcı menüsü: uzun basma zaten tetiklendiyse yalnız engeller */
    menu(e: Event, oge: T): void {
      e.preventDefault()
      if (tetiklendi) return
      iptal()
      tetikle(oge)
    },
    temizle: iptal,
  }
}
