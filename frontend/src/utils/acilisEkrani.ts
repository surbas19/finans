import type { Router } from 'vue-router'

/**
 * Açılış ekranını (index.html'deki #acilis) kapatır: uygulama hazır (ilk rota çözüldü, oturum denetimi bitti) ve ekran en
 * az animasyon süresi kadar (~1,2 sn; hareketi azalt'ta 0,25 sn) görünmüş olunca solarak kalkar. Oturumun ilk açılışı
 * değilse index.html ekranı zaten kaldırmıştır, burada yapılacak şey yoktur.
 */
export async function acilisiKapat(router: Router): Promise<void> {
  const el = document.getElementById('acilis')
  if (!el) return
  const bilgi = (window as unknown as { __acilis?: { basla: number; en_az: number } }).__acilis
  try {
    await router.isReady()
  } catch {
    // Rota hatası açılışı sonsuza dek tutmasın
  }
  const gecen = bilgi ? Date.now() - bilgi.basla : Number.POSITIVE_INFINITY
  const bekle = bilgi ? Math.max(0, bilgi.en_az - gecen) : 0
  setTimeout(() => {
    el.style.opacity = '0'
    el.addEventListener('transitionend', () => el.remove(), { once: true })
    // transitionend gelmezse (hareketi azalt, arka plan sekmesi) yine kaldırılır
    setTimeout(() => el.remove(), 450)
  }, bekle)
}
