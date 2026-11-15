import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import PrimeVue from 'primevue/config'
import type { Pozisyon } from '@/api/finans'
import PozisyonListesi from '@/components/PozisyonListesi.vue'

function poz(id: number, dahil: boolean, hesapId: number | null = 1): Pozisyon {
  return {
    dahil,
    hesap_id: hesapId,
    varlik: {
      id,
      tur: 'altin',
      kod: `GRAM${id}`,
      ad: `Gram altın ${id}`,
      birim: 'gr',
      para_birimi: 'TRY',
      kaynak: 'elle',
      gecikmeli: false,
      fiyat: null,
    },
    miktar: 10,
    maliyeti_bilinmeyen_miktar: 0,
    ortalama_maliyet: 2000,
    maliyet: 20000,
    fiyat_tl: 3000,
    deger: 30000,
    kz: 10000,
    kz_yuzde: 50,
    gunluk_degisim: null,
    gerceklesen_kz: null,
    gerceklesen_kz_eksik: false,
  }
}

const genel = { plugins: [PrimeVue], stubs: { RouterLink: { template: '<a><slot /></a>' } } }

describe('Yatırım satırı: genel toplama dahil düğmesi', () => {
  it('düğme yalnız degistirilebilir listede ve hesaba bağlı satırda görünür', () => {
    const kapali = mount(PozisyonListesi, { props: { pozisyonlar: [poz(1, true)] }, global: genel })
    expect(kapali.find('[data-test="dahil-dugmesi"]').exists()).toBe(false)

    const acik = mount(PozisyonListesi, {
      props: { pozisyonlar: [poz(1, true), poz(2, true, null)], degistirilebilir: true },
      global: genel,
    })
    // hesap_id olmayan (hesaplar arası toplanmış) satırda düğme yok
    expect(acik.findAll('[data-test="dahil-dugmesi"]')).toHaveLength(1)
  })

  it('hariç satır soluk, "Hariç" etiketli; düğme tersini ister', async () => {
    const w = mount(PozisyonListesi, {
      props: { pozisyonlar: [poz(1, true), poz(2, false)], degistirilebilir: true },
      global: genel,
    })
    const satirlar = w.findAll('li')
    expect(satirlar[0]!.attributes('data-dahil')).toBe('evet')
    expect(satirlar[0]!.classes()).not.toContain('opacity-55')
    expect(satirlar[0]!.find('[data-test="dahil-dugmesi"]').text()).toBe('Dahil')
    expect(satirlar[0]!.find('[data-test="haric-etiketi"]').exists()).toBe(false)

    expect(satirlar[1]!.attributes('data-dahil')).toBe('hayir')
    expect(satirlar[1]!.classes()).toContain('opacity-55')
    expect(satirlar[1]!.find('[data-test="haric-etiketi"]').exists()).toBe(true)
    expect(satirlar[1]!.find('[data-test="dahil-dugmesi"]').text()).toBe('Hariç')

    await satirlar[0]!.find('[data-test="dahil-dugmesi"]').trigger('click')
    await satirlar[1]!.find('[data-test="dahil-dugmesi"]').trigger('click')
    expect(w.emitted('dahilDegistir')).toEqual([
      [expect.objectContaining({ dahil: true }), false], // dahilse → çıkar
      [expect.objectContaining({ dahil: false }), true], // hariçse → ekle
    ])
  })

  it('usePozisyonDahil: ucu çağırır ve sayfayı yeniler', async () => {
    const dahil =
      vi.fn<(h: number, v: number, d: boolean) => Promise<{ dahil: boolean; message: string }>>()
    dahil.mockResolvedValue({ dahil: false, message: 'Genel toplamdan çıkarıldı.' })
    vi.doMock('@/api/finans', async (asil) => ({
      ...(await asil<typeof import('@/api/finans')>()),
      finansApi: { pozisyonDahil: dahil },
    }))
    vi.doMock('primevue/usetoast', () => ({ useToast: () => ({ add: vi.fn<() => void>() }) }))
    const { usePozisyonDahil } = await import('@/composables/usePozisyonDahil')
    const yenile = vi.fn<() => void>()
    await usePozisyonDahil(yenile)(poz(5, true, 3), false)
    expect(dahil).toHaveBeenCalledWith(3, 5, false)
    expect(yenile).toHaveBeenCalledOnce()
    vi.doUnmock('@/api/finans')
    vi.doUnmock('primevue/usetoast')
  })
})
