import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import type { SilinenKaydi, SilinenlerYaniti } from '@/api/aile'

const silinenler = vi.fn<(tur?: string, sayfa?: number) => Promise<SilinenlerYaniti>>()
const silinenAyrinti = vi.fn<(id: number) => Promise<SilinenKaydi>>()
const sifreUnuttum = vi.fn<(e: string) => Promise<{ message: string }>>()
const sifreKodla = vi.fn<(v: Record<string, string>) => Promise<{ message: string }>>()
const sifreSifirla = vi.fn<(v: Record<string, string>) => Promise<{ message: string }>>()
const kurtarmaKalan = vi.fn<() => Promise<{ kalan: number; adet: number }>>()
const kurtarmaUret = vi.fn<(s: string) => Promise<{ kodlar: string[]; message: string }>>()
const replace = vi.fn<(adres: unknown) => void>()

vi.mock('@/api/aile', async (asil) => ({
  ...(await asil<typeof import('@/api/aile')>()),
  aileApi: {
    silinenler: (t?: string, s?: number) => silinenler(t, s),
    silinenAyrinti: (id: number) => silinenAyrinti(id),
  },
}))
vi.mock('@/api/finans', async (asil) => ({
  ...(await asil<typeof import('@/api/finans')>()),
  finansApi: {
    sifreUnuttum: (e: string) => sifreUnuttum(e),
    sifreKodla: (v: Record<string, string>) => sifreKodla(v),
    sifreSifirla: (v: Record<string, string>) => sifreSifirla(v),
    kurtarmaKalan: () => kurtarmaKalan(),
    kurtarmaUret: (s: string) => kurtarmaUret(s),
  },
}))
vi.mock('vue-router', async (asil) => ({
  ...(await asil<typeof import('vue-router')>()),
  useRouter: () => ({ replace }),
  useRoute: () => ({ query: { token: 'jeton123', email: 'ben@ornek.test' } }),
}))

const stubs = {
  UygulamaPenceresi: { template: '<div><slot /></div>' },
  RouterLink: { template: '<a><slot /></a>' },
}
const plugins = [PrimeVue, ToastService]

const kayit = (id: number, alan: Partial<SilinenKaydi> = {}): SilinenKaydi => ({
  id,
  tur: 'gider',
  tur_etiketi: 'Gider',
  baslik: 'Simit · 12.11.2026',
  tutar: 40,
  olusturan_ad: 'Ayşe Test',
  olusturuldu_at: '2026-11-12T09:00:00+00:00',
  silen_ad: 'Ben Test',
  silindi_at: '2026-11-14T09:30:00+00:00',
  neden: null,
  alt_sayisi: 0,
  ...alan,
})

describe('Silinenler', () => {
  beforeEach(() => {
    silinenler.mockReset()
    silinenAyrinti.mockReset()
    setActivePinia(createPinia())
  })

  it('satırların üstü çizili; yapan ve silen ile zamanları görünür; tür çipleri süzer; ayrıntı açılır', async () => {
    silinenler.mockResolvedValue({
      kayitlar: [
        kayit(2),
        kayit(1, {
          tur: 'borc',
          tur_etiketi: 'Borç / alacak',
          baslik: 'Ahmet · borç',
          tutar: null,
        }),
      ],
      toplam: 2,
      sayfa: 1,
      sonraki: false,
      turler: { gider: 'Gider', borc: 'Borç / alacak', gelir: 'Gelir' },
      tur_sayilari: { gider: 1, borc: 1 },
    })
    silinenAyrinti.mockResolvedValue(
      kayit(2, {
        icerik: { aciklama: 'Simit', tutar: '40.00', hane_id: 1 },
        alt: [{ tarih: '2026-11-05', tutar: 10 }],
      }),
    )
    const { default: SilinenlerView } = await import('@/views/aile/SilinenlerView.vue')
    const w = mount(SilinenlerView, { global: { plugins, stubs } })
    await flushPromises()

    expect(w.findAll('[data-test="silinen-satir"]')).toHaveLength(2)
    expect(w.get('[data-test="silinen-baslik"]').classes()).toContain('line-through')
    const satir = w.get('[data-test="silinen-satir"]').text()
    expect(satir).toContain('Yapan: Ayşe Test')
    expect(satir).toContain('Silen: Ben Test')
    // Çipler: Tümü + yalnız kaydı olan türler (Gelir yok)
    const cipler = w.findAll('[data-test="silinen-cip"]').map((c) => c.text())
    expect(cipler).toEqual(['Tümü 2', 'Gider 1', 'Borç / alacak 1'])
    await w.findAll('[data-test="silinen-cip"]')[1]!.trigger('click')
    await flushPromises()
    expect(silinenler).toHaveBeenLastCalledWith('gider', 1)

    await w.get('[data-test="silinen-satir"] button').trigger('click')
    await flushPromises()
    const ayrinti = w.get('[data-test="silinen-ayrinti"]').text()
    expect(ayrinti).toContain('Simit')
    expect(ayrinti).not.toContain('hane id')
    expect(ayrinti).toContain('Bağlı kayıtlar (1)')
    w.unmount()
  })

  it('boş durum', async () => {
    silinenler.mockResolvedValue({
      kayitlar: [],
      toplam: 0,
      sayfa: 1,
      sonraki: false,
      turler: {},
      tur_sayilari: {},
    })
    const { default: SilinenlerView } = await import('@/views/aile/SilinenlerView.vue')
    const w = mount(SilinenlerView, { global: { plugins, stubs } })
    await flushPromises()
    expect(w.text()).toContain('Silinen kayıt yok')
    w.unmount()
  })
})

describe('Şifremi unuttum', () => {
  beforeEach(() => {
    sifreUnuttum.mockReset()
    sifreKodla.mockReset()
    sifreSifirla.mockReset()
    replace.mockReset()
  })

  it('e-posta sekmesi: genel yanıt gösterilir; 503 mesajı hata olarak çıkar', async () => {
    sifreUnuttum.mockResolvedValueOnce({ message: 'Bu e-posta kayıtlıysa bağlantı gönderildi.' })
    const { default: SifreUnuttumView } = await import('@/views/SifreUnuttumView.vue')
    const w = mount(SifreUnuttumView, { global: { plugins, stubs } })
    await w.get('[data-test="sifre-email"]').setValue('ben@ornek.test')
    await w.get('form').trigger('submit')
    await flushPromises()
    expect(sifreUnuttum).toHaveBeenCalledWith('ben@ornek.test')
    expect(w.get('[data-test="sifre-bilgi"]').text()).toContain('kayıtlıysa')

    sifreUnuttum.mockRejectedValueOnce(
      new AxiosError('503', '503', undefined, undefined, {
        status: 503,
        data: { message: 'E-posta gönderimi ayarlı değil; yöneticiye başvurun.' },
      } as never),
    )
    await w.get('form').trigger('submit')
    await flushPromises()
    expect(w.get('[data-test="sifre-hata"]').text()).toContain('yöneticiye başvurun')
    w.unmount()
  })

  it('kurtarma kodu sekmesi: kod + yeni şifre gönderilir, başarıda girişe yönlenir; şifreler uyuşmazsa pasif', async () => {
    sifreKodla.mockResolvedValue({ message: 'Şifreniz değişti.' })
    const { default: SifreUnuttumView } = await import('@/views/SifreUnuttumView.vue')
    const w = mount(SifreUnuttumView, { global: { plugins, stubs } })
    await w.findAll('[data-test="sifre-yol"] .p-togglebutton')[1]!.trigger('click')
    await w.get('[data-test="sifre-email"]').setValue('yon@ornek.test')
    await w.get('[data-test="sifre-kod"]').setValue('ABCD-EFGH-JKLM-NPQR')
    const sifreler = w.findAll('input[type="password"]')
    await sifreler[0]!.setValue('YeniSifre#2026x')
    await sifreler[1]!.setValue('baska')
    expect(w.get('[data-test="sifre-gonder"]').attributes('disabled')).toBeDefined()
    await sifreler[1]!.setValue('YeniSifre#2026x')
    await w.get('form').trigger('submit')
    await flushPromises()
    expect(sifreKodla).toHaveBeenCalledWith({
      email: 'yon@ornek.test',
      kod: 'ABCD-EFGH-JKLM-NPQR',
      yeni_sifre: 'YeniSifre#2026x',
      yeni_sifre_confirmation: 'YeniSifre#2026x',
    })
    expect(replace).toHaveBeenCalledWith({ name: 'giris' })
    w.unmount()
  })

  it('sıfırlama sayfası adresteki jeton ve e-postayla şifre belirler', async () => {
    sifreSifirla.mockResolvedValue({ message: 'Şifreniz değişti.' })
    const { default: SifreSifirlaView } = await import('@/views/SifreSifirlaView.vue')
    const w = mount(SifreSifirlaView, { global: { plugins, stubs } })
    const sifreler = w.findAll('input[type="password"]')
    await sifreler[0]!.setValue('YeniSifre#2026x')
    await sifreler[1]!.setValue('YeniSifre#2026x')
    await w.get('form').trigger('submit')
    await flushPromises()
    expect(sifreSifirla).toHaveBeenCalledWith({
      email: 'ben@ornek.test',
      token: 'jeton123',
      yeni_sifre: 'YeniSifre#2026x',
      yeni_sifre_confirmation: 'YeniSifre#2026x',
    })
    expect(replace).toHaveBeenCalledWith({ name: 'giris' })
    w.unmount()
  })
})

describe('Kurtarma kodları kartı', () => {
  beforeEach(() => {
    kurtarmaKalan.mockReset()
    kurtarmaUret.mockReset()
  })

  it('kalan sayı ve düşükse uyarı; üretince kodlar bir kez görünür, yazdım işaretlenmeden kapanmaz', async () => {
    kurtarmaKalan.mockResolvedValue({ kalan: 2, adet: 8 })
    kurtarmaUret.mockResolvedValue({
      kodlar: ['AAAA-BBBB-CCCC-DDDD', 'EEEE-FFFF-GGGG-HHHH'],
      message: 'ok',
    })
    const { default: KurtarmaKodlariKarti } = await import('@/components/KurtarmaKodlariKarti.vue')
    const w = mount(KurtarmaKodlariKarti, { global: { plugins, stubs } })
    await flushPromises()
    expect(w.get('[data-test="kurtarma-kalan"]').text()).toContain('Kalan kod: 2')
    expect(w.get('[data-test="kurtarma-kalan"]').text()).toContain('önerilir')

    await w.get('[data-test="kurtarma-ac"]').trigger('click')
    await w.get('input[type="password"]').setValue('password')
    await w.get('[data-test="kurtarma-uret"]').trigger('click')
    await flushPromises()
    expect(kurtarmaUret).toHaveBeenCalledWith('password')
    expect(w.findAll('[data-test="kurtarma-kodlar"] li').map((l) => l.text())).toEqual([
      'AAAA-BBBB-CCCC-DDDD',
      'EEEE-FFFF-GGGG-HHHH',
    ])
    expect(w.get('[data-test="kurtarma-kapat"]').attributes('disabled')).toBeDefined()
    await w.get('[data-test="kurtarma-yazdim"]').setValue(true)
    expect(w.get('[data-test="kurtarma-kapat"]').attributes('disabled')).toBeUndefined()
    w.unmount()
  })
})
