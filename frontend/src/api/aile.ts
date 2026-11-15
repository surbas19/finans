import { http } from './http'

/**
 * Aile finansı (Bütçe): hane, gelirler, kartlar, ekstreler, faturalar. Toplamlar sunucuda (ButceRaporu) hesaplanır;
 * arayüz yalnızca gösterir. Tutarlar TL (sayı), dönemler "YYYY-AA".
 */

export type GelirDuzeni = 'duzenli' | 'duzensiz' | 'tek_seferlik'
export type GelirSikligi = 'aylik' | 'iki_aylik' | 'uc_aylik' | 'alti_aylik' | 'yillik'
export type KartIslemTuru =
  'harcama' | 'iade' | 'odeme' | 'transfer' | 'ucret' | 'nakit_avans' | 'faiz' | 'vergi' | 'bonus'
export type KartIslemDurumu = 'taslak' | 'onayli' | 'atlandi'
export type FaturaDurumu = 'bekliyor' | 'odendi'
export type EslesmeGuveni = 'yuksek' | 'orta' | 'belirsiz' | 'dusuk' | 'kullanici'

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
  ucret: { etiket: 'Ücret / aidat', gider: 1 },
  faiz: { etiket: 'Faiz', gider: 1 },
  vergi: { etiket: 'Vergi (KKDF / BSMV)', gider: 1 },
  nakit_avans: { etiket: 'Nakit avans', gider: 0 },
  bonus: { etiket: 'Bonus / puanla ödenen', gider: 0 },
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
  /** Ekstre yükleme seçenekleri: yapay zekâ açık mı, taslağı aktarılabilir mi, yükleme sınırı */
  yetenekler?: { ai_acik: boolean; ai_aktarim: boolean; ekstre_max_mb: number }
}

/** Hanenin gelir türü (Maaş, Ek ders…); kullanıcı ekler */
export interface GelirTuru {
  id: number
  ad: string
  sira: number
}

export interface GelirKaynagi {
  id: number
  uye_id: number
  gelir_turu_id: number
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

/** Düzenli ödemenin tanımı (eğitim, bakıcı…); düzenli kaynaktan her dönem "beklenen" gider çıkar */
export interface GiderKaynagi {
  id: number
  uye_id: number | null
  kategori_id: number | null
  ad: string
  siklik: GelirSikligi
  beklenen_tutar: number | null
  gun: number | null
  baslangic: string | null
  bitis: string | null
  aktif: boolean
  not: string | null
}

export type GiderKaynagiGirdisi = Omit<Partial<GiderKaynagi>, 'id'> & { ad: string }

/** Gerçekleşen kartsız gider (düzenli ödeme ya da tek seferlik) */
export interface DuzenliGider {
  id: number
  gider_kaynagi_id: number | null
  kategori_id: number | null
  aciklama: string
  tutar: number
  tarih: string
  donem: string
  not: string | null
}

export interface DuzenliGiderGirdisi {
  gider_kaynagi_id: number | null
  kategori_id: number | null
  aciklama: string
  tutar: number | null
  tarih: string
  donem: string
  not: string | null
}

export interface BeklenenGider {
  kaynak: GiderKaynagi
  donem: string
  tarih: string | null
}

/**
 * Bütçeden ayrılan tasarruf (gider sayılmaz): portföy hesabına alış olarak yazılır; gün alımında "kime verildi" notu
 * doluysa hiçbir hesaba yazılmaz (`hesaba_eklendi=false`), yalnız aylık tasarruf toplamına girer.
 */
export interface Tasarruf {
  id: number
  /** Kalıcı işlem numarası (T-12) */
  no: string
  uye_id: number | null
  hesap_id: number | null
  hesap_ad: string | null
  islem_id: number | null
  /** Bağlı portföy işleminin numarası (İ-34) */
  islem_no: string | null
  /** Bağlı portföy işlemi genel toplama dahil mi (işlem yoksa null) */
  islem_dahil: boolean | null
  hesaba_eklendi: boolean
  gun_id: number | null
  gun_ad: string | null
  varlik_id: number | null
  varlik_ad: string | null
  varlik_kod: string | null
  varlik_tur: string | null
  varlik_birim: string | null
  miktar: number | null
  /** Kaydın uygulamaya girildiği zaman (otomatik) */
  islem_zamani: string | null
  tarih: string
  donem: string
  tutar: number
  not: string | null
}

/** Altın / döviz günü şablonu: her ay `ay_gunu`nda `miktar` kadar alım */
export interface TasarrufGunu {
  id: number
  ad: string
  hesap_id: number
  hesap_ad: string | null
  varlik_id: number
  varlik_ad: string | null
  varlik_kod: string | null
  varlik_birim: string | null
  miktar: string
  ay_gunu: number
  baslangic: string
  ay_sayisi: number | null
  /** Sıranın bizde olduğu ay (YYYY-AA): o ay alım beklenmez */
  bizde_ay: string | null
  aktif: boolean
}

export interface TasarrufGunuGirdisi {
  ad: string
  hesap_id: number | null
  varlik_id: number | null
  miktar: number | null
  ay_gunu: number | null
  baslangic: string
  ay_sayisi: number | null
  bizde_ay: string | null
}

/** Ayın günü: bu ay alım yapıldı mı (yapildi) ya da bekliyor */
export interface AyGunu {
  gun: TasarrufGunu
  donem: string
  tarih: string
  durum: 'bekliyor' | 'yapildi' | 'sira_bizde'
  vadesi_geldi: boolean
  tasarruf_id: number | null
}

/** Bütçe → Tasarruf sekmesi: yılın ayları, oranlar (gelire göre %) ve ay kalemleri */
export interface TasarrufAyi {
  donem: string
  etiket: string
  gelir: number
  gider: number
  tasarruf: number
  kalan: number
  gider_orani: number | null
  tasarruf_orani: number | null
  kalan_orani: number | null
  kalemler: Tasarruf[]
}

export interface TasarrufOzeti {
  yil: number
  aylar: TasarrufAyi[]
  toplam: {
    gelir: number
    gider: number
    tasarruf: number
    kalan: number
    gider_orani: number | null
    tasarruf_orani: number | null
    kalan_orani: number | null
  }
}

export interface TasarrufGirdisi {
  gun_id?: number | null
  hesap_id: number | null
  varlik_id: number | null
  uye_id: number | null
  miktar: number | null
  birim_fiyat: number | null
  komisyon: number | null
  tarih: string
  not: string | null
}

export interface Gelir {
  id: number
  uye_id: number
  gelir_kaynagi_id: number | null
  gelir_turu_id: number
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
  gelir_turu_id: number | null
  duzen: GelirDuzeni
  aciklama: string | null
  tutar: number | null
  para_birimi: string
  tl_tutar: number | null
  tarih: string
  donem: string
  not: string | null
}

/** Kaynak formu: kişi / tür seçilmemiş olabilir (sunucu doğrular) */
export type GelirKaynagiGirdisi = Omit<Partial<GelirKaynagi>, 'uye_id' | 'gelir_turu_id'> & {
  uye_id: number | null
  gelir_turu_id: number | null
}

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
    turler: { gelir_turu_id: number; etiket: string; toplam: number }[]
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
  alan_kaynaklari: Record<string, 'pdf' | 'kullanici' | 'hesaplandi' | 'ai'>
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
  okuma: {
    alanlar: string[]
    uyarilar: string[]
    tutar_adaylari: string[]
    /** Yapay zekâ satırı şüpheli: "Kontrol ettim" denmeden onaylanmaz */
    inceleme?: boolean
    incelendi?: boolean
  }
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
    duzenli: { toplam: number; kalemler: DuzenliGider[] }
    beklenenler: BeklenenGider[]
    kategoriler: {
      kategori_id: number | null
      ad: string
      renk: string | null
      ikon: string | null
      toplam: number
      /** "Ekstredeki diğer": ekstre borcu ile satır toplamı arasındaki fark (devreden borç, ödeme, bonus…) */
      diger?: boolean
    }[]
    kartlar: {
      kart_id: number
      ad: string
      sahip_uye_id: number | null
      /** Kart gideri: kesim ayı bu ay olan onaylı ekstrelerin dönem borcu (+ ekstreye girmemiş elle satırlar) */
      toplam: number
      ekstre: { id: number; donem_borcu: number | null; son_odeme_tarihi: string | null } | null
    }[]
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
    /** Bu aydan sonra kalan taksitler, kart başına */
    sonraki_taksitler: {
      kart_id: number
      ad: string
      toplam: number
      tahmini: boolean
      kalem_sayisi: number
      kalemler: {
        islem_id: number
        aciklama: string
        kalan_adet: number
        aya_dusen: number
        kalan_toplam: number
        tahmini: boolean
      }[]
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
  tasarruf: { toplam: number; kalemler: Tasarruf[] }
  /** Altın / döviz günleri: bu ay beklenen ve yapılan alımlar (toplamlara girmez) */
  gunler: AyGunu[]
  fark: number
  /** Gelir − Gider − Tasarruf */
  kalan: number
  /** Verilen zekât (ödeme tarihinin ayı): yalnızca bilgi, gider / kalan hesabına girmez */
  zekat?: { verilen: number; adet: number }
  uyarilar: {
    gecikmis_faturalar: Fatura[]
    bekleyen_faturalar: Fatura[]
    son_odemeler: (Ekstre & { kart_ad: string; gun: number })[]
    zekat?: ZekatUyarisi[]
  }
}

// ---- Zekât ----

export const ZEKAT_UYARISI =
  'Bilgilendirme amaçlıdır, dini hüküm değildir; din görevlisine danışın.'

export type ZekatNisabOlcutu = 'altin' | 'gumus'
export type ZekatGunuTuru = 'hicri' | 'miladi'
export type ZekatBorcKaynagi = 'kart' | 'fatura' | 'elle'

export interface ZekatTurKurali {
  dahil: boolean
  /** Değerin zekâta giren yüzdesi (0-100) */
  yuzde: number
}

export interface ZekatAyari {
  nisab_olcutu: ZekatNisabOlcutu
  nisab_altin_gram: number
  nisab_gumus_gram: number
  /** Binde: 25 = %2,5 */
  oran_binde: number
  tur_kurallari: Record<string, ZekatTurKurali>
  borc_dus: boolean
  borc_kaynaklari: Record<ZekatBorcKaynagi, boolean>
  yalniz_gunde_nisab: boolean
  zekat_gunu_turu: ZekatGunuTuru
  hicri_ay: number
  hicri_gun: number
  miladi_ay: number
  miladi_gun: number
  sonraki_zekat_gunu: string | null
  hatirlatma_gun: number
}

export interface ZekatGunu {
  tarih: string
  /** Hicrî günler her zaman tahminîdir (takvim yöntemi ±1-2 gün sapabilir) */
  tahmini: boolean
  yontem: 'intl' | 'yedek' | 'miladi'
  gun_kaldi: number
}

export interface ZekatHesapAyari {
  id: number
  ad: string
  sahip_ad: string | null
  zekata_dahil: boolean
  zekat_notu: string | null
}

export interface ZekatAyarYaniti {
  ayar: ZekatAyari
  zekat_gunu: ZekatGunu
  turler: { deger: string; etiket: string }[]
  hesaplar: ZekatHesapAyari[]
  varsayilanlar: { nisab_altin_gram: number; nisab_gumus_gram: number; oran_binde: number }
  uyari: string
}

export type ZekatAyarGirdisi = Partial<
  Omit<ZekatAyari, 'tur_kurallari' | 'borc_kaynaklari'> & {
    tur_kurallari: Record<string, ZekatTurKurali>
    borc_kaynaklari: Partial<Record<ZekatBorcKaynagi, boolean>>
    hesaplar: { id: number; zekata_dahil: boolean }[]
  }
>

export interface ZekatKalemi {
  hesap_id: number | null
  hesap_ad: string
  varlik_kod: string
  varlik_ad: string
  varlik_tur: string
  miktar: number
  birim_fiyat_tl: number | null
  /** Fiyatı yoksa null (sıfır sayılmaz) */
  deger: number | null
  dahil_edilen: boolean
  kapsam_yuzde: number
  hesaba_giren: number
  dislanma_nedeni: string | null
}

export interface ZekatBorcSatiri {
  kaynak: ZekatBorcKaynagi
  ad: string
  tutar: number
}

export interface ZekatEksik {
  hesap_ad: string
  varlik_kod: string
  varlik_ad: string
  neden: string
}

export interface ZekatNisabi {
  olcut: ZekatNisabOlcutu
  gram: number
  birim_fiyat: number
  tl: number
  fiyat_zamani?: string | null
}

/** Önizleme ya da kayıtlı yılın ortak gösterimi */
export interface ZekatSonucu {
  brut: number
  borc: number
  net: number
  nisab: ZekatNisabi
  nisab_ustu: boolean
  zekat: number
  kalemler: ZekatKalemi[]
  borclar: ZekatBorcSatiri[]
  eksikler: ZekatEksik[]
  eksik_veri: boolean
  notlar?: string[]
  borc_dusuldu?: boolean
  oran_binde?: number
}

export interface ZekatHesabiYaniti extends ZekatSonucu {
  tarih: string
  usd_kuru: number | null
  zekat_gunu: ZekatGunu
  /** Bugün zekât günü değilse tahmindir */
  tahmini: boolean
  uyari: string
}

export interface ZekatOdemesi {
  id: number
  zekat_yili_id: number | null
  uye_id: number | null
  tarih: string
  tutar: number
  alici: string | null
  not: string | null
}

/** "Ramazan'dan Ramazan'a" zekât yılı: gereken (zekât hesaplanmışsa), verilen, kalan ve ödemeler */
export interface ZekatDonemi {
  baslangic: string
  bitis: string
  etiket: string
  hicri: boolean
  guncel: boolean
  yil: { id: number; durum: 'taslak' | 'kesinlesti'; nisab_ustu: boolean } | null
  gereken: number | null
  verilen: number
  kalan: number | null
  fazla: number | null
  odemeler: ZekatOdemesi[]
}

export interface ZekatOdemeGirdisi {
  zekat_yili_id: number | null
  uye_id?: number | null
  tarih: string
  tutar: number | null
  alici: string | null
  not: string | null
}

export interface ZekatYili {
  id: number
  zekat_gunu: string
  durum: 'taslak' | 'kesinlesti'
  brut: number
  borc: number
  net: number
  nisab: number
  nisab_birim_fiyat: number
  nisab_ustu: boolean
  zekat: number
  odenen: number
  kalan: number
  usd_kuru: number | null
  eksik_veri: boolean
  hesaplandi_at: string
  kesinlesti_at: string | null
  not: string | null
}

export interface ZekatYiliDetayi extends ZekatYili {
  ayar: ZekatAyari
  eksikler: ZekatEksik[]
  kalemler: ZekatKalemi[]
  borclar: ZekatBorcSatiri[]
  odemeler: ZekatOdemesi[]
}

export interface ZekatElleBorcu {
  id: number
  ad: string
  tutar: number
  vade_tarihi: string | null
  not: string | null
}

/** Bütçe uyarıları: zekât gününe N gün / gün geçti, kayıt yok / ödenmemiş zekât */
export interface ZekatUyarisi {
  tur: 'yaklasiyor' | 'gecti' | 'odenmemis'
  tarih: string
  mesaj: string
  gun?: number
  tahmini?: boolean
  yil_id?: number
  kalan?: number
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
  /** İşlem tarihi aralığı (YYYY-AA-GG, uçlar dahil) */
  tarih_bas?: string | null
  tarih_bit?: string | null
  /** Arama: açıklama, kategori, kart adı ya da tutar */
  ara?: string | null
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
    gelir_turu_id?: number | null
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

  giderKaynaklari: () =>
    http.get<{ kaynaklar: GiderKaynagi[] }>('/aile/gider-kaynaklari').then((r) => r.data.kaynaklar),
  giderKaynagiEkle: (veri: GiderKaynagiGirdisi) =>
    http.post<{ kaynak: GiderKaynagi }>('/aile/gider-kaynaklari', veri).then((r) => r.data),
  giderKaynagiGuncelle: (id: number, veri: GiderKaynagiGirdisi) =>
    http.put<{ kaynak: GiderKaynagi }>(`/aile/gider-kaynaklari/${id}`, veri).then((r) => r.data),
  giderKaynagiSil: (id: number) => http.delete(`/aile/gider-kaynaklari/${id}`),
  duzenliGiderler: (donem: string) =>
    http
      .get<{ giderler: DuzenliGider[]; beklenenler: BeklenenGider[] }>('/aile/duzenli-giderler', {
        params: { donem },
      })
      .then((r) => r.data),
  duzenliGiderEkle: (veri: DuzenliGiderGirdisi) =>
    http.post<{ gider: DuzenliGider }>('/aile/duzenli-giderler', veri).then((r) => r.data),
  duzenliGiderGuncelle: (id: number, veri: DuzenliGiderGirdisi) =>
    http.put<{ gider: DuzenliGider }>(`/aile/duzenli-giderler/${id}`, veri).then((r) => r.data),
  duzenliGiderSil: (id: number) => http.delete(`/aile/duzenli-giderler/${id}`),

  tasarrufEkle: (veri: TasarrufGirdisi) =>
    http
      .post<{ tasarruf: Tasarruf; uyari: string | null }>('/aile/tasarruflar', veri)
      .then((r) => r.data),
  tasarrufGunleri: (donem: string) =>
    http
      .get<{ gunler: TasarrufGunu[]; ay: AyGunu[] }>('/aile/tasarruf-gunleri', {
        params: { donem },
      })
      .then((r) => r.data),
  gunEkle: (veri: TasarrufGunuGirdisi) =>
    http.post<{ gun: TasarrufGunu }>('/aile/tasarruf-gunleri', veri).then((r) => r.data),
  gunGuncelle: (id: number, veri: TasarrufGunuGirdisi) =>
    http.put<{ gun: TasarrufGunu }>(`/aile/tasarruf-gunleri/${id}`, veri).then((r) => r.data),
  gunSil: (id: number) => http.delete(`/aile/tasarruf-gunleri/${id}`),
  tasarrufOzeti: (yil: number) =>
    http.get<TasarrufOzeti>('/aile/tasarruf-ozeti', { params: { yil } }).then((r) => r.data),
  tasarrufSil: (id: number) => http.delete(`/aile/tasarruflar/${id}`),

  zekatAyar: () => http.get<ZekatAyarYaniti>('/aile/zekat/ayar').then((r) => r.data),
  zekatAyarKaydet: (veri: ZekatAyarGirdisi) =>
    http.put<ZekatAyarYaniti>('/aile/zekat/ayar', veri).then((r) => r.data),
  zekatHesapla: () => http.post<ZekatHesabiYaniti>('/aile/zekat/hesapla').then((r) => r.data),
  zekatYillari: () =>
    http.get<{ yillar: ZekatYili[] }>('/aile/zekat/yillar').then((r) => r.data.yillar),
  zekatYili: (id: number) =>
    http.get<{ yil: ZekatYiliDetayi }>(`/aile/zekat/yillar/${id}`).then((r) => r.data.yil),
  zekatYiliKaydet: (veri: { zekat_gunu?: string | null; not?: string | null } = {}) =>
    http.post<{ yil: ZekatYiliDetayi }>('/aile/zekat/yillar', veri).then((r) => r.data.yil),
  zekatYiliKesinlestir: (id: number) =>
    http
      .post<{ yil: ZekatYiliDetayi }>(`/aile/zekat/yillar/${id}/kesinlestir`)
      .then((r) => r.data.yil),
  zekatYiliSil: (id: number) => http.delete(`/aile/zekat/yillar/${id}`),
  zekatBorclari: () =>
    http.get<{ borclar: ZekatElleBorcu[] }>('/aile/zekat/borclar').then((r) => r.data.borclar),
  zekatBorcuEkle: (veri: { ad: string; tutar: number | null; vade_tarihi?: string | null }) =>
    http.post<{ borc: ZekatElleBorcu }>('/aile/zekat/borclar', veri).then((r) => r.data.borc),
  zekatBorcuSil: (id: number) => http.delete(`/aile/zekat/borclar/${id}`),
  zekatOdemeleri: () =>
    http.get<{ odemeler: ZekatOdemesi[] }>('/aile/zekat/odemeler').then((r) => r.data.odemeler),
  zekatDonemleri: () =>
    http.get<{ donemler: ZekatDonemi[] }>('/aile/zekat/donemler').then((r) => r.data.donemler),
  zekatOdemeEkle: (veri: ZekatOdemeGirdisi) =>
    http.post<{ odeme: ZekatOdemesi }>('/aile/zekat/odemeler', veri).then((r) => r.data.odeme),
  zekatOdemeSil: (id: number) => http.delete(`/aile/zekat/odemeler/${id}`),
  gelirTurleri: () =>
    http.get<{ turler: GelirTuru[] }>('/aile/gelir-turleri').then((r) => r.data.turler),
  gelirTuruEkle: (ad: string) =>
    http.post<{ tur: GelirTuru }>('/aile/gelir-turleri', { ad }).then((r) => r.data.tur),
  gelirTuruSil: (id: number) => http.delete(`/aile/gelir-turleri/${id}`),

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
  /** Dış yapay zekâya (ChatGPT, Claude, Gemini…) verilecek istem (şemadan sunucuda birleştirilir) */
  ekstreIstemi: () =>
    http.get<{ istem: string; sema_surumu: string }>('/aile/ekstre-istemi').then((r) => r.data),
  /** Dış yapay zekânın verdiği standart JSON → taslak ekstre; PDF isteğe bağlı (sunucuda kalır, dışarı gitmez) */
  ekstreJsonIle: (kartId: number, json: string, dosya?: File | null) => {
    const veri = new FormData()
    veri.append('kart_id', String(kartId))
    veri.append('json', json)
    if (dosya) veri.append('dosya', dosya)

    return http
      .post<{
        ekstre: Ekstre
        ozet: { satir: number; inceleme: number; reddedilen: number }
        uyarilar: string[]
        message: string
      }>('/aile/ekstreler/json-ile', veri)
      .then((r) => r.data)
  },
  /** Kart ekstresi; `yapayZeka`: kullanıcının açık onayıyla PDF yapay zekâ analizine gider (analiz hemen başlar) */
  ekstreYukle: (kartId: number, dosya: File, yapayZeka = false) => {
    const veri = new FormData()
    veri.append('kart_id', String(kartId))
    veri.append('dosya', dosya)
    if (yapayZeka) veri.append('yapay_zeka', '1')

    return http
      .post<{
        ekstre: Ekstre
        calisma: AiCalisma | null
        ozet: { satir: number; okunamayan_satir: number; tekrar: number }
        message: string
      }>('/aile/ekstreler', veri)
      .then((r) => r.data)
  },
  /** Dosyasız ekstre: yalnızca kesim, son ödeme ve dönem borcu; onaylı ekstre + tek gider satırı */
  ekstreElle: (veri: {
    kart_id: number | null
    kesim_tarihi: string
    son_odeme_tarihi: string
    donem_borcu: number | null
    asgari_odeme: number | null
  }) =>
    http
      .post<{ ekstre: Ekstre; message: string }>('/aile/ekstreler/elle', veri)
      .then((r) => r.data),
  ekstre: (id: number) =>
    http
      .get<{
        ekstre: Ekstre
        kart: Kart
        satirlar: KartIslemi[]
        ai_acik?: boolean
        ai_aktarim?: boolean
        ai_calisma?: AiCalisma | null
      }>(`/aile/ekstreler/${id}`)
      .then((r) => r.data),
  ekstreGuncelle: (id: number, veri: Partial<Record<string, string | number | boolean | null>>) =>
    http.put<{ ekstre: Ekstre }>(`/aile/ekstreler/${id}`, veri).then((r) => r.data),
  ekstreSatiri: (ekstreId: number, islemId: number, veri: Record<string, unknown>) =>
    http
      .put<{ islem: KartIslemi }>(`/aile/ekstreler/${ekstreId}/satirlar/${islemId}`, veri)
      .then((r) => r.data),
  /** Yapay zekâ analizini başlatır (açık onayla); arka planda yürür, durumu ekstreAnalizDurumu ile sorulur */
  ekstreYapayZeka: (id: number) =>
    http
      .post<{ calisma: AiCalisma; message: string }>(`/aile/ekstreler/${id}/yapay-zeka`, {
        onay: true,
      })
      .then((r) => r.data),
  ekstreAnalizDurumu: (id: number) =>
    http
      .get<{ calisma: AiCalisma | null; ekstre: Ekstre }>(`/aile/ekstreler/${id}/yapay-zeka`)
      .then((r) => r.data),
  ekstreAnalizIptal: (id: number) =>
    http
      .post<{ calisma: AiCalisma; message: string }>(`/aile/ekstreler/${id}/yapay-zeka/iptal`)
      .then((r) => r.data),
  /** Maskeli standart JSON (kişisel veri maskelenmiş) */
  ekstreAiJson: (id: number) =>
    http
      .get<{ ai_cikti: StandartEkstre }>(`/aile/ekstreler/${id}/yapay-zeka/json`)
      .then((r) => r.data.ai_cikti),

  /** Banka hesabı ekstresi: yalnızca yapay zekâ analizi ve önizleme (Bütçe'ye aktarılmaz) */
  hesapEkstreleri: () =>
    http
      .get<{
        hesap_ekstreleri: (HesapEkstresi & { ai_calisma: AiCalisma | null })[]
      }>('/aile/hesap-ekstreleri')
      .then((r) => r.data.hesap_ekstreleri),
  hesapEkstresiYukle: (dosya: File) => {
    const veri = new FormData()
    veri.append('dosya', dosya)
    veri.append('onay', '1')

    return http
      .post<{
        hesap_ekstresi: HesapEkstresi
        calisma: AiCalisma
        message: string
      }>('/aile/hesap-ekstreleri', veri)
      .then((r) => r.data)
  },
  hesapEkstresi: (id: number) =>
    http
      .get<{
        hesap_ekstresi: HesapEkstresi
        calisma: AiCalisma | null
        ai_cikti: StandartEkstre | null
      }>(`/aile/hesap-ekstreleri/${id}`)
      .then((r) => r.data),
  hesapEkstresiSil: (id: number) => http.delete(`/aile/hesap-ekstreleri/${id}`),
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
  /** Yapay zekâ çalıştıysa: sayfa denetimi ve doğrulamadan geçmeyen satırlar */
  ai?: EkstreAiDenetimi | null
  /** PDF okuyucu ↔ yapay zekâ karşılaştırması */
  capraz?: EkstreCapraz | null
  /** Kesim tarihi iki okumada farklı: kullanıcı kaydedene kadar onay yok */
  kesim_teyit_gerekli?: boolean
}

/** Yapay zekâ analizi çalışması (içeriksiz) */
export interface AiCalisma {
  id: number
  durum: 'bekliyor' | 'calisiyor' | 'basarili' | 'hata' | 'iptal'
  asama: 'sirada' | 'gonderildi' | 'okunuyor' | 'dogrulaniyor' | 'tamam' | 'hata' | 'iptal' | null
  etkin: boolean
  hata_kodu: string | null
  mesaj: string | null
  kabul_satir: number
  reddedilen_satir: number
  model: string
  basladi_at: string | null
  bitti_at: string | null
  sure_ms: number | null
}

export interface EkstreCapraz {
  durum: 'uyumlu' | 'farkli' | 'php_yok'
  /** Taslakta kalan satırlar: yapay zekânın mı, PDF okuyucunun mu */
  secilen: 'ai' | 'php'
  farklar: string[]
  kesim_farkli: boolean
  php: { satir: number; mutabakat: string | null }
  ai: { satir: number; mutabakat: string }
}

export interface SayfaDenetimi {
  pdf_sayfa: number
  analiz_edilen_sayfa: number
  sayfa_uyusuyor: boolean
  sorunlu_sayfa?: number
  sayfalar: {
    sayfa: number
    islem_tablosu_var: boolean
    bulunan_islem: number
    ayristirilan_islem: number
    inceleme_gereken: number
    reddedilen?: number
    php_islem?: number | null
    metin_var?: boolean
    durum: string
  }[]
}

/** Standart ekstre JSON'u (yapay zekâ çıktısı, maskeli); arayüzün kullandığı alanlar */
export interface StandartIslem {
  date: string | null
  description: string
  amount: number
  currency: string
  direction: 'debit' | 'credit'
  transaction_type: string
  balance: number | null
  source_page: number
  source_text: string
  confidence: number
  needs_review: boolean
  review_reason: string | null
}

export interface StandartEkstre {
  bank_name: string | null
  statement_type: 'credit_card' | 'bank_account' | 'unknown'
  account: { label: string | null; currency: string | null }
  period: Record<string, string | null>
  summary: Record<string, number | null>
  transactions: StandartIslem[]
  warnings: string[]
  review_required: boolean
  [alan: string]: unknown
}

export interface HesapEkstresiDenetimi {
  tur?: string
  bakiye?: {
    durum: 'tutuyor' | 'tutmuyor' | 'kontrol_edilemedi'
    kontroller: (EkstreKontrolu & { hatali_satir?: number })[]
    satir_hatalari: { sira: number; beklenen: string; bulunan: string; fark: string }[]
  }
  satirlar?: { durum: 'kabul' | 'red'; neden: string | null; inceleme: string[] }[]
  reddedilen?: { neden: string; kaynak: string; sayfa: number }[]
  sayfa_denetimi?: SayfaDenetimi
  uyarilar?: string[]
}

export interface HesapEkstresi {
  id: number
  tur: 'banka_hesabi'
  durum: 'analiz' | 'onizleme' | 'hata'
  banka_adi: string | null
  donem_baslangic: string | null
  donem_bitis: string | null
  acilis_bakiyesi: number | null
  kapanis_bakiyesi: number | null
  denetim: HesapEkstresiDenetimi | null
  dosya_adi: string | null
  sayfa_sayisi: number | null
  olusturuldu: string | null
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

export interface EkstreAiDenetimi {
  model: string
  banka: string
  /** Yapay zekânın belge türü tanıması */
  tur?: 'credit_card' | 'bank_account' | 'unknown'
  inceleme_gerekli: boolean
  uyarilar: string[]
  bonus_hareketi: number
  reddedilen: { neden: string; kaynak: string; sayfa: number }[]
  sayfa_denetimi: SayfaDenetimi
  /** PDF okuyucunun satırları korunduysa yapay zekâ sonucu yalnızca öneri */
  oneri?: boolean
  /** Kullanıcının dış yapay zekâdan yapıştırdığı JSON (model: "harici"); `metinsiz`: PDF yok, kaynak satırlar doğrulanamadı */
  harici?: boolean
  metinsiz?: boolean
  mutabakat?: { durum: string; fark: string | null } | null
}
