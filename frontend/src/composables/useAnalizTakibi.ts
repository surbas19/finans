import { onBeforeUnmount, ref } from 'vue'
import type { AiCalisma } from '@/api/aile'

/**
 * Yapay zekâ analizinin durumunu birkaç saniyede bir sorar (sunucu her soruşta çalışmayı bir adım ilerletir).
 * Çalışma bitince, bileşen kapanınca durur; sekme gizliyken sormaz, sekme dönünce sürer. Ağ hatasında bir sonraki
 * turda yeniden dener.
 */
export function useAnalizTakibi(
  sor: () => Promise<AiCalisma | null>,
  bitti: (calisma: AiCalisma | null) => void,
  aralikMs = 3000,
) {
  const calisma = ref<AiCalisma | null>(null)
  let zamanlayici: ReturnType<typeof setTimeout> | null = null
  let kapandi = false

  function durdur(): void {
    if (zamanlayici) clearTimeout(zamanlayici)
    zamanlayici = null
  }

  function planla(): void {
    durdur()
    if (kapandi || document.hidden) return
    zamanlayici = setTimeout(() => void tur(), aralikMs)
  }

  async function tur(): Promise<void> {
    zamanlayici = null
    try {
      const yeni = await sor()
      if (kapandi) return
      calisma.value = yeni
      if (!yeni?.etkin) {
        bitti(yeni)

        return
      }
    } catch {
      // bir sonraki turda yeniden
    }
    planla()
  }

  /** Bilinen çalışmayla başlar; sürüyorsa yoklama başlar */
  function izle(baslangic: AiCalisma | null): void {
    calisma.value = baslangic
    if (baslangic?.etkin) planla()
    else durdur()
  }

  function gorunurluk(): void {
    if (!document.hidden && calisma.value?.etkin && !zamanlayici) planla()
  }
  document.addEventListener('visibilitychange', gorunurluk)
  onBeforeUnmount(() => {
    kapandi = true
    durdur()
    document.removeEventListener('visibilitychange', gorunurluk)
  })

  return { calisma, izle, durdur }
}
