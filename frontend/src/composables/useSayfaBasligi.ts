import { onBeforeUnmount, readonly, ref, watch, type Ref } from 'vue'

/** Detay sayfasının üst çubukta görünen adı (ör. hesap adı); boşsa rotanın `meta.baslik`'i */
const ozelBaslik = ref<string | null>(null)

/** Üst çubuğun okuduğu başlık */
export function useOzelBaslik(): Readonly<Ref<string | null>> {
  return readonly(ozelBaslik)
}

/**
 * Sayfa, üst çubuktaki başlığı kendi verisinden verir (hesap / varlık / kart adı). Sayfa kapanınca başlık rotanınkine
 * döner. Değer yüklenene kadar (null) rotanın başlığı görünür.
 *
 *   useSayfaBasligi(() => hesap.value?.ad)
 */
export function useSayfaBasligi(kaynak: () => string | null | undefined): void {
  watch(
    kaynak,
    (deger) => {
      ozelBaslik.value = deger?.trim() || null
    },
    { immediate: true },
  )
  onBeforeUnmount(() => {
    ozelBaslik.value = null
  })
}
