import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import {
  aileApi,
  type Banka,
  type FaturaTuru,
  type GelirTuru,
  type HaneBilgisi,
  type Kart,
  type Kategori,
  type Saglayici,
} from '@/api/aile'

/**
 * Bütçe sayfalarının ortak tanımları: hane ve üyeler, gelir türleri, bankalar, kartlar, kategoriler, fatura türleri
 * ve sağlayıcılar. Bir kez yüklenir; değişince `yukle(true)`.
 */
export const useAileStore = defineStore('aile', () => {
  const hane = ref<HaneBilgisi | null>(null)
  const bankalar = ref<Banka[]>([])
  const kartlar = ref<Kart[]>([])
  const kategoriler = ref<Kategori[]>([])
  const turler = ref<FaturaTuru[]>([])
  const saglayicilar = ref<Saglayici[]>([])
  const gelirTurleri = ref<GelirTuru[]>([])
  let yukleniyor: Promise<void> | null = null

  function yukle(zorla = false): Promise<void> {
    if (!zorla && hane.value) return Promise.resolve()
    if (!zorla && yukleniyor) return yukleniyor
    yukleniyor = Promise.all([
      aileApi.hane(),
      aileApi.bankalar(),
      aileApi.kartlar(),
      aileApi.kategoriler(),
      aileApi.faturaTurleri(),
      aileApi.saglayicilar(),
      aileApi.gelirTurleri(),
    ])
      .then(([h, b, k, kat, t, s, g]) => {
        hane.value = h
        bankalar.value = b
        kartlar.value = k
        kategoriler.value = kat
        turler.value = t
        saglayicilar.value = s
        gelirTurleri.value = g
      })
      .finally(() => {
        yukleniyor = null
      })

    return yukleniyor
  }

  /** Yalnız kategorileri (kullanım sayıları) yeniler; kategori verince çağrılır */
  async function kategorileriYenile(): Promise<void> {
    kategoriler.value = await aileApi.kategoriler()
  }

  /** Oturum kapanınca / hane değişince */
  function bosalt(): void {
    hane.value = null
  }

  /** Kategoriler en çok kullanılandan başlayarak (eşitlikte ayarlardaki sıra) */
  const kategorilerKullanima = computed(() =>
    [...kategoriler.value].sort(
      (a, b) =>
        (b.kullanim ?? 0) - (a.kullanim ?? 0) || a.sira - b.sira || a.ad.localeCompare(b.ad, 'tr'),
    ),
  )
  /** Sık kullanılan 4 kategori (fatura kategorisi otomatik atanır, hızlı seçeneklerde yer almaz) */
  const sikKategoriler = computed(() =>
    kategorilerKullanima.value.filter((k) => !k.fatura).slice(0, 4),
  )

  const uyeler = computed(() => hane.value?.uyeler ?? [])
  const aktifUyeler = computed(() => uyeler.value.filter((u) => u.aktif))
  const uye = (id: number | null | undefined) => uyeler.value.find((u) => u.id === id)
  const uyeAdi = (id: number | null | undefined) => uye(id)?.ad ?? ''
  const kartAdi = (id: number | null | undefined) =>
    kartlar.value.find((k) => k.id === id)?.ad ?? ''
  const kart = (id: number | null | undefined) => kartlar.value.find((k) => k.id === id)
  const bankaAdi = (id: number | null | undefined) =>
    bankalar.value.find((b) => b.id === id)?.ad ?? ''
  const kategori = (id: number | null | undefined) => kategoriler.value.find((k) => k.id === id)
  const saglayici = (id: number | null | undefined) => saglayicilar.value.find((s) => s.id === id)
  const tur = (id: number | null | undefined) => turler.value.find((t) => t.id === id)
  const gelirTuruAdi = (id: number | null | undefined) =>
    gelirTurleri.value.find((t) => t.id === id)?.ad ?? ''

  return {
    hane,
    bankalar,
    kartlar,
    kategoriler,
    turler,
    saglayicilar,
    gelirTurleri,
    uyeler,
    aktifUyeler,
    yukle,
    kategorileriYenile,
    kategorilerKullanima,
    sikKategoriler,
    bosalt,
    uye,
    uyeAdi,
    kartAdi,
    kart,
    bankaAdi,
    kategori,
    saglayici,
    tur,
    gelirTuruAdi,
  }
})
