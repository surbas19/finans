import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import { finansApi, ISLEM_TURLERI, type Islem } from '@/api/finans'
import { dogrulamaHatalari, hataMesaji } from '@/api/http'
import { tarih } from '@/utils/bicim'

/**
 * İşlem silme: onay sorusu, API, bildirim. Masaüstünde işlem satırındaki çöp düğmesi, telefonda düzenleme
 * penceresindeki "Sil" kullanır. Silme sonraki bir satışı geçersiz kılacaksa sunucu reddeder; mesajı gösterilir.
 */
export function useIslemSil() {
  const confirm = useConfirm()
  const toast = useToast()

  function sil(islem: Islem, silindi: () => void): void {
    confirm.require({
      header: 'İşlemi sil',
      message: `${tarih(islem.tarih)} tarihli ${ISLEM_TURLERI[islem.tur].toLocaleLowerCase('tr-TR')} kaydı silinsin mi?`,
      acceptProps: { label: 'Sil', severity: 'danger' },
      rejectProps: { label: 'Vazgeç', severity: 'secondary', text: true },
      accept: async () => {
        try {
          await finansApi.islemSil(islem.id)
          toast.add({ severity: 'success', summary: 'İşlem silindi', life: 3000 })
          silindi()
        } catch (e) {
          const hata = Object.values(dogrulamaHatalari(e))[0]
          toast.add({
            severity: 'error',
            summary: 'Silinemedi',
            detail: hata ?? hataMesaji(e),
            life: 6000,
          })
        }
      },
    })
  }

  return { sil }
}
