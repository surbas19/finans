import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'

const giderKaynaklari = vi.fn<() => Promise<unknown[]>>()
const kaynaklar = vi.fn<() => Promise<unknown[]>>()
vi.mock('@/api/aile', async (asil) => ({
  ...(await asil<typeof import('@/api/aile')>()),
  aileApi: {
    giderKaynaklari: () => giderKaynaklari(),
    kaynaklar: () => kaynaklar(),
    hane: () => Promise.resolve({ hane: { id: 1, ad: 'Aile' }, uyeler: [], ben_uye_id: 1 }),
    bankalar: () => Promise.resolve([]),
    kartlar: () => Promise.resolve([]),
    kategoriler: () => Promise.resolve([]),
    faturaTurleri: () => Promise.resolve([]),
    saglayicilar: () => Promise.resolve([]),
    gelirTurleri: () => Promise.resolve([]),
  },
}))

/** Formların yerine: görünürlüğü, başlığı ve gelen kaynak sayısını işaretler; kaydedildi olayı yayabilir */
const sahte = (ad: string) =>
  defineComponent({
    name: ad,
    props: {
      visible: Boolean,
      baslik: { type: String, default: '' },
      kaynaklar: { type: Array, default: () => [] },
    },
    emits: ['kaydedildi', 'update:visible'],
    setup(p) {
      return () =>
        h('div', {
          'data-form': ad,
          'data-visible': String(p.visible),
          'data-baslik': p.baslik,
          'data-kaynak': String(p.kaynaklar.length),
        })
    },
  })
const Nakit = sahte('NakitHarcamaFormu')
const Tasarruf = sahte('TasarrufFormu')
const Ekstre = sahte('EkstreYukleDialog')
const Gelir = sahte('GelirFormu')
const Borc = sahte('BorcFormu')
const Popover = defineComponent({
  setup(_, { slots, expose }) {
    expose({ toggle: () => {}, hide: () => {} })

    return () => h('div', { 'data-test': 'popover' }, slots.default?.())
  },
})

async function kur() {
  const { default: HizliIslemler } = await import('@/components/HizliIslemler.vue')
  setActivePinia(createPinia())
  const w = mount(HizliIslemler, {
    global: {
      plugins: [PrimeVue, ToastService],
      stubs: {
        NakitHarcamaFormu: Nakit,
        TasarrufFormu: Tasarruf,
        EkstreYukleDialog: Ekstre,
        GelirFormu: Gelir,
        BorcFormu: Borc,
        Popover,
      },
    },
  })
  await flushPromises()

  return w
}

const gorunur = (w: Awaited<ReturnType<typeof kur>>, ad: string): string | undefined =>
  w.find(`[data-form="${ad}"]`).attributes('data-visible')

describe('Hızlı işlemler menüsü', () => {
  beforeEach(() => {
    giderKaynaklari.mockReset()
    kaynaklar.mockReset()
    giderKaynaklari.mockResolvedValue([{ id: 1 }, { id: 2 }])
    kaynaklar.mockResolvedValue([{ id: 9 }])
  })

  it('düğme var; menüde altı işlem, ikon + ad + açıklama satırıyla', async () => {
    const w = await kur()
    expect(w.get('[data-test="hizli-dugme"]').attributes('aria-label')).toBe('Hızlı işlemler')
    const satirlar = w.findAll('[role="menuitem"]').map((e) => e.text())
    expect(satirlar).toHaveLength(6)
    expect(satirlar[0]).toContain('Nakit harcama')
    expect(satirlar[0]).toContain('Kartsız gideri hemen yaz')
    expect(satirlar[1]).toContain('Tasarruf')
    expect(satirlar[2]).toContain('Ekstre yükle')
    expect(satirlar[3]).toContain('Gelir ekle')
    expect(satirlar[4]).toContain('Borç / alacak')
    expect(satirlar[5]).toContain('Portföy özeti')
    // Başlangıçta hiçbir form açık değil
    for (const f of [
      'NakitHarcamaFormu',
      'TasarrufFormu',
      'EkstreYukleDialog',
      'GelirFormu',
      'BorcFormu',
    ]) {
      expect(gorunur(w, f)).toBe('false')
    }
    w.unmount()
  })

  it('nakit harcama: sade hızlı form açılır (gider kaynağı yüklenmez)', async () => {
    const w = await kur()
    await w.get('[data-test="hizli-nakit-harcama"]').trigger('click')
    await flushPromises()
    expect(giderKaynaklari).not.toHaveBeenCalled()
    expect(gorunur(w, 'NakitHarcamaFormu')).toBe('true')
    expect(gorunur(w, 'GelirFormu')).toBe('false')
    w.unmount()
  })

  it('gelir ekle: gelir kaynakları yüklenir; tasarruf ve ekstre yükle kendi formlarını açar', async () => {
    const w = await kur()
    await w.get('[data-test="hizli-gelir-ekle"]').trigger('click')
    await flushPromises()
    expect(kaynaklar).toHaveBeenCalledTimes(1)
    expect(gorunur(w, 'GelirFormu')).toBe('true')
    expect(w.get('[data-form="GelirFormu"]').attributes('data-kaynak')).toBe('1')
    await w.get('[data-test="hizli-tasarruf"]').trigger('click')
    await flushPromises()
    expect(gorunur(w, 'TasarrufFormu')).toBe('true')
    await w.get('[data-test="hizli-ekstre-yukle"]').trigger('click')
    await flushPromises()
    expect(gorunur(w, 'EkstreYukleDialog')).toBe('true')
    w.unmount()
  })

  it('borç / alacak: kendi formu açılır', async () => {
    const w = await kur()
    await w.get('[data-test="hizli-borc-alacak"]').trigger('click')
    await flushPromises()
    expect(gorunur(w, 'BorcFormu')).toBe('true')
    w.unmount()
  })

  it('form kaydedilince kaydedildi olayı yayılır (açık sayfa yenilensin)', async () => {
    const w = await kur()
    w.findComponent(Nakit).vm.$emit('kaydedildi')
    w.findComponent(Tasarruf).vm.$emit('kaydedildi')
    w.findComponent(Gelir).vm.$emit('kaydedildi')
    expect(w.emitted('kaydedildi')).toHaveLength(3)
    w.unmount()
  })

  it('veri yüklenemezse form açılmaz', async () => {
    kaynaklar.mockRejectedValue(new Error('hata'))
    const w = await kur()
    await w.get('[data-test="hizli-gelir-ekle"]').trigger('click')
    await flushPromises()
    expect(gorunur(w, 'GelirFormu')).toBe('false')
    w.unmount()
  })
})
