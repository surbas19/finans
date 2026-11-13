import { defineStore } from 'pinia'
import { ref } from 'vue'
import { finansApi, type Varlik } from '@/api/finans'

/**
 * Varlık kataloğu (ortak liste); işlem formu ve izleme listesi aynı kopyayı kullanır.
 */
export const useKatalogStore = defineStore('katalog', () => {
  const varliklar = ref<Varlik[]>([])
  let yukleniyor: Promise<void> | null = null

  function yukle(zorla = false): Promise<void> {
    if (!zorla && varliklar.value.length > 0) return Promise.resolve()
    if (!zorla && yukleniyor) return yukleniyor
    yukleniyor = finansApi
      .varliklar()
      .then((veri) => {
        varliklar.value = veri.varliklar
      })
      .finally(() => {
        yukleniyor = null
      })

    return yukleniyor
  }

  function ekle(varlik: Varlik): void {
    if (!varliklar.value.some((v) => v.id === varlik.id)) {
      varliklar.value = [...varliklar.value, varlik]
    }
  }

  return { varliklar, yukle, ekle }
})
