import { readonly, ref } from 'vue'

/**
 * Tek bir "Geri al" bildirimi (uygulama genelinde): bir işlem yapılınca altta `sure` ms görünür; "Geri al"a basılınca
 * verilen işlev çalışır. Yeni bildirim eskisinin yerini alır. Bildirimi `GeriAlBildirimi` (AnaDuzen) çizer.
 *
 *   const { goster } = useGeriAl()
 *   goster('7 işlem Market'e atandı', async () => { await api.geriAl(...) })
 */
interface Bildirim {
  no: number
  mesaj: string
  geriAl: () => Promise<void> | void
}

const bildirim = ref<Bildirim | null>(null)
let zamanlayici: ReturnType<typeof setTimeout> | undefined
let sayac = 0

function kapat(): void {
  clearTimeout(zamanlayici)
  bildirim.value = null
}

function goster(mesaj: string, geriAl: Bildirim['geriAl'], sure = 5000): void {
  clearTimeout(zamanlayici)
  const no = ++sayac
  bildirim.value = { no, mesaj, geriAl }
  zamanlayici = setTimeout(() => {
    if (bildirim.value?.no === no) bildirim.value = null
  }, sure)
}

export function useGeriAl() {
  return { bildirim: readonly(bildirim), goster, kapat }
}
