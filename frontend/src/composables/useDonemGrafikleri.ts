import { onMounted, ref, watch } from 'vue'
import { aileApi, type DonemGrafikleri } from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { useSonIstek } from '@/composables/useSonIstek'

/**
 * Faturalar / Kartlar / Gelirler sayfalarının dönem grafikleri: yıl seçilir, sunucudan yılın 12 ayı gelir.
 * Yıl değişince yeniden yüklenir; eski yıldan geç dönen yanıt yenisini ezmez.
 */
export function useDonemGrafikleri() {
  const sonIstek = useSonIstek()
  const yil = ref(new Date().getFullYear())
  const veri = ref<DonemGrafikleri | null>(null)
  const yukleniyor = ref(true)
  const hata = ref<string | null>(null)

  async function yukle(): Promise<void> {
    yukleniyor.value = true
    hata.value = null
    try {
      const yanit = await sonIstek(() => aileApi.grafikler(yil.value))
      if (yanit !== undefined) {
        veri.value = yanit
        yukleniyor.value = false
      }
    } catch (e) {
      hata.value = hataMesaji(e)
      yukleniyor.value = false
    }
  }

  onMounted(() => void yukle())
  watch(yil, () => void yukle())

  return { yil, veri, yukleniyor, hata, yukle }
}
