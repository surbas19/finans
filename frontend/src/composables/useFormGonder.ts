import { ref } from 'vue'
import { useToast } from 'primevue/usetoast'
import { dogrulamaHatalari, hataMesaji } from '@/api/http'

/**
 * Form gönderimi: yükleniyor durumu, alan hataları (422) ve genel hata bildirimi.
 */
export function useFormGonder() {
  const toast = useToast()
  const hatalar = ref<Record<string, string>>({})
  const gonderiliyor = ref(false)

  async function gonder<T>(islem: () => Promise<T>, basariMesaji?: string): Promise<T | undefined> {
    hatalar.value = {}
    gonderiliyor.value = true

    try {
      const sonuc = await islem()

      if (basariMesaji) {
        toast.add({ severity: 'success', summary: basariMesaji, life: 3000 })
      }

      return sonuc
    } catch (error) {
      hatalar.value = dogrulamaHatalari(error)

      if (Object.keys(hatalar.value).length === 0) {
        toast.add({
          severity: 'error',
          summary: 'İşlem yapılamadı',
          detail: hataMesaji(error),
          life: 5000,
        })
      }

      return undefined
    } finally {
      gonderiliyor.value = false
    }
  }

  return { hatalar, gonderiliyor, gonder }
}
