import { http } from './http'

export type VarlikTuru = 'altin' | 'doviz' | 'hisse' | 'fon' | 'kripto'
export type IslemTuru = 'alis' | 'satis' | 'mevcut'
export type HesapTuru = 'araci_kurum' | 'banka' | 'kasa' | 'ev' | 'kripto' | 'diger'

export interface Kullanici {
  id: number
  ad: string
  soyad: string
  ad_soyad: string
  email: string
  yonetici: boolean
}

export interface FiyatOzeti {
  fiyat: number
  alis: number | null
  satis: number | null
  degisim_yuzde: number | null
  elle: boolean
  zaman: string | null
}

export interface Varlik {
  id: number
  tur: VarlikTuru
  kod: string
  ad: string
  birim: string
  para_birimi: string
  kaynak: string
  gecikmeli: boolean
  fiyat: FiyatOzeti | null
  izleniyor?: boolean
}

export interface Toplam {
  deger: number
  maliyet: number
  kz: number
  kz_yuzde: number | null
  gunluk_degisim: number
  gerceklesen_kz: number
  fiyatsiz: number
  maliyeti_eksik: boolean
}

export interface HesapOzeti extends Toplam {
  id: number
  ad: string
  tur: HesapTuru
  renk: string | null
  sira: number
  not: string | null
  varlik_sayisi: number
}

export interface Pozisyon {
  hesap_id: number | null
  varlik: Varlik
  miktar: number
  maliyeti_bilinmeyen_miktar: number
  ortalama_maliyet: number | null
  maliyet: number | null
  fiyat_tl: number | null
  deger: number | null
  kz: number | null
  kz_yuzde: number | null
  gunluk_degisim: number | null
  gerceklesen_kz: number | null
  gerceklesen_kz_eksik: boolean
  hesap_sayisi?: number
  hesap_ad?: string
}

export interface Ozet {
  usd_kuru: number | null
  toplam: Toplam
  hesaplar: HesapOzeti[]
  dagilim: { tur: VarlikTuru; etiket: string; deger: number }[]
  varliklar: Pozisyon[]
  pozisyonlar: Pozisyon[]
  son_guncelleme: string | null
}

export interface Islem {
  id: number
  hesap_id: number
  varlik_id: number
  tur: IslemTuru
  miktar: number
  birim_fiyat: number | null
  komisyon: number
  tutar: number | null
  tarih: string
  not: string | null
  varlik?: Varlik
  hesap_ad?: string
}

export interface IslemGirdisi {
  hesap_id: number | null
  varlik_id: number | null
  tur: IslemTuru
  miktar: number | null
  birim_fiyat: number | null
  komisyon: number | null
  tarih: string
  not: string | null
}

export interface HesapGirdisi {
  ad: string
  tur: HesapTuru
  renk: string | null
  not: string | null
}

export interface Secenek<T extends string = string> {
  deger: T
  etiket: string
}

export interface KaynakDurumu {
  kaynak: string
  ad: string
  varlik_sayisi: number
  son_deneme: string | null
  son_basari: string | null
  son_hata: string | null
  son_hata_zamani: string | null
  art_arda_hata: number
  guncellenen: number
}

export interface YonetimKullanicisi extends Kullanici {
  aktif: boolean
  son_giris: string | null
}

export const finansApi = {
  ben: () => http.get<{ kullanici: Kullanici }>('/ben').then((r) => r.data.kullanici),
  giris: (email: string, sifre: string, hatirla: boolean) =>
    http
      .post<{ kullanici: Kullanici }>('/giris', { email, sifre, hatirla })
      .then((r) => r.data.kullanici),
  cikis: () => http.post('/cikis'),
  sifre: (veri: { mevcut_sifre: string; yeni_sifre: string; yeni_sifre_confirmation: string }) =>
    http.put<{ message: string }>('/ben/sifre', veri),

  ozet: (hesapId: number | null) =>
    http.get<Ozet>('/ozet', { params: hesapId ? { hesap_id: hesapId } : {} }).then((r) => r.data),

  hesaplar: () =>
    http
      .get<{ hesaplar: HesapOzeti[]; turler: Secenek<HesapTuru>[] }>('/hesaplar')
      .then((r) => r.data),
  hesap: (id: number) =>
    http
      .get<{ hesap: HesapOzeti; pozisyonlar: Pozisyon[]; islemler: Islem[] }>(`/hesaplar/${id}`)
      .then((r) => r.data),
  hesapEkle: (veri: HesapGirdisi) => http.post('/hesaplar', veri),
  hesapGuncelle: (id: number, veri: HesapGirdisi) => http.put(`/hesaplar/${id}`, veri),
  hesapSil: (id: number) => http.delete(`/hesaplar/${id}`),
  hesapSirala: (ids: number[]) => http.put('/hesaplar/sira', { ids }),

  islemler: (filtre: { hesap_id?: number; varlik_id?: number }) =>
    http.get<{ islemler: Islem[] }>('/islemler', { params: filtre }).then((r) => r.data.islemler),
  islemEkle: (veri: IslemGirdisi) => http.post('/islemler', veri),
  islemGuncelle: (id: number, veri: IslemGirdisi) => http.put(`/islemler/${id}`, veri),
  islemSil: (id: number) => http.delete(`/islemler/${id}`),

  varliklar: (filtre: { tur?: VarlikTuru; q?: string } = {}) =>
    http
      .get<{ varliklar: Varlik[]; turler: Secenek<VarlikTuru>[] }>('/varliklar', { params: filtre })
      .then((r) => r.data),
  varlik: (id: number) =>
    http
      .get<{
        varlik: Varlik & {
          onceki_kapanis: number | null
          tl_fiyat: number | null
          izleniyor: boolean
        }
        toplam: Pozisyon | null
        pozisyonlar: Pozisyon[]
      }>(`/varliklar/${id}`)
      .then((r) => r.data),
  gecmis: (id: number, aralik: string) =>
    http
      .get<{ gecmis: { tarih: string; kapanis: number }[] }>(`/varliklar/${id}/gecmis`, {
        params: { aralik },
      })
      .then((r) => r.data.gecmis),
  varlikEkle: (veri: { tur: VarlikTuru; kod: string; ad?: string | null }) =>
    http.post<{ varlik: Varlik; message: string }>('/varliklar', veri).then((r) => r.data),
  elleFiyat: (id: number, fiyat: number) => http.put(`/varliklar/${id}/fiyat`, { fiyat }),

  izleme: () => http.get<{ varliklar: Varlik[] }>('/izleme').then((r) => r.data.varliklar),
  izlemeEkle: (varlikId: number) => http.post('/izleme', { varlik_id: varlikId }),
  izlemeCikar: (varlikId: number) => http.delete(`/izleme/${varlikId}`),
  izlemeSirala: (varlikIdleri: number[]) =>
    http.put('/izleme/sira', { varlik_idleri: varlikIdleri }),

  kullanicilar: () =>
    http
      .get<{ kullanicilar: YonetimKullanicisi[] }>('/yonetim/kullanicilar')
      .then((r) => r.data.kullanicilar),
  kullaniciEkle: (veri: {
    ad: string
    soyad: string
    email: string
    sifre: string
    yonetici: boolean
  }) => http.post('/yonetim/kullanicilar', veri),
  kullaniciGuncelle: (
    id: number,
    veri: Partial<{ ad: string; soyad: string; aktif: boolean; yonetici: boolean; sifre: string }>,
  ) => http.put(`/yonetim/kullanicilar/${id}`, veri),
  kaynaklar: () =>
    http.get<{ kaynaklar: KaynakDurumu[] }>('/yonetim/kaynaklar').then((r) => r.data.kaynaklar),
  kaynaklariGuncelle: () =>
    http
      .post<{ rapor: Record<string, number | string>; message: string }>(
        '/yonetim/kaynaklar/guncelle',
      )
      .then((r) => r.data),
}

export const VARLIK_TURLERI: Record<VarlikTuru, { etiket: string; ikon: string; renk: string }> = {
  altin: { etiket: 'Altın / gümüş', ikon: 'pi pi-star', renk: '--p-amber-500' },
  doviz: { etiket: 'Döviz', ikon: 'pi pi-dollar', renk: '--p-emerald-500' },
  hisse: { etiket: 'Hisse senedi', ikon: 'pi pi-chart-line', renk: '--p-sky-500' },
  fon: { etiket: 'Yatırım fonu', ikon: 'pi pi-briefcase', renk: '--p-violet-500' },
  kripto: { etiket: 'Kripto', ikon: 'pi pi-bitcoin', renk: '--p-orange-500' },
}

export const HESAP_TURLERI: Record<HesapTuru, { etiket: string; ikon: string }> = {
  araci_kurum: { etiket: 'Aracı kurum / yatırım hesabı', ikon: 'pi pi-chart-bar' },
  banka: { etiket: 'Banka hesabı', ikon: 'pi pi-building-columns' },
  kasa: { etiket: 'Banka kasası', ikon: 'pi pi-lock' },
  ev: { etiket: 'Evde', ikon: 'pi pi-home' },
  kripto: { etiket: 'Kripto borsası / cüzdan', ikon: 'pi pi-wallet' },
  diger: { etiket: 'Diğer', ikon: 'pi pi-box' },
}

export const ISLEM_TURLERI: Record<IslemTuru, string> = {
  alis: 'Alış',
  satis: 'Satış',
  mevcut: 'Elde olan',
}

/** Hesap kartı renkleri (Tailwind / PrimeVue paleti) */
export const HESAP_RENKLERI = [
  'teal',
  'sky',
  'violet',
  'amber',
  'rose',
  'emerald',
  'indigo',
  'slate',
] as const
