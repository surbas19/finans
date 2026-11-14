import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import {
  aileApi,
  type Banka,
  type FaturaTuru,
  type HaneBilgisi,
  type Kart,
  type Kategori,
  type Saglayici,
} from '@/api/aile'

/**
 * Bütçe sayfalarının ortak tanımları: hane ve üyeler, bankalar, kartlar, kategoriler, fatura türleri ve
 * sağlayıcılar. Bir kez yüklenir; değişince `yukle(true)`.
 */
export const useAileStore = defineStore('aile', () => {
  const hane = ref<HaneBilgisi | null>(null)
  const bankalar = ref<Banka[]>([])
  const kartlar = ref<Kart[]>([])
  const kategoriler = ref<Kategori[]>([])
  const turler = ref<FaturaTuru[]>([])
  const saglayicilar = ref<Saglayici[]>([])
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
    ])
      .then(([h, b, k, kat, t, s]) => {
        hane.value = h
        bankalar.value = b
        kartlar.value = k
        kategoriler.value = kat
        turler.value = t
        saglayicilar.value = s
      })
      .finally(() => {
        yukleniyor = null
      })

    return yukleniyor
  }

  /** Oturum kapanınca / hane değişince */
  function bosalt(): void {
    hane.value = null
  }

  const uyeler = computed(() => hane.value?.uyeler ?? [])
  const aktifUyeler = computed(() => uyeler.value.filter((u) => u.aktif))
  const uye = (id: number | null | undefined) => uyeler.value.find((u) => u.id === id)
  const uyeAdi = (id: number | null | undefined) => uye(id)?.ad ?? ''
  const kartAdi = (id: number | null | undefined) =>
    kartlar.value.find((k) => k.id === id)?.ad ?? ''
  const bankaAdi = (id: number | null | undefined) =>
    bankalar.value.find((b) => b.id === id)?.ad ?? ''
  const kategori = (id: number | null | undefined) => kategoriler.value.find((k) => k.id === id)
  const saglayici = (id: number | null | undefined) => saglayicilar.value.find((s) => s.id === id)
  const tur = (id: number | null | undefined) => turler.value.find((t) => t.id === id)

  return {
    hane,
    bankalar,
    kartlar,
    kategoriler,
    turler,
    saglayicilar,
    uyeler,
    aktifUyeler,
    yukle,
    bosalt,
    uye,
    uyeAdi,
    kartAdi,
    bankaAdi,
    kategori,
    saglayici,
    tur,
  }
})
