import { VARLIK_TURLERI, type Varlik } from '@/api/finans'
import { miktar as miktarBicimi } from '@/utils/bicim'

/**
 * Elde olan varlıkları toplu ekleme: her satır bir varlık, alanlar `;` (ya da Excel'den kopyalanınca sekme) ile ayrılır:
 *
 *   kod ya da ad ; miktar ; birim fiyat (isteğe bağlı, TL) ; tarih (isteğe bağlı) ; not (isteğe bağlı)
 *
 * Saf fonksiyonlar: katalog ve tarihler dışarıdan verilir. Sunucu aynı kuralları yeniden denetler.
 */

/** Sunucunun tek seferde kabul ettiği en çok satır */
export const EN_COK_SATIR = 200
/** Katalogda olmayan kodlar sunucuya bu büyüklükte parçalarla sorulur (`POST /varliklar/toplu` sınırı) */
export const YENI_KOD_PARCASI = 50

export interface TopluSatirSonucu {
  /** Metindeki satır numarası (1'den başlar) */
  satirNo: number
  ham: string
  varlik: Varlik | null
  /** Katalogda yok ama kod biçiminde (ör. ASELS): eklerken Borsa İstanbul'da, yoksa kriptoda aranır */
  yeniKod: string | null
  miktar: number | null
  birimFiyat: number | null
  /** YYYY-MM-DD */
  tarih: string
  not: string | null
  hata: string | null
  uyari: string | null
}

const BASLIK_ALANLARI = new Set(['KOD', 'VARLIK', 'VARLIK KODU', 'AD'])

/** Karşılaştırma için: Türkçe büyük harf, harf işaretleri atılmış (çeyrek = ÇEYREK = CEYREK) */
function sade(metin: string): string {
  return metin
    .trim()
    .replace(/\s+/g, ' ')
    .toLocaleUpperCase('tr-TR')
    .replace(
      /[ÇĞİÖŞÜÂÎÛ]/g,
      (h) => ({ Ç: 'C', Ğ: 'G', İ: 'I', Ö: 'O', Ş: 'S', Ü: 'U', Â: 'A', Î: 'I', Û: 'U' })[h] ?? h,
    )
}

/**
 * Sayı: virgül varsa Türkçe biçim (1.234,56). Virgül yoksa birden çok nokta binliktir (1.234.567); tek nokta ve
 * ardında tam 3 hane (1.500) binlik sayılır ama `belirsiz` döner; kalanında nokta ondalıktır (12.5, 0.015).
 */
export function sayiCoz(metin: string): { deger: number | null; belirsiz: boolean } {
  const s = metin.replace(/[\s ₺]/g, '').replace(/^tl|tl$/gi, '')
  const gecersiz = { deger: null, belirsiz: false }
  if (!/^\d[\d.,]*$/.test(s)) return gecersiz

  let belirsiz = false
  let duz: string
  if (s.includes(',')) {
    const [tam = '', kesir = '', ...fazla] = s.split(',')
    if (fazla.length > 0 || kesir.includes('.') || kesir === '') return gecersiz
    if (tam.includes('.') && !/^\d{1,3}(\.\d{3})+$/.test(tam)) return gecersiz
    duz = `${tam.replace(/\./g, '')}.${kesir}`
  } else {
    const parcalar = s.split('.')
    if (parcalar.length > 2) {
      if (!/^\d{1,3}(\.\d{3})+$/.test(s)) return gecersiz
      duz = s.replace(/\./g, '')
    } else if (parcalar.length === 2) {
      const [tam = '', kesir = ''] = parcalar
      if (kesir === '') return gecersiz
      if (kesir.length === 3 && tam !== '0') {
        belirsiz = true
        duz = tam + kesir
      } else {
        duz = s
      }
    } else {
      duz = s
    }
  }
  const deger = Number(duz)

  return Number.isFinite(deger) ? { deger, belirsiz } : gecersiz
}

/** gg.aa.yyyy, gg/aa/yyyy, gg-aa-yyyy ya da yyyy-aa-gg → "YYYY-MM-DD"; geçersiz takvim günü (31.02) null */
export function tarihCoz(metin: string): string | null {
  const s = metin.trim()
  let y: number, a: number, g: number
  let e = s.match(/^(\d{1,2})[./-](\d{1,2})[./-](\d{4})$/)
  if (e) {
    ;[g, a, y] = [Number(e[1]), Number(e[2]), Number(e[3])]
  } else if ((e = s.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/))) {
    ;[y, a, g] = [Number(e[1]), Number(e[2]), Number(e[3])]
  } else {
    return null
  }
  const d = new Date(y, a - 1, g)
  if (d.getFullYear() !== y || d.getMonth() !== a - 1 || d.getDate() !== g) return null

  return `${y}-${String(a).padStart(2, '0')}-${String(g).padStart(2, '0')}`
}

/** Sunucunun kaynakta arayabileceği kod biçimi (sadeleştirilmiş: büyük harf, Türkçe harf yok) */
const KOD_BICIMI = /^[A-Z0-9]{2,12}$/

/**
 * Önce kod, sonra ad eşleşmesi; birden çok türde aynı kod varsa hata. Katalogda olmayan ama kod biçimindeki alan
 * `yeniKod` olur (eklerken aranır); `bulunamayan` kümesindeki yeni kod (önceki aramada bulunamamış) hatadır.
 */
function varlikBul(
  alan: string,
  varliklar: Varlik[],
  bulunamayan: ReadonlySet<string>,
): { varlik: Varlik | null; yeniKod: string | null; hata: string | null } {
  const anahtar = sade(alan)
  const kodla = varliklar.filter((v) => sade(v.kod) === anahtar)
  const adayler = kodla.length > 0 ? kodla : varliklar.filter((v) => sade(v.ad) === anahtar)
  if (adayler.length === 1) return { varlik: adayler[0]!, yeniKod: null, hata: null }
  if (adayler.length > 1) {
    const liste = adayler.map((v) => `${v.ad} (${VARLIK_TURLERI[v.tur].etiket})`).join(', ')

    return {
      varlik: null,
      yeniKod: null,
      hata: `Birden çok varlık eşleşti: ${liste}. Kod yerine adını yazın.`,
    }
  }
  if (KOD_BICIMI.test(anahtar)) {
    return {
      varlik: null,
      yeniKod: anahtar,
      hata: bulunamayan.has(anahtar)
        ? `${anahtar} Borsa İstanbul'da ve kriptoda bulunamadı. Borsa kodunu yazın (ör. ASELS); fonsa önce "Kodla ekle" ile ekleyin.`
        : null,
    }
  }

  return {
    varlik: null,
    yeniKod: null,
    hata: `Katalogda yok: ${alan.trim()}. Hisse ya da kriptoysa kodunu yazın (ör. THYAO); fonsa önce "Kodla ekle" ile ekleyin.`,
  }
}

/** Excel'in tırnakladığı alan: "GRAM" → GRAM */
function alanTemizle(alan: string): string {
  const s = alan.trim()

  return s.length >= 2 && s.startsWith('"') && s.endsWith('"') ? s.slice(1, -1).trim() : s
}

/**
 * Metni satır satır çözer. Boş satırlar, `#` ile başlayan satırlar ve ilk alanı "kod / varlık" olan başlık satırı
 * atlanır. `varsayilanTarih` tarihi yazılmamış satırlara verilir; `bugun`den ileri tarih hatadır (ikisi de YYYY-MM-DD).
 * `bulunamayan`: kaynakta aranıp bulunamamış yeni kodlar (o satırlar hatalı olur).
 */
export function satirlariCoz(
  metin: string,
  varliklar: Varlik[],
  varsayilanTarih: string,
  bugun: string,
  bulunamayan: ReadonlySet<string> = new Set(),
): TopluSatirSonucu[] {
  const sonuc: TopluSatirSonucu[] = []

  metin.split(/\r?\n/).forEach((satir, i) => {
    const ham = satir.trim()
    if (ham === '' || ham.startsWith('#')) return
    const alanlar = ham.split(/[;\t]/).map(alanTemizle)
    while (alanlar.length > 0 && alanlar[alanlar.length - 1] === '') alanlar.pop()
    if (sonuc.length === 0 && BASLIK_ALANLARI.has(sade(alanlar[0] ?? ''))) return

    const [kod = '', miktarAlani = '', fiyatAlani = '', tarihAlani = '', notAlani = ''] = alanlar
    const hatalar: string[] = []
    const uyarilar: string[] = []
    const kayit: TopluSatirSonucu = {
      satirNo: i + 1,
      ham,
      varlik: null,
      yeniKod: null,
      miktar: null,
      birimFiyat: null,
      tarih: varsayilanTarih,
      not: notAlani || null,
      hata: null,
      uyari: null,
    }

    if (alanlar.length > 5) {
      hatalar.push('En çok 5 alan olabilir: kod;miktar;birim fiyat;tarih;not')
    }
    if (kod === '' || miktarAlani === '') {
      hatalar.push('Kod ve miktar gerekli (ör. GRAM;25)')
    }

    if (kod !== '') {
      const bulunan = varlikBul(kod, varliklar, bulunamayan)
      kayit.varlik = bulunan.varlik
      kayit.yeniKod = bulunan.yeniKod
      if (bulunan.hata) hatalar.push(bulunan.hata)
    }

    if (miktarAlani !== '') {
      const { deger, belirsiz } = sayiCoz(miktarAlani)
      if (deger === null) hatalar.push(`Miktar sayı değil: ${miktarAlani}`)
      else if (deger <= 0) hatalar.push('Miktar sıfırdan büyük olmalı')
      else kayit.miktar = deger
      if (deger !== null && belirsiz) {
        uyarilar.push(
          `${miktarAlani} → ${miktarBicimi(deger)} olarak okundu; ondalıksa virgül kullanın (ör. 1,5)`,
        )
      }
    }

    if (fiyatAlani !== '') {
      const { deger, belirsiz } = sayiCoz(fiyatAlani)
      if (deger === null) hatalar.push(`Birim fiyat sayı değil: ${fiyatAlani}`)
      else if (deger <= 0) hatalar.push('Birim fiyat sıfırdan büyük olmalı')
      else kayit.birimFiyat = deger
      if (deger !== null && belirsiz) {
        uyarilar.push(
          `Fiyat ${fiyatAlani} → ${miktarBicimi(deger)} olarak okundu; ondalıksa virgül kullanın`,
        )
      }
    }

    if (tarihAlani !== '') {
      const t = tarihCoz(tarihAlani)
      if (t === null) hatalar.push(`Tarih anlaşılmadı: ${tarihAlani} (gg.aa.yyyy)`)
      else kayit.tarih = t
    }
    if (kayit.tarih > bugun) hatalar.push('İleri tarihli kayıt girilemez')

    if (notAlani.length > 255) hatalar.push('Not en çok 255 karakter olabilir')

    kayit.hata = hatalar.length > 0 ? hatalar.join(' · ') : null
    kayit.uyari = uyarilar.length > 0 ? uyarilar.join(' · ') : null
    sonuc.push(kayit)
  })

  return sonuc
}
