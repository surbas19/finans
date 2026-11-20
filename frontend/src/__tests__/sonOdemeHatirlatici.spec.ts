import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import type { Ekstre, Kart } from '@/api/aile'
import SonOdemeHatirlaticilari from '@/components/aile/SonOdemeHatirlaticilari.vue'
import { useGeriAl } from '@/composables/useGeriAl'
import { useAileStore } from '@/stores/aile'

const gun = (fark: number): string => {
  const d = new Date()
  d.setDate(d.getDate() + fark)

  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function ekstre(id: number, kartId: number, ek: Partial<Ekstre> = {}): Ekstre {
  return {
    id,
    kart_id: kartId,
    durum: 'onaylandi',
    son_odeme_tarihi: gun(id),
    donem_borcu: 1000 * id + 0.1,
    asgari_odeme: 400 * id,
    odendi: false,
    toplamda: false,
    ...ek,
  } as unknown as Ekstre
}

const kart = (id: number, ad: string, sira: number | null = null): Kart =>
  ({
    id,
    ad,
    banka_id: 1,
    sahip_uye_id: 1,
    renk: null,
    aktif: true,
    hatirlatici_sira: sira,
  }) as Kart

const guncelle = vi.fn<(id: number, veri: Record<string, unknown>) => Promise<{ ekstre: Ekstre }>>()
const siraKaydet = vi.fn<(idler: number[]) => Promise<unknown>>()
vi.mock('@/api/aile', async (asil) => ({
  ...(await asil<typeof import('@/api/aile')>()),
  aileApi: {
    ekstreGuncelle: (id: number, veri: Record<string, unknown>) => guncelle(id, veri),
    kartHatirlaticiSirasi: (idler: number[]) => siraKaydet(idler),
  },
}))

describe('Kart borç hatırlatıcısı: sıra, Borç öde, toplam', () => {
  let ekstreler: Ekstre[]

  beforeEach(() => {
    setActivePinia(createPinia())
    useAileStore().kartlar = [
      kart(1, 'Bonus'),
      kart(2, 'Maximum'),
      kart(3, 'World'),
      kart(4, 'Axess', 5),
    ]
    ekstreler = [ekstre(1, 1), ekstre(2, 2), ekstre(3, 3)]
    guncelle.mockReset().mockImplementation((id, veri) => {
      const e = ekstreler.find((x) => x.id === id)!

      return Promise.resolve({ ekstre: { ...e, ...veri } as Ekstre })
    })
    siraKaydet.mockReset().mockResolvedValue({ message: 'Sıra kaydedildi.' })
  })

  function kur() {
    // Sayfa gibi: güncellenen ekstre listeye geri yazılır
    const degisti = (yeni: Ekstre): void => {
      ekstreler = ekstreler.map((e) => (e.id === yeni.id ? yeni : e))
      void w.setProps({ ekstreler, onDegisti: degisti })
    }
    const w = mount(SonOdemeHatirlaticilari, {
      props: { ekstreler, onDegisti: degisti },
      global: {
        plugins: [PrimeVue, ToastService],
        stubs: { RouterLink: { template: '<a><slot /></a>' } },
      },
    })

    return w
  }
  const adlar = (w: ReturnType<typeof kur>) =>
    w.findAll('[data-test="son-odeme-satiri"]').map((s) => s.text().split('son ödeme')[0]!.trim())

  it('hanenin sırası önce, sırası olmayan kartlar sonda ve tarihe göre; asgari görünür', async () => {
    useAileStore().kartlar = [kart(1, 'Bonus'), kart(2, 'Maximum', 1), kart(3, 'World', 0)]
    const w = kur()
    expect(adlar(w)).toEqual(['World', 'Maximum', 'Bonus'])
    expect(w.text()).toContain('asgari ₺400,00')
  })

  it('Düzenle: tutamakla (klavye ↓) yer değişir ve kart sırası kaydedilir; hatırlatıcısı olmayan kartın sırası korunur', async () => {
    const w = kur()
    expect(adlar(w)).toEqual(['Bonus', 'Maximum', 'World'])
    await w.get('[data-test="son-odeme-duzenle"]').trigger('click')
    expect(w.find('[data-test="son-odeme-ode"]').exists()).toBe(false)
    await w.findAll('[data-test="son-odeme-tutamak"]')[0]!.trigger('keydown', { key: 'ArrowDown' })
    await flushPromises()
    expect(adlar(w)).toEqual(['Maximum', 'Bonus', 'World'])
    expect(siraKaydet).toHaveBeenCalledWith([2, 1, 3, 4])
    await w.get('[data-test="son-odeme-duzenle"]').trigger('click')
    expect(adlar(w)).toEqual(['Maximum', 'Bonus', 'World'])
  })

  it('Borç öde: ödendi işaretlenir, satır düşer; Geri al geri getirir', async () => {
    const w = kur()
    await w.findAll('[data-test="son-odeme-ode"]')[0]!.trigger('click')
    await flushPromises()
    expect(guncelle).toHaveBeenCalledWith(1, { odendi: true })
    expect(adlar(w)).toEqual(['Maximum', 'World'])
    const { bildirim, kapat } = useGeriAl()
    expect(bildirim.value?.mesaj).toBe('Bonus borcu ödendi')
    await bildirim.value!.geriAl()
    await flushPromises()
    expect(guncelle).toHaveBeenLastCalledWith(1, { odendi: false })
    expect(adlar(w)).toEqual(['Bonus', 'Maximum', 'World'])
    kapat()
  })

  it('Toplama ekle: seçilenlerin borç ve asgari toplamı kuruşla; Temizle hepsini çıkarır', async () => {
    const w = kur()
    expect(w.find('[data-test="son-odeme-toplami"]').exists()).toBe(false)
    const dugmeler = () => w.findAll('[data-test="son-odeme-toplam"]')
    await dugmeler()[0]!.trigger('click')
    await flushPromises()
    await dugmeler()[2]!.trigger('click')
    await flushPromises()
    expect(guncelle).toHaveBeenCalledWith(3, { toplamda: true })
    expect(dugmeler()[0]!.text()).toBe('✓ Toplamda')
    const cubuk = w.get('[data-test="son-odeme-toplami"]').text()
    expect(cubuk).toContain('Seçilen 2 kart')
    expect(cubuk).toContain('₺4.000,20') // 1000,10 + 3000,10
    expect(cubuk).toContain('₺1.600,00')
    await w.get('[data-test="son-odeme-temizle"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-test="son-odeme-toplami"]').exists()).toBe(false)
  })
})
