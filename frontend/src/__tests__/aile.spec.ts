import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import type { KartIslemi } from '@/api/aile'
import { buAy, donemEkle, donemEtiketi, gecerliDonem, tarihMetnindenDonem } from '@/utils/donem'

const ekstreSatiri = vi.fn<(...a: unknown[]) => Promise<unknown>>()
vi.mock('@/api/aile', async (asil) => ({
  ...(await asil<typeof import('@/api/aile')>()),
  aileApi: {
    ekstreSatiri: (...a: unknown[]) => ekstreSatiri(...a),
    ekstreDosyaAdresi: (id: number, sayfa?: number) =>
      `/api/v1/aile/ekstreler/${id}/dosya#page=${sayfa}`,
  },
}))

describe('donem yardımcıları', () => {
  it('ay ekleme yıl geçişinde, etiket ve doğrulama', () => {
    expect(donemEkle('2026-12', 1)).toBe('2027-01')
    expect(donemEkle('2026-01', -1)).toBe('2025-12')
    expect(donemEkle('2026-03', -14)).toBe('2025-01')
    expect(donemEtiketi('2026-02')).toBe('Şubat 2026')
    expect(donemEtiketi(null)).toBe('Dönem bilinmiyor')
    expect(gecerliDonem('2026-13')).toBe(false)
    expect(gecerliDonem('2026-09')).toBe(true)
    expect(buAy(new Date(2026, 10, 30))).toBe('2026-11')
    expect(tarihMetnindenDonem('2026-01-31')).toBe('2026-01')
    expect(tarihMetnindenDonem(null)).toBeNull()
  })
})

function satir(alanlar: Partial<KartIslemi> = {}): KartIslemi {
  return {
    id: 5,
    kart_id: 1,
    ekstre_id: 9,
    sira: 1,
    durum: 'taslak',
    islem_tarihi: '2026-10-05',
    aciklama: 'ISKI SU FATURA 2026/09',
    tutar: 312.4,
    tur: 'harcama',
    taksit_no: null,
    taksit_sayisi: null,
    taksit_toplam: null,
    iade_islem_id: null,
    kategori_id: null,
    rapor_donemi: null,
    tekrar_islem_id: null,
    ham_metin: '05.10.2026 ISKI SU FATURA 2026/09 312,40',
    sayfa: 2,
    okuma: { alanlar: [], uyarilar: [], tutar_adaylari: [] },
    fatura_onerisi: null,
    ...alanlar,
  }
}

describe('EkstreSatiri', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    ekstreSatiri.mockReset()
  })

  async function kur(s: KartIslemi, duzenlenebilir = true) {
    const { default: EkstreSatiri } = await import('@/components/aile/EkstreSatiri.vue')

    return mount(EkstreSatiri, {
      props: { satir: s, ekstreId: 9, duzenlenebilir },
      global: { plugins: [PrimeVue, ToastService] },
    })
  }

  it('PDF satırı ve sayfası, tekrar ve okunamayan işaretleri', async () => {
    const w = await kur(
      satir({
        durum: 'atlandi',
        tutar: null,
        tekrar: { id: 3, yer: 'Ekim 2026 ekstresi' },
        okuma: {
          alanlar: ['tutar'],
          uyarilar: ['Yıl ekstre döneminden alındı.'],
          tutar_adaylari: [],
        },
      }),
    )
    expect(w.text()).toContain('05.10.2026 ISKI SU FATURA 2026/09 312,40')
    expect(w.text()).toContain('Zaten kayıtlı: Ekim 2026 ekstresi')
    expect(w.text()).toContain('Okunamadı: tutar')
    expect(w.text()).toContain('Yıl ekstre döneminden alındı.')
    expect(w.get('a').attributes('href')).toBe('/api/v1/aile/ekstreler/9/dosya#page=2')
    expect(w.text()).toContain('PDF s.2')
  })

  it('dışarıda bırakma hemen kaydedilir', async () => {
    ekstreSatiri.mockResolvedValue({ islem: satir({ durum: 'atlandi' }) })
    const w = await kur(satir())
    await w.get('input[type="checkbox"]').setValue(false)
    await flushPromises()
    expect(ekstreSatiri).toHaveBeenCalledWith(9, 5, { dahil: false })
    expect(w.emitted('degisti')?.[0]?.[0]).toMatchObject({ durum: 'atlandi' })
  })

  it('fatura adayının güveni ve gerekçesi görünür; onaylıda düzenleme yok', async () => {
    const oneri = {
      guven: 'yuksek' as const,
      puan: 70,
      saglayici_id: 2,
      fatura_turu_id: 2,
      adaylar: [2],
      gerekce: [
        'Açıklamada "ISKI" geçiyor (İSKİ).',
        'Açıklamada hizmet dönemi yazıyor (Eylül 2026).',
      ],
      hizmet_donemi: '2026-09',
      donem_kaynagi: 'aciklama' as const,
      otomatik: true,
      secildi: true,
    }
    const w = await kur(satir({ fatura_onerisi: oneri }), false)
    const kutu = w.get('[data-test="fatura-onerisi"]')
    expect(kutu.text()).toContain('Güvenilir')
    expect(kutu.text()).toContain('otomatik kayıt')
    expect(kutu.text()).toContain('Açıklamada hizmet dönemi yazıyor (Eylül 2026).')
    expect(kutu.text()).toContain('Eylül 2026')
    expect(w.find('input[type="checkbox"]').exists()).toBe(false)
  })
})
