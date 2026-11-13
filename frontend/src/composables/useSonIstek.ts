/**
 * Aynı veri için art arda yapılan isteklerde yalnızca en sonuncunun sonucunu kabul eder.
 * Örn. sayfa açılışındaki liste isteği, kayıttan sonraki yenilemeden geç dönerse yeni listeyi ezmesin.
 *
 * Kullanım:
 *   const sonIstek = useSonIstek()
 *   const veri = await sonIstek(() => api.liste())
 *   if (veri === undefined) return // daha yeni bir istek var
 */
export function useSonIstek() {
  let sayac = 0

  return async function <T>(istek: () => Promise<T>): Promise<T | undefined> {
    const bu = ++sayac
    const sonuc = await istek()

    return bu === sayac ? sonuc : undefined
  }
}
