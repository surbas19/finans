import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import KisiEtiketi from '@/components/KisiEtiketi.vue'
import KisiResmi from '@/components/KisiResmi.vue'
import { kareAlan } from '@/utils/profilResmi'

describe('kareAlan: fotoğrafın ortasından kare', () => {
  it('yatay, dikey ve kare fotoğraf', () => {
    expect(kareAlan(4000, 3000)).toEqual({ x: 500, y: 0, kenar: 3000 })
    expect(kareAlan(1080, 1920)).toEqual({ x: 0, y: 420, kenar: 1080 })
    expect(kareAlan(256, 256)).toEqual({ x: 0, y: 0, kenar: 256 })
    expect(kareAlan(101, 100)).toEqual({ x: 0, y: 0, kenar: 100 })
  })
})

describe('KisiResmi', () => {
  it('resim varsa yuvarlak resim', () => {
    const w = mount(KisiResmi, {
      props: { ad: 'Ali Yılmaz', resim: '/api/v1/kullanicilar/1/resim?v=a' },
    })
    const img = w.get('[data-test="kisi-resmi"]')
    expect(img.attributes('src')).toBe('/api/v1/kullanicilar/1/resim?v=a')
    expect(img.classes()).toContain('rounded-full')
    expect(w.find('[data-test="kisi-bas-harf"]').exists()).toBe(false)
  })

  it('resim yoksa baş harfler (Türkçe büyük harf), renk verilirse o renk', () => {
    const w = mount(KisiResmi, { props: { ad: 'irem şahin', renk: 'teal' } })
    const harf = w.get('[data-test="kisi-bas-harf"]')
    expect(harf.text()).toBe('İŞ')
    expect(harf.attributes('style')).toContain('--p-teal-500')
    expect(mount(KisiResmi, { props: { ad: '' } }).text()).toBe('?')
  })

  it('resim açılamazsa baş harfe düşer; yeni resim gelince yeniden dener', async () => {
    const w = mount(KisiResmi, { props: { ad: 'Deniz', resim: '/bozuk' } })
    await w.get('[data-test="kisi-resmi"]').trigger('error')
    expect(w.get('[data-test="kisi-bas-harf"]').text()).toBe('D')
    await w.setProps({ resim: '/yeni' })
    expect(w.get('[data-test="kisi-resmi"]').attributes('src')).toBe('/yeni')
  })
})

describe('KisiEtiketi', () => {
  it('kişi varsa küçük resim + ad, yoksa hiçbir şey', () => {
    const w = mount(KisiEtiketi, { props: { kisi: { ad: 'Eşim', resim: null, renk: 'rose' } } })
    expect(w.text()).toContain('Eşim')
    expect(w.find('[data-test="kisi-bas-harf"]').exists()).toBe(true)
    expect(mount(KisiEtiketi, { props: { kisi: undefined } }).text()).toBe('')
  })
})
