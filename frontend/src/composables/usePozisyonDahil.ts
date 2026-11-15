import { useToast } from 'primevue/usetoast'
import { finansApi, type Pozisyon } from '@/api/finans'
import { hataMesaji } from '@/api/http'

/**
 * Yatırım satırını genel toplama / zekâta dahil eder ya da çıkarır; sonra sayfa verisi `yenile` ile tazelenir.
 */
export function usePozisyonDahil(
  yenile: () => unknown,
): (p: Pozisyon, dahil: boolean) => Promise<void> {
  const toast = useToast()

  return async (p, dahil) => {
    if (p.hesap_id === null) return
    try {
      const r = await finansApi.pozisyonDahil(p.hesap_id, p.varlik.id, dahil)
      toast.add({ severity: 'success', summary: r.message, life: 3000 })
      await yenile()
    } catch (e) {
      toast.add({ severity: 'error', summary: 'Yapılamadı', detail: hataMesaji(e), life: 4000 })
    }
  }
}
