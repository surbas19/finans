import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import PrimeVue from 'primevue/config'
import type { ZekatDonemi } from '@/api/aile'
import ZekatDonemleri from '@/components/zekat/ZekatDonemleri.vue'

function donem(ek: Partial<ZekatDonemi> = {}): ZekatDonemi {
  return {
    baslangic: '2026-02-18',
    bitis: '2027-02-07',
    etiket: 'Ramazan 1447 → Ramazan 1448 (18.02.2026 – 06.02.2027)',
    hicri: true,
    guncel: true,
    yil: { id: 3, durum: 'kesinlesti', nisab_ustu: true },
    gereken: 15000,
    verilen: 10000,
    kalan: 5000,
    fazla: 0,
    odemeler: [
      {
        id: 1,
        zekat_yili_id: 3,
        uye_id: null,
        tarih: '2026-05-05',
        tutar: 6000,
        alici: 'Vakıf',
        not: null,
      },
      {
        id: 2,
        zekat_yili_id: 3,
        uye_id: null,
        tarih: '2026-10-01',
        tutar: 4000,
        alici: null,
        not: 'Komşu',
      },
    ],
    ...ek,
  }
}

const genel = { plugins: [PrimeVue] }

describe("Zekât yılları (Ramazan'dan Ramazan'a)", () => {
  it('gereken, verilen ve kalan başlıkta; ödemeler ve toplam içerikte', () => {
    const w = mount(ZekatDonemleri, { props: { donemler: [donem()] }, global: genel })
    const baslik = w.get('[data-test="zekat-donem-baslik"]').text()
    expect(baslik).toContain('Ramazan 1447')
    expect(baslik).toContain('Bu yıl')
    expect(baslik).toContain('Gereken')
    expect(baslik).toContain('15.000')
    expect(w.get('[data-test="zekat-verilen"]').text()).toContain('10.000')
    expect(w.get('[data-test="zekat-kalan-donem"]').text()).toContain('5.000')
    expect(w.find('[data-test="zekat-tamam"]').exists()).toBe(false)
    // Güncel yıl açık: ödemeler görünür
    expect(w.text()).toContain('Vakıf')
    expect(w.text()).toContain('Komşu')
    expect(w.text()).toContain('Verilen toplam')
  })

  it('kalan sıfırsa Tamamlandı; fazla verildiyse fazla yazar', () => {
    const w = mount(ZekatDonemleri, {
      props: { donemler: [donem({ verilen: 17000, kalan: 0, fazla: 2000 })] },
      global: genel,
    })
    expect(w.find('[data-test="zekat-tamam"]').exists()).toBe(true)
    expect(w.get('[data-test="zekat-fazla"]').text()).toContain('2.000')
  })

  it('zekât hesaplanmamış yılda gereken / kalan yok, yalnız verilen yazar', () => {
    const w = mount(ZekatDonemleri, {
      props: {
        donemler: [
          donem({
            yil: null,
            gereken: null,
            kalan: null,
            fazla: null,
            verilen: 3000,
            guncel: false,
          }),
        ],
      },
      global: genel,
    })
    const baslik = w.get('[data-test="zekat-donem-baslik"]').text()
    expect(baslik).toContain('Zekât henüz hesaplanmadı')
    expect(baslik).not.toContain('Kalan')
    expect(w.get('[data-test="zekat-verilen"]').text()).toContain('3.000')
    expect(baslik).not.toContain('Bu yıl')
  })

  it('ödeme ekle ve sil olayları dönem / ödeme ile gelir', async () => {
    const d = donem()
    const w = mount(ZekatDonemleri, { props: { donemler: [d] }, global: genel })
    await w.get('[data-test="zekat-donem-odeme-ekle"]').trigger('click')
    expect(w.emitted('ekle')?.[0]?.[0]).toMatchObject({ baslangic: '2026-02-18' })
    await w.get('button[aria-label="Ödemeyi sil"]').trigger('click')
    expect(w.emitted('sil')?.[0]?.[0]).toMatchObject({ id: 1 })
  })
})
