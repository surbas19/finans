import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import type { Ozet } from '@/api/finans'

const ozet = vi.fn<(hesapId: number | null) => Promise<Ozet>>()

vi.mock('@/api/finans', async (asil) => ({
  ...(await asil<typeof import('@/api/finans')>()),
  finansApi: { ozet: (h: number | null) => ozet(h) },
}))

const stubs = {
  UygulamaPenceresi: {
    props: ['visible'],
    template: '<div v-if="visible" data-test="pencere"><slot name="header" /><slot /></div>',
  },
}

const poz = (
  hesap: number,
  kod: string,
  ad: string,
  miktar: number,
  birim: string,
  deger: number,
) => ({
  hesap_id: hesap,
  varlik: {
    id: Math.round(deger),
    tur: 'hisse',
    kod,
    ad,
    birim,
    para_birimi: 'TRY',
    kaynak: 'elle',
    gecikmeli: false,
    fiyat: null,
  },
  miktar,
  deger,
  dahil: true,
})

const veri = {
  usd_kuru: null,
  toplam: {},
  dagilim: [],
  varliklar: [],
  son_guncelleme: null,
  hesaplar: [
    { id: 1, ad: 'Yatırım hesabım', renk: null, deger: 168080 },
    { id: 2, ad: 'Eşimin hesabı', renk: null, deger: 107350 },
    { id: 3, ad: 'Boş hesap', renk: null, deger: 0 },
  ],
  pozisyonlar: [
    poz(1, 'ASELS', 'Aselsan', 400, 'lot', 44600),
    poz(1, 'THYAO', 'Türk Hava Yolları', 1575, 'lot', 123480),
    poz(2, 'Gram altın', 'Altın', 20, 'gr', 99200),
    poz(2, 'Nakit', 'Türk lirası', 8150, '₺', 8150),
  ],
} as unknown as Ozet

const yaz = vi.fn<(m: string) => Promise<void>>()

beforeEach(() => {
  vi.stubGlobal('matchMedia', () => ({
    matches: false,
    addEventListener: () => {},
    removeEventListener: () => {},
  }))
  Object.defineProperty(navigator, 'clipboard', {
    value: { writeText: (m: string) => yaz(m) },
    configurable: true,
  })
  ozet.mockReset()
  yaz.mockReset()
  yaz.mockResolvedValue()
  ozet.mockResolvedValue(veri)
})

async function ac() {
  const { default: PortfoyOzetiDialog } = await import('@/components/PortfoyOzetiDialog.vue')
  const w = mount(PortfoyOzetiDialog, {
    props: { visible: false },
    global: { plugins: [PrimeVue, ToastService], stubs },
  })
  await w.setProps({ visible: true })
  await flushPromises()

  return w
}

describe('Portföy özeti penceresi', () => {
  it('her hesap bandı toplamıyla, altında varlık / adet / değer; boş hesap gösterilmez; genel toplam', async () => {
    const w = await ac()
    const hesaplar = w.findAll('[data-test="ozet-hesap"]')
    expect(hesaplar).toHaveLength(2)
    expect(hesaplar[0]!.text()).toContain('Yatırım hesabım')
    expect(hesaplar[0]!.get('[data-test="ozet-hesap-toplam"]').text()).toContain('168.080')
    const satirlar = hesaplar[0]!.findAll('[data-test="ozet-satir"]').map((s) => s.text())
    // Değeri büyükten küçüğe
    expect(satirlar[0]).toContain('THYAO')
    expect(satirlar[0]).toContain('1.575 lot')
    expect(satirlar[0]).toContain('123.480')
    expect(satirlar[1]).toContain('ASELS')
    expect(w.get('[data-test="ozet-genel"]').text()).toContain('275.430')
    w.unmount()
  })

  it('kopyala simgesi aynı içeriği düz metin olarak panoya yazar', async () => {
    const w = await ac()
    await w.get('[data-test="ozet-kopyala"]').trigger('click')
    await flushPromises()
    expect(yaz).toHaveBeenCalledTimes(1)
    const metin = yaz.mock.calls[0]![0]
    expect(metin).toContain('YATIRIM HESABIM — ₺168.080,00')
    expect(metin).toContain('THYAO · 1.575 lot · ₺123.480,00')
    expect(metin).toContain('EŞİMİN HESABI — ₺107.350,00')
    expect(metin).toContain('Gram altın · 20 gr · ₺99.200,00')
    expect(metin).toContain('GENEL TOPLAM — ₺275.430,00')
    w.unmount()
  })

  it('varlık yoksa boş durum; yükleme hatasında uyarı', async () => {
    ozet.mockResolvedValue({ ...veri, pozisyonlar: [] } as unknown as Ozet)
    const w = await ac()
    expect(w.text()).toContain('Henüz varlık yok')
    w.unmount()
    ozet.mockRejectedValue(new Error('x'))
    const h = await ac()
    expect(h.text()).toContain('yüklenemedi')
    h.unmount()
  })
})
