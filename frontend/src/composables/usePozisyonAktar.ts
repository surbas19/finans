import { ref } from 'vue'
import type { Pozisyon } from '@/api/finans'

/** "Başka hesaba aktar" penceresinin durumu: hangi satır, açık mı. */
export function usePozisyonAktar() {
  const aktarAcik = ref(false)
  const aktarPozisyon = ref<Pozisyon | null>(null)

  function aktarAc(p: Pozisyon): void {
    aktarPozisyon.value = p
    aktarAcik.value = true
  }

  return { aktarAcik, aktarPozisyon, aktarAc }
}
