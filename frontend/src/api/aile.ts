import { http } from './http'

/**
 * Aile finansı (Bütçe): hane, gelirler, kartlar, ekstreler, faturalar. Toplamlar sunucuda (ButceRaporu) hesaplanır;
 * arayüz yalnızca gösterir. Tutarlar TL (sayı), dönemler "YYYY-AA".
 */

export type GelirTuru =
  'maas' | 'kira' | 'serbest' | 'ek_is' | 'prim' | 'faiz_temettu' | 'emekli' | 'diger'
export type GelirDuzeni = 'duzenli' | 'duzensiz' | 'tek_seferlik'
export type GelirSikligi = 'aylik' | 'iki_aylik' | 'uc_aylik' | 'alti_aylik' | 'yillik'
export type KartIslemTuru = 'harcama' | 'iade' | 'odeme' | 'transfer' | 'ucret' | 'nakit_avans'
export type KartIslemDurumu = 'taslak' | 'onayli' | 'atlandi'
export type FaturaDurumu = 'bekliyor' | 'odendi'
export type EslesmeGuveni = 'yuksek' | 'orta' | 'belirsiz' | 'dusuk' | 'kullanici'

export const GELIR_TURLERI: Record<GelirTuru, string> = {
  maas: 'Maaş',
  kira: 'Kira geliri',
  serbest: 'Serbest çalışma',
  ek_is: 'Ek iş',
  prim: 'Prim / ikramiye',
  faiz_temettu: 'Faiz / temettü',
  emekli: 'Emekli maaşı',
  diger: 'Diğer',
}

export const GELIR_DUZENLERI: Record<GelirDuzeni, string> = {
  duzenli: 'Düzenli',
  duzensiz: 'Düzensiz',
  tek_seferlik: 'Tek seferlik',
}

export const GELIR_SIKLIKLARI: Record<GelirSikligi, string> = {
  aylik: 'Her ay',
  iki_aylik: '2 ayda bir',
  uc_aylik: '3 ayda bir',
  alti_aylik: '6 ayda bir',
  yillik: 'Yılda bir',
}

/** `gider`: gider toplamına etkisi (+ ekler, − düşer, 0 sayılmaz) */
export const KART_ISLEM_TURLERI: Record<KartIslemTuru, { etiket: string; gider: 1 | -1 | 0 }> = {
  harcama: { etiket: 'Harcama', gider: 1 },
  iade: { etiket: 'İade / iptal', gider: -1 },
  odeme: { etiket: 'Kart borcu ödemesi', gider: 0 },
  transfer: { etiket: 'Transfer', gider: 0 },
  ucret: { etiket: 'Faiz / ücret', gider: 1 },
  nakit_avans: { etiket: 'Nakit avans', gider: 0 },
}

export const GUVEN_ETIKETLERI: Record<EslesmeGuveni, { etiket: string; renk: string }> = {
  yuksek: { etiket: 'Güvenilir', renk: 'bg-emerald-100 text-emerald-800' },
  orta: { etiket: 'Orta', renk: 'bg-sky-100 text-sky-800' },
  belirsiz: { etiket: 'Belirsiz', renk: 'bg-amber-100 text-amber-800' },
  dusuk: { etiket: 'Zayıf', renk: 'bg-surface-100 text-surface-700' },
  kullanici: { etiket: 'Sizin seçiminiz', renk: 'bg-violet-100 text-violet-800' },
}

export interface HaneUyesi {
  id: number
  ad: string
  renk: string | null
  user_id: number | null
  /** Girişi olan üyenin profil resmi */
  resim: string | null
  aktif: boolean
  sira: number
}

export interface HaneBilgisi {
  hane: { id: number; ad: string }
  uyeler: HaneUyesi[]
  ben_uye_id: number | null
}

export interface GelirKaynagi {
  id: number
  uye_id: number
  tur: GelirTuru
  ad: string
  duzen: GelirDuzeni
  siklik: GelirSikligi | null
  beklenen_tutar: number | null
  para_birimi: string
  gun: number | null
  baslangic: string | null
  bitis: string | null
  aktif: boolean
  not: string | null
}

export interface Gelir {
  id: number
  uye_id: number
  gelir_kaynagi_id: number | null
  tur: GelirTuru
  duzen: GelirDuzeni
  aciklama: string | null
  tutar: number
  para_birimi: string
  tl_tutar: number
  kur: number | null
  tarih: string
  donem: string
  not: string | null
}

export interface GelirGirdisi {
  uye_id: number | null
  gelir_kaynagi_id: number | null
  tur: GelirTuru
  duzen: GelirDuzeni
  aciklama: string | null
  tutar: number | null
  para_birimi: string
  tl_tutar: number | null
  tarih: string
  donem: string
  not: string | null
}

/** Kaynak formu: kişi seçilmemiş olabilir (sunucu doğrular) */
export type GelirKaynagiGirdisi = Omit<Partial<GelirKaynagi>, 'uye_id'> & { uye_id: number | null }

export interface BeklenenGelir {
  kaynak: GelirKaynagi
  donem: string
  tarih: string | null
}

interface GelirToplamlari {
  toplam: number
  duzenli: number
  duzensiz: number
  tek_seferlik: number
}

export interface GelirOzeti extends GelirToplamlari {
  donem: string
  kisiler: (GelirToplamlari & {
    uye: HaneUyesi
    turler: { tur: GelirTuru; etiket: string; toplam: number }[]
    beklenen_sayisi: number
  })[]
  beklenenler: BeklenenGelir[]
}

export interface Banka {
  id: number
  ad: string
  renk: string | null
}

export interface Kart {
  id: number
  ad: string
  banka_id: number
  sahip_uye_id: number
  kesim_gunu: number | null
  son_odeme_gunu: number | null
  /** Ekstreden okunamazsa asgari ödeme = dönem borcu × oran (%) */
  asgari_oran: number
  renk: string | null
  aktif: boolean
}

export interface Kategori {
  id: number
  ad: string
  ikon: string | null
  renk: string | null
  fatura: boolean
  sira: number
  kurallar?: { id: number; desen: string }[]
}

export interface Ekstre {
  id: number
  kart_id: number
  durum: 'taslak' | 'onaylandi'
  donem: string | null
  donem_etiketi: string | null
  donem_baslangic: string | null
  kesim_tarihi: string | null
  son_odeme_tarihi: string | null
  donem_borcu: number | null
  asgari_odeme: number | null
  /** alan => pdf | kullanici | hesaplandi (asgari ödeme: dönem borcu × kart oranı) */
  alan_kaynaklari: Record<string, 'pdf' | 'kullanici' | 'hesaplandi'>
  okunamayan: string[]
  odendi: boolean
  dosya_var: boolean
  dosya_adi: string | null
  sayfa_sayisi: number | null
  onaylandi_at: string | null
  olusturuldu: string | null
  satir_sayisi?: number
  banka_profili?: string | null
  /** PDF okumasının mutabakatı: borç denklemi ve ekstre özet toplamları */
  denetim?: EkstreDenetimi | null
  /** Satırlardaki "kalan taksit" tutarlarının toplamı (gelecek dönem yükümlülüğü) */
  kalan_taksit?: number | null
}

export interface FaturaOnerisi {
  guven: EslesmeGuveni
  puan: number
  saglayici_id: number | null
  fatura_turu_id: number | null
  adaylar: number[]
  gerekce: string[]
  hizmet_donemi: string | null
  donem_kaynagi: 'aciklama' | 'kullanici' | 'kesim' | null
  otomatik: boolean
  secildi: boolean
}

export interface KartIslemi {
  id: number
  kart_id: number
  ekstre_id: number | null
  sira: number
  durum: KartIslemDurumu
  islem_tarihi: string | null
  aciklama: string
  tutar: number | null
  tur: KartIslemTuru
  taksit_no: number | null
  taksit_sayisi: number | null
  taksit_toplam: number | null
  taksit_kalan?: number | null
  iade_islem_id: number | null
  kategori_id: number | null
  rapor_donemi: string | null
  tekrar_islem_id: number | null
  ham_metin: string | null
  sayfa: number | null
  okuma: { alanlar: string[]; uyarilar: string[]; tutar_adaylari: string[] }
  fatura_onerisi: FaturaOnerisi | null
  ekstre_donemi?: string | null
  tekrar?: { id: number; yer: string } | null
}

export interface KartIslemGirdisi {
  kart_id: number | null
  islem_tarihi: string
  aciklama: string
  tutar: number | null
  tur: KartIslemTuru
  taksit_no: number | null
  taksit_sayisi: number | null
  taksit_toplam: number | null
  taksit_kalan?: number | null
  kategori_id: number | null
  iade_islem_id: number | null
}

export interface FaturaTuru {
  id: number
  ad: string
  ikon: string | null
  sistem: boolean
  anahtar_kelimeler: string[]
}

export interface Saglayici {
  id: number
  ad: string
  fatura_turu_id: number
  otomatik_kayit: boolean
  aktif: boolean
  desenler: { id: number; desen: string }[]
}

export interface Fatura {
  id: number
  saglayici_id: number
  fatura_turu_id: number
  hizmet_donemi: string | null
  hizmet_donemi_etiketi: string | null
  donem_kaynagi: 'aciklama' | 'kullanici' | 'kesim' | null
  tutar: number
  son_odeme_tarihi: string | null
  durum: FaturaDurumu
  gecikmis: boolean
  odeme_tarihi: string | null
  kart_islemi_id: number | null
  eslesme: { guven: EslesmeGuveni | null; gerekce: string[]; otomatik: boolean } | null
  kaynak: 'elle' | 'ekstre'
  not: string | null
  saglayici_ad?: string
  kart_islemi?: {
    id: number
    kart_id: number
    islem_tarihi: string | null
    aciklama: string
    tutar: number
  } | null
  gun?: number | null
}

export interface FaturaGirdisi {
  saglayici_id: number | null
  hizmet_donemi: string | null
  tutar: number | null
  son_odeme_tarihi: string | null
  durum: FaturaDurumu
  odeme_tarihi: string | null
  not: string | null
}

export interface AylikOzet {
  donem: string
  etiket: string
  gelir: GelirOzeti
  gider: {
    toplam: number
    kart_harcamasi: number
    kartsiz_faturalar: number
    kategoriler: {
      kategori_id: number | null
      ad: string
      renk: string | null
      ikon: string | null
      toplam: number
    }[]
    kartlar: { kart_id: number; ad: string; sahip_uye_id: number | null; toplam: number }[]
    haric: { tur: KartIslemTuru; etiket: string; toplam: number }[]
    taksitler: {
      islem_id: number
      aciklama: string
      kart_id: number
      taksit_no: number | null
      taksit_sayisi: number | null
      aya_dusen: number
      toplam_borc: number
      toplam_tahmini: boolean
    }[]
  }
  faturalar: {
    toplam: number
    odenen: number
    bekleyen: number
    kartla_odenen: number
    turler: {
      fatura_turu_id: number
      ad: string
      ikon: string | null
      toplam: number
      adet: number
    }[]
    donemi_bilinmeyen: number
  }
  fark: number
  uyarilar: {
    gecikmis_faturalar: Fatura[]
    bekleyen_faturalar: Fatura[]
    son_odemeler: (Ekstre & { kart_ad: string; gun: number })[]
  }
}

export interface KartIslemSuzgeci {
  ay?: string | null
  donem_turu?: 'rapor' | 'islem' | 'ekstre'
  uye_id?: number | null
  banka_id?: number | null
  kart_id?: number | null
  kategori_id?: number | 'yok' | null
  tur?: KartIslemTuru | null
  ekstre_id?: number | null
  durum?: KartIslemDurumu | null
}

/** Boş süzgeç alanları istekten çıkar */
function temiz<T extends object>(nesne: T): Partial<T> {
  return Object.fromEntries(
    Object.entries(nesne).filter(([, d]) => d !== null && d !== undefined && d !== ''),
  ) as Partial<T>
}

export const aileApi = {
  hane: () => http.get<HaneBilgisi>('/aile/hane').then((r) => r.data),
  haneAdi: (ad: string) => http.put('/aile/hane', { ad }),
  uyeEkle: (veri: { ad: string; renk?: string | null }) =>
    http.post<{ uye: HaneUyesi }>('/aile/uyeler', veri).then((r) => r.data.uye),
  uyeGuncelle: (id: number, veri: { ad: string; renk?: string | null; aktif?: boolean }) =>
    http.put<{ uye: HaneUyesi }>(`/aile/uyeler/${id}`, veri).then((r) => r.data.uye),
  uyeSil: (id: number) =>
    http.delete<{ message: string }>(`/aile/uyeler/${id}`).then((r) => r.data),

  ozet: (ay: string) => http.get<AylikOzet>('/aile/ozet', { params: { ay } }).then((r) => r.data),

  gelirler: (suzgec: {
    donem?: string
    yil?: number
    uye_id?: number | null
    tur?: GelirTuru | null
  }) =>
    http
      .get<{ gelirler: Gelir[]; ozet: GelirOzeti | null }>('/aile/gelirler', {
        params: temiz(suzgec),
      })
      .then((r) => r.data),
  gelirEkle: (veri: GelirGirdisi) =>
    http.post<{ gelir: Gelir }>('/aile/gelirler', veri).then((r) => r.data),
  gelirGuncelle: (id: number, veri: GelirGirdisi) =>
    http.put<{ gelir: Gelir }>(`/aile/gelirler/${id}`, veri).then((r) => r.data),
  gelirSil: (id: number) => http.delete(`/aile/gelirler/${id}`),
  kaynaklar: () =>
    http.get<{ kaynaklar: GelirKaynagi[] }>('/aile/gelir-kaynaklari').then((r) => r.data.kaynaklar),
  kaynakEkle: (veri: GelirKaynagiGirdisi) =>
    http.post<{ kaynak: GelirKaynagi }>('/aile/gelir-kaynaklari', veri).then((r) => r.data),
  kaynakGuncelle: (id: number, veri: GelirKaynagiGirdisi) =>
    http.put<{ kaynak: GelirKaynagi }>(`/aile/gelir-kaynaklari/${id}`, veri).then((r) => r.data),
  kaynakSil: (id: number) => http.delete(`/aile/gelir-kaynaklari/${id}`),

  bankalar: () => http.get<{ bankalar: Banka[] }>('/aile/bankalar').then((r) => r.data.bankalar),
  bankaEkle: (ad: string) =>
    http.post<{ banka: Banka }>('/aile/bankalar', { ad }).then((r) => r.data.banka),
  bankaGuncelle: (id: number, ad: string) => http.put(`/aile/bankalar/${id}`, { ad }),
  bankaSil: (id: number) => http.delete(`/aile/bankalar/${id}`),
  kartlar: () => http.get<{ kartlar: Kart[] }>('/aile/kartlar').then((r) => r.data.kartlar),
  kart: (id: number) =>
    http.get<{ kart: Kart; ekstreler: Ekstre[] }>(`/aile/kartlar/${id}`).then((r) => r.data),
  kartEkle: (veri: Partial<Kart>) =>
    http.post<{ kart: Kart }>('/aile/kartlar', veri).then((r) => r.data),
  kartGuncelle: (id: number, veri: Partial<Kart>) =>
    http.put<{ kart: Kart }>(`/aile/kartlar/${id}`, veri).then((r) => r.data),
  kartSil: (id: number) => http.delete(`/aile/kartlar/${id}`),

  kategoriler: () =>
    http.get<{ kategoriler: Kategori[] }>('/aile/kategoriler').then((r) => r.data.kategoriler),
  kategoriEkle: (ad: string) =>
    http.post<{ kategori: Kategori }>('/aile/kategoriler', { ad }).then((r) => r.data.kategori),
  kategoriSil: (id: number) => http.delete(`/aile/kategoriler/${id}`),
  kuralEkle: (kategoriId: number, desen: string) =>
    http.post(`/aile/kategoriler/${kategoriId}/kurallar`, { desen }),
  kuralSil: (id: number) => http.delete(`/aile/kategori-kurallari/${id}`),

  kartIslemleri: (suzgec: KartIslemSuzgeci) =>
    http
      .get<{
        islemler: KartIslemi[]
        toplam: { gider: number; gidere_girmeyen: number; adet: number }
      }>('/aile/kart-islemleri', { params: temiz(suzgec) })
      .then((r) => r.data),
  kartIslemiEkle: (veri: KartIslemGirdisi) =>
    http
      .post<{ islem: KartIslemi; message: string }>('/aile/kart-islemleri', veri)
      .then((r) => r.data),
  kartIslemiGuncelle: (id: number, veri: KartIslemGirdisi) =>
    http.put<{ islem: KartIslemi }>(`/aile/kart-islemleri/${id}`, veri).then((r) => r.data),
  kartIslemiSil: (id: number) =>
    http.delete<{ message: string }>(`/aile/kart-islemleri/${id}`).then((r) => r.data),

  ekstreler: (kartId?: number) =>
    http
      .get<{ ekstreler: Ekstre[] }>('/aile/ekstreler', { params: temiz({ kart_id: kartId }) })
      .then((r) => r.data.ekstreler),
  ekstreYukle: (kartId: number, dosya: File) => {
    const veri = new FormData()
    veri.append('kart_id', String(kartId))
    veri.append('dosya', dosya)

    return http
      .post<{
        ekstre: Ekstre
        ozet: { satir: number; okunamayan_satir: number; tekrar: number }
        message: string
      }>('/aile/ekstreler', veri)
      .then((r) => r.data)
  },
  ekstre: (id: number) =>
    http
      .get<{ ekstre: Ekstre; kart: Kart; satirlar: KartIslemi[] }>(`/aile/ekstreler/${id}`)
      .then((r) => r.data),
  ekstreGuncelle: (id: number, veri: Partial<Record<string, string | number | boolean | null>>) =>
    http.put<{ ekstre: Ekstre }>(`/aile/ekstreler/${id}`, veri).then((r) => r.data),
  ekstreSatiri: (ekstreId: number, islemId: number, veri: Record<string, unknown>) =>
    http
      .put<{ islem: KartIslemi }>(`/aile/ekstreler/${ekstreId}/satirlar/${islemId}`, veri)
      .then((r) => r.data),
  ekstreOnayla: (id: number) =>
    http
      .post<{
        ekstre: Ekstre
        rapor: { eklenen: number; atlanan: number; tekrar: number; fatura: number }
        message: string
      }>(`/aile/ekstreler/${id}/onayla`)
      .then((r) => r.data),
  ekstreSil: (id: number) => http.delete(`/aile/ekstreler/${id}`),
  /** PDF (yalnızca hane; oturum çereziyle açılır) */
  ekstreDosyaAdresi: (id: number, sayfa?: number | null) =>
    `/api/v1/aile/ekstreler/${id}/dosya${sayfa ? `#page=${sayfa}` : ''}`,

  faturaTurleri: () =>
    http.get<{ turler: FaturaTuru[] }>('/aile/fatura-turleri').then((r) => r.data.turler),
  faturaTuruEkle: (ad: string) => http.post('/aile/fatura-turleri', { ad }),
  faturaTuruSil: (id: number) => http.delete(`/aile/fatura-turleri/${id}`),
  saglayicilar: () =>
    http.get<{ saglayicilar: Saglayici[] }>('/aile/saglayicilar').then((r) => r.data.saglayicilar),
  saglayiciEkle: (veri: {
    ad: string
    fatura_turu_id: number
    otomatik_kayit: boolean
    desenler: string[]
  }) =>
    http.post<{ saglayici: Saglayici }>('/aile/saglayicilar', veri).then((r) => r.data.saglayici),
  saglayiciGuncelle: (
    id: number,
    veri: { ad: string; fatura_turu_id: number; otomatik_kayit?: boolean; aktif?: boolean },
  ) =>
    http
      .put<{ saglayici: Saglayici }>(`/aile/saglayicilar/${id}`, veri)
      .then((r) => r.data.saglayici),
  saglayiciSil: (id: number) => http.delete(`/aile/saglayicilar/${id}`),
  desenEkle: (saglayiciId: number, desen: string) =>
    http.post(`/aile/saglayicilar/${saglayiciId}/desenler`, { desen }),
  desenSil: (id: number) => http.delete(`/aile/saglayici-desenleri/${id}`),

  faturalar: (suzgec: {
    donem?: string | null
    yil?: number | null
    bilinmeyen?: boolean
    saglayici_id?: number | null
    fatura_turu_id?: number | null
    durum?: FaturaDurumu | null
    kart_id?: number | null
    banka_id?: number | null
  }) =>
    http
      .get<{ faturalar: Fatura[]; toplam: number }>('/aile/faturalar', {
        params: temiz({ ...suzgec, bilinmeyen: suzgec.bilinmeyen ? 1 : null }),
      })
      .then((r) => r.data),
  faturaEkle: (veri: FaturaGirdisi) =>
    http.post<{ fatura: Fatura }>('/aile/faturalar', veri).then((r) => r.data),
  faturaGuncelle: (id: number, veri: FaturaGirdisi) =>
    http.put<{ fatura: Fatura }>(`/aile/faturalar/${id}`, veri).then((r) => r.data),
  faturaSil: (id: number) => http.delete(`/aile/faturalar/${id}`),
  faturaBagla: (id: number, kartIslemiId: number) =>
    http.post<{ fatura: Fatura }>(`/aile/faturalar/${id}/bagla`, { kart_islemi_id: kartIslemiId }),
  faturaCoz: (id: number) => http.post<{ fatura: Fatura }>(`/aile/faturalar/${id}/coz`),
}

export interface EkstreKontrolu {
  ad: string
  etiket: string
  durum: 'tutuyor' | 'tutmuyor' | 'kontrol_edilemedi'
  beklenen?: string
  bulunan?: string
  fark?: string
  neden?: string
}

export interface EkstreDenetimi {
  durum: 'tutuyor' | 'tutmuyor' | 'kontrol_edilemedi'
  /** İlk uyuşmayan kontrolün farkı (TL, metin) */
  fark: string | null
  kontroller: EkstreKontrolu[]
  teshis?: EkstreTeshisi | null
}

/** Tanınan banka profilleri; yoksa "genel" */
export const BANKA_PROFILLERI: Record<string, string> = {
  garanti: 'Garanti BBVA',
  yapikredi: 'Yapı Kredi',
  enpara: 'Enpara',
  genel: 'Tanınmayan banka',
}

/** "Neden okunamadı?" teşhisi (0 satır ya da banka tanınmadığında); örnek satırlarda rakamlar `#` */
export interface EkstreTeshisi {
  banka_taninmadi: boolean
  sayfa_sayisi: number
  metin_satiri: number
  tarihli_satir: number
  okunan_satir: number
  okunmayan_tarihli_satir: number
  nedenler: string[]
  ornekler: string[]
}
