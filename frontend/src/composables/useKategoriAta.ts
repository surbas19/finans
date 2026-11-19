import { useToast } from 'primevue/usetoast'
import { aileApi, type KartIslemi } from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { useGeriAl } from '@/composables/useGeriAl'
import { useAileStore } from '@/stores/aile'

/**
 * Kart satırına kategori atar (kategori seçici, kategorisiz bölümü). Başarıda altta "Geri al" bildirimi çıkar; geri
 * alınca satırlar ve kural eski hâline döner. `yenile` listeyi yeniden yükler (atamadan ve geri almadan sonra).
 * Kullanım sayıları değiştiği için kategoriler de yenilenir ("sık kullanılan" sırası).
 */
export function useKategoriAta() {
  const aile = useAileStore()
  const toast = useToast()
  const { goster } = useGeriAl()

  async function ata(
    islem: KartIslemi,
    kategoriId: number | null,
    yenile: () => void,
    kapsam: 'hepsi' | 'tek' = 'hepsi',
  ): Promise<boolean> {
    try {
      const sonuc = await aileApi.kartIslemiKategori(islem.id, kategoriId, kapsam)
      void aile.kategorileriYenile().catch(() => {})
      yenile()
      const geri = sonuc.geri_al
      if (geri) {
        goster(sonuc.message, async () => {
          await aileApi.kartIslemiKategoriGeriAl(islem.id, geri)
          void aile.kategorileriYenile().catch(() => {})
          yenile()
        })
      } else {
        toast.add({ severity: 'success', summary: sonuc.message, life: 3500 })
      }

      return true
    } catch (e) {
      toast.add({ severity: 'error', summary: 'Yapılamadı', detail: hataMesaji(e), life: 5000 })

      return false
    }
  }

  return { ata }
}
