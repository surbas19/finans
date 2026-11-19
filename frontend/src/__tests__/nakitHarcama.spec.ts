import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import type { Kategori } from '@/api/aile'
import { useAileStore } from '@/stores/aile'

const ekle = vi.fn<(veri: Record<string, unknown>) => Promise<unknown>>()
vi.mock('@/api/aile', async (asil) => ({
  ...(await asil<typeof import('@/api/aile')>()),
  aileApi: {
    duzenliGiderEkle: (v: Record<string, unknown>) => ekle(v),
    odemeKaynaklari: () =>
      Promise.resolve({
        ev: { bakiye: 500 },
        hesaplar: [
          { id: 3, ad: 'Maaş hesabı', nakit: 1000 },
          { id: 4, ad: 'Birikim', nakit: 20 },
        ],
      }),
  },
}))

const kat = (id: number, ad: string, kullanim: number, fatura = false): Kategori => ({
  id,
  ad,
  ikon: null,
  renk: 'sky',
  fatura,
  sira: id,
  kullanim,
})
// Kullanım: Market 142 > Yeme-içme 87 > Akaryakıt 54 > Giyim 21 > Eğlence 3 > Eğitim 0; Faturalar çiplerde yok
const kategoriler = [
  kat(1, 'Giyim', 21),
  kat(2, 'Market', 142),
  kat(3, 'Eğlence', 3),
  kat(4, 'Yeme-içme', 87),
  kat(5, 'Faturalar', 500, true),
  kat(6, 'Eğitim', 0),
  kat(7, 'Akaryakıt', 54),
]

async function kur() {
  const { default: NakitHarcamaFormu } = await import('@/components/aile/NakitHarcamaFormu.vue')
  setActivePinia(createPinia())
  useAileStore().kategoriler = kategoriler
  const w = mount(NakitHarcamaFormu, {
    props: { visible: false },
    attachTo: document.body,
    global: {
      plugins: [PrimeVue, ToastService],
      stubs: { UygulamaPenceresi: { template: '<div><slot /></div>' }, EvNakdiHareketFormu: true },
    },
  })
  await w.setProps({ visible: true })
  await flushPromises()

  return w
}

describe('Nakit harcama: sade hızlı form', () => {
  beforeEach(() => {
    ekle.mockReset()
    ekle.mockResolvedValue({ gider: { id: 1 } })
    vi.stubGlobal('matchMedia', () => ({
      matches: false,
      addEventListener: () => {},
      removeEventListener: () => {},
    }))
  })

  it('yalnız tutar, açıklama, kategori çipleri ve tarih; düzenli ödeme / dönem / not yok', async () => {
    const w = await kur()
    expect(w.find('[data-test="nakit-tutar"]').exists()).toBe(true)
    expect(w.find('[data-test="nakit-aciklama"]').exists()).toBe(true)
    expect(w.text()).not.toContain('Düzenli ödeme')
    expect(w.text()).not.toContain('Ait olduğu ay')
    expect(w.text()).not.toContain('Not')
    // Çipler: en çok kullanılan 4 (fatura kategorisi hariç)
    expect(w.findAll('[data-test="nakit-kategori"]').map((e) => e.text())).toEqual([
      'Market',
      'Yeme-içme',
      'Akaryakıt',
      'Giyim',
    ])
    // Tutar girilmeden kaydedilemez
    expect(w.get('[data-test="nakit-kaydet"]').attributes('disabled')).toBeDefined()
    w.unmount()
  })

  it('tutar + kategori çipi: açıklama boşsa kategori adı; bugünün tarihi ve ayı gönderilir', async () => {
    const w = await kur()
    await w.get('#nh-tutar').setValue('125,5')
    await w.get('#nh-tutar').trigger('blur')
    await w.findAll('[data-test="nakit-kategori"]')[0]!.trigger('click')
    expect(w.findAll('[data-test="nakit-kategori"]')[0]!.attributes('aria-pressed')).toBe('true')
    await w.get('[data-test="nakit-form"]').trigger('submit')
    await flushPromises()
    expect(ekle).toHaveBeenCalledTimes(1)
    const g = ekle.mock.calls[0]![0]
    const bugun = new Date()
    const ay = `${bugun.getFullYear()}-${String(bugun.getMonth() + 1).padStart(2, '0')}`
    expect(g).toMatchObject({
      gider_kaynagi_id: null,
      kategori_id: 2,
      odeme_yeri: 'ev',
      aciklama: 'Market',
      tutar: 125.5,
      donem: ay,
      not: null,
    })
    expect(String(g.tarih).startsWith(ay)).toBe(true)
    expect(w.emitted('kaydedildi')).toHaveLength(1)
    expect((w.emitted('update:visible') ?? []).slice(-1)[0]).toEqual([false])
    w.unmount()
  })

  it('açıklama yazılırsa o gider; kategori seçilmezse ve açıklama yoksa "Nakit harcama"', async () => {
    let w = await kur()
    await w.get('#nh-tutar').setValue('40')
    await w.get('#nh-tutar').trigger('blur')
    await w.get('[data-test="nakit-aciklama"]').setValue('Simit')
    await w.get('[data-test="nakit-form"]').trigger('submit')
    await flushPromises()
    expect(ekle.mock.calls[0]![0]).toMatchObject({
      aciklama: 'Simit',
      kategori_id: null,
      tutar: 40,
    })
    w.unmount()
    ekle.mockClear()
    w = await kur()
    await w.get('#nh-tutar').setValue('15')
    await w.get('#nh-tutar').trigger('blur')
    await w.get('[data-test="nakit-form"]').trigger('submit')
    await flushPromises()
    expect(ekle.mock.calls[0]![0]).toMatchObject({ aciklama: 'Nakit harcama', kategori_id: null })
    w.unmount()
  })

  it('ödeme yeri varsayılan Ev; Banka seçilirse banka gönderilir', async () => {
    const w = await kur()
    expect(w.text()).toContain('Ev nakdinden düşer')
    await w.get('#nh-tutar').setValue('60')
    await w.get('#nh-tutar').trigger('blur')
    const banka = w.findAll('[data-test="nakit-odeme-yeri"] .p-togglebutton')[1]!
    await banka.trigger('click')
    await w.get('[data-test="nakit-form"]').trigger('submit')
    await flushPromises()
    expect(ekle.mock.calls[0]![0]).toMatchObject({ odeme_yeri: 'banka', tutar: 60, hesap_id: 3 })
    w.unmount()
  })

  it("kaynak bakiyesi gösterilir; yetmeyen tutarda uyarı çıkar ve kaydedilemez, Para ekle yalnız Ev'de", async () => {
    const w = await kur()
    expect(w.get('[data-test="nakit-kaynak-bilgi"]').text()).toContain('500')
    await w.get('#nh-tutar').setValue('800')
    await w.get('#nh-tutar').trigger('blur')
    expect(w.get('[data-test="nakit-yetmiyor"]').text()).toContain('300')
    expect(w.find('[data-test="nakit-para-ekle"]').exists()).toBe(true)
    expect(w.get('[data-test="nakit-kaydet"]').attributes('disabled')).toBeDefined()
    await w.get('[data-test="nakit-form"]').trigger('submit')
    await flushPromises()
    expect(ekle).not.toHaveBeenCalled()
    // Banka: ilk hesabın nakdi 1000 → 800 yeter
    await w.findAll('[data-test="nakit-odeme-yeri"] .p-togglebutton')[1]!.trigger('click')
    expect(w.find('[data-test="nakit-yetmiyor"]').exists()).toBe(false)
    expect(w.get('[data-test="nakit-kaynak-bilgi"]').text()).toContain('1.000')
    w.unmount()
  })

  it('aynı çipe ikinci dokunuş seçimi kaldırır; Diğer tam kategori listesini açar', async () => {
    const w = await kur()
    const cip = () => w.findAll('[data-test="nakit-kategori"]')[1]!
    await cip().trigger('click')
    expect(cip().attributes('aria-pressed')).toBe('true')
    await cip().trigger('click')
    expect(cip().attributes('aria-pressed')).toBe('false')
    expect(w.find('[data-test="nakit-kategori-secici"]').exists()).toBe(false)
    await w.get('[data-test="nakit-diger"]').trigger('click')
    expect(w.find('[data-test="nakit-kategori-secici"]').exists()).toBe(true)
    w.unmount()
  })
})
