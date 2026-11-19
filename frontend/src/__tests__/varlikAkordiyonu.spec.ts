import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import PrimeVue from 'primevue/config'
import VarlikAkordiyonu from '@/components/VarlikAkordiyonu.vue'
import type { Pozisyon, VarlikTuru } from '@/api/finans'

function poz(
  id: number,
  tur: VarlikTuru,
  deger: number | null,
  ek: Partial<Pozisyon> = {},
): Pozisyon {
  return {
    hesap_id: 1,
    varlik: { id, tur, kod: `V${id}`, ad: `Varlık ${id}`, birim: 'adet' },
    miktar: 1,
    maliyeti_bilinmeyen_miktar: 0,
    ortalama_maliyet: 1,
    maliyet: deger,
    fiyat_tl: deger,
    deger,
    kz: 100,
    kz_yuzde: 1,
    gunluk_degisim: 0,
    gerceklesen_kz: 0,
    gerceklesen_kz_eksik: false,
    dahil: true,
    ...ek,
  } as Pozisyon
}

const kur = (pozisyonlar: Pozisyon[]) =>
  mount(VarlikAkordiyonu, {
    props: { pozisyonlar },
    global: { plugins: [PrimeVue], stubs: { RouterLink: { template: '<a><slot /></a>' } } },
  })

describe('VarlikAkordiyonu', () => {
  it('türe göre gruplar, değeri büyükten küçüğe; başta hepsi kapalı', () => {
    const w = kur([
      poz(1, 'hisse', 300),
      poz(2, 'altin', 500),
      poz(3, 'altin', 100),
      poz(4, 'nakit', 100, { kz: null }),
    ])
    const gruplar = w.findAll('[data-test="varlik-grubu"]')
    expect(gruplar).toHaveLength(3)
    expect(w.findAll('[data-test="grup-deger"]').map((e) => e.text())).toEqual([
      '₺600,00',
      '₺300,00',
      '₺100,00',
    ])
    const altin = w.get('[data-test="varlik-grubu-altin"]').text()
    expect(altin).toContain('Altın / gümüş')
    expect(altin).toContain('2 varlık · %60')
    expect(w.findAll('[data-test="pay-cubugu"]')[0]!.attributes('style')).toContain('width: 60%')
    expect(w.findAll('.p-accordionpanel-active')).toHaveLength(0)
    // Nakitte K/Z gösterilmez
    expect(w.get('[data-test="varlik-grubu-nakit"]').text()).not.toContain('K/Z')
  })

  it('hariç satır ve fiyatsız değer toplama girmez; maliyeti bilinmeyen satır grubu "K/Z —" yapar', () => {
    const w = kur([
      poz(1, 'altin', 400),
      poz(2, 'altin', 600, { dahil: false }),
      poz(3, 'hisse', null),
      poz(4, 'doviz', 200, { kz: null }),
    ])
    const altin = w.get('[data-test="varlik-grubu-altin"]').text()
    expect(altin).toContain('₺400,00')
    expect(altin).toContain('2 varlık')
    expect(w.get('[data-test="varlik-grubu-doviz"]').text()).toContain('K/Z —')
    expect(w.get('[data-test="varlik-grubu-hisse"]').text()).toContain('₺0,00')
  })

  it('başlığa dokununca grup açılır ve satırlar görünür', async () => {
    const w = kur([poz(1, 'altin', 500), poz(2, 'hisse', 300)])
    await w.get('[data-test="varlik-grubu-hisse"]').trigger('click')
    expect(w.findAll('.p-accordionpanel-active')).toHaveLength(1)
    expect(w.text()).toContain('V2')
  })
})
