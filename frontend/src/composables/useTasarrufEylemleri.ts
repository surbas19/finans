import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import { aileApi, type Tasarruf } from '@/api/aile'
import { finansApi } from '@/api/finans'
import { hataMesaji } from '@/api/http'

/**
 * Tasarruf kalemi eylemleri (Özet ve Tasarruf sekmesi): silme (onaylı) ve bağlı portföy işlemini genel toplama
 * dahil etme / çıkarma. Sonra sayfa verisi `yenile` ile tazelenir.
 */
export function useTasarrufEylemleri(yenile: () => unknown) {
  const confirm = useConfirm()
  const toast = useToast()

  function sil(k: Tasarruf): void {
    confirm.require({
      header: 'Tasarrufu sil',
      message: k.hesaba_eklendi
        ? `"${k.varlik_ad}" tasarrufu silinsin mi? Portföydeki alış işlemi de silinir.`
        : `"${k.varlik_ad}" tasarrufu silinsin mi? Hiçbir hesaba eklenmemişti; yalnız tasarruf kaydı silinir.`,
      acceptProps: { label: 'Sil', severity: 'danger' },
      rejectProps: { label: 'Vazgeç', severity: 'secondary', text: true },
      accept: async () => {
        try {
          await aileApi.tasarrufSil(k.id)
          toast.add({ severity: 'success', summary: 'Tasarruf silindi', life: 3000 })
          await yenile()
        } catch (e) {
          toast.add({ severity: 'error', summary: 'Silinemedi', detail: hataMesaji(e), life: 6000 })
        }
      },
    })
  }

  async function dahil(k: Tasarruf, yeni: boolean): Promise<void> {
    if (k.islem_id === null) return
    try {
      const r = await finansApi.islemDahil(k.islem_id, yeni)
      toast.add({ severity: 'success', summary: r.message, life: 3000 })
      await yenile()
    } catch (e) {
      toast.add({ severity: 'error', summary: 'Yapılamadı', detail: hataMesaji(e), life: 6000 })
    }
  }

  return { sil, dahil }
}
