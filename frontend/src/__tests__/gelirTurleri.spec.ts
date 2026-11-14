import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { AxiosError, type AxiosResponse } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'

const gelirTuruEkle = vi.fn<(ad: string) => Promise<unknown>>()
vi.mock('@/api/aile', async (asil) => ({
  ...(await asil<typeof import('@/api/aile')>()),
  aileApi: { gelirTuruEkle: (ad: string) => gelirTuruEkle(ad) },
}))

async function kur(secim: number | null = 1) {
  // Bileşen ve store, sahte API kurulduktan sonra yüklenir (aile.spec.ts'teki gibi)
  const { default: GelirTuruSecici } = await import('@/components/aile/GelirTuruSecici.vue')
  const { useAileStore } = await import('@/stores/aile')
  setActivePinia(createPinia())
  const aile = useAileStore()
  aile.gelirTurleri = [
    { id: 1, ad: 'Maaş', sira: 1 },
    { id: 2, ad: 'Ek iş', sira: 2 },
  ]
  const sayfa = mount(GelirTuruSecici, {
    props: { inputId: 'gelir-tur', modelValue: secim },
    global: { plugins: [PrimeVue] },
    attachTo: document.body,
  })

  return { aile, sayfa }
}

describe('GelirTuruSecici', () => {
  beforeEach(() => {
    gelirTuruEkle.mockReset()
    // jsdom'da yok; PrimeVue Select ekran yönünü dinler
    vi.stubGlobal('matchMedia', () => ({
      matches: false,
      addEventListener: () => {},
      removeEventListener: () => {},
    }))
  })

  it('listede olmayan türü yerinde ekler ve seçer', async () => {
    gelirTuruEkle.mockResolvedValue({ id: 9, ad: 'Ek ders', sira: 3 })
    const { aile, sayfa } = await kur()
    await sayfa.get('button.text-primary-600').trigger('click')
    const girdi = sayfa.get('input#gelir-tur')
    await girdi.setValue('  Ek ders ')
    await girdi.trigger('keydown', { key: 'Enter' })
    await flushPromises()

    expect(gelirTuruEkle).toHaveBeenCalledWith('Ek ders')
    expect(aile.gelirTurleri.map((t) => t.ad)).toEqual(['Maaş', 'Ek iş', 'Ek ders'])
    const secimler = sayfa.emitted('update:modelValue') ?? []
    expect(secimler[secimler.length - 1]).toEqual([9])
    // Ekleme kapanır, seçim listesi döner
    expect(sayfa.find('input#gelir-tur').exists()).toBe(false)
    sayfa.unmount()
  })

  it('sunucu reddederse mesaj gösterir, seçim değişmez', async () => {
    gelirTuruEkle.mockRejectedValue(
      new AxiosError('422', '422', undefined, undefined, {
        status: 422,
        data: { message: 'Bu gelir türü zaten var.' },
      } as AxiosResponse),
    )
    const { aile, sayfa } = await kur()
    await sayfa.get('button.text-primary-600').trigger('click')
    await sayfa.get('input#gelir-tur').setValue('ek iş')
    await sayfa.get('button[aria-label="Türü ekle"]').trigger('click')
    await flushPromises()

    expect(sayfa.text()).toContain('Bu gelir türü zaten var.')
    expect(sayfa.emitted('update:modelValue')).toBeUndefined()
    expect(aile.gelirTurleri).toHaveLength(2)
    sayfa.unmount()
  })
})
