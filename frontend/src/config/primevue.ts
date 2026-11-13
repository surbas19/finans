import Aura from '@primeuix/themes/aura'
import { definePreset } from '@primeuix/themes'
import type { PrimeVueConfiguration } from 'primevue/config'

/**
 * Görünüm: turkuaz ana renk (okul sistemiyle aynı görünüm dili), yumuşak ve yuvarlak köşeler, hap biçimli sekme ve etiketler (okul sisteminin docs/TASARIM.md belgesi).
 */
const FinansTemasi = definePreset(Aura, {
  primitive: {
    borderRadius: { none: '0', xs: '4px', sm: '8px', md: '12px', lg: '14px', xl: '18px' },
  },
  semantic: {
    primary: {
      50: '{teal.50}',
      100: '{teal.100}',
      200: '{teal.200}',
      300: '{teal.300}',
      400: '{teal.400}',
      500: '{teal.500}',
      600: '{teal.600}',
      700: '{teal.700}',
      800: '{teal.800}',
      900: '{teal.900}',
      950: '{teal.950}',
    },
    colorScheme: {
      light: {
        // Ana eylemler bir ton koyu: beyaz yazı turkuaz üzerinde okunaklı kalsın.
        primary: {
          color: '{primary.600}',
          contrastColor: '#ffffff',
          hoverColor: '{primary.700}',
          activeColor: '{primary.800}',
        },
      },
    },
  },
  components: {
    button: {
      root: { borderRadius: '{border.radius.xl}', label: { fontWeight: '600' } },
    },
    tag: {
      root: { borderRadius: '999px', padding: '0.25rem 0.625rem', fontSize: '0.75rem' },
    },
    selectbutton: {
      root: { borderRadius: '999px' },
    },
    togglebutton: {
      root: { borderRadius: '999px', padding: '0.25rem', fontWeight: '600' },
      content: { borderRadius: '999px', padding: '0.375rem 0.875rem' },
      colorScheme: {
        light: {
          root: { checkedColor: '{primary.contrast.color}' },
          content: { checkedBackground: '{primary.color}' },
          icon: { checkedColor: '{primary.contrast.color}' },
        },
      },
    },
    card: {
      root: { borderRadius: '1.5rem' },
    },
    dialog: {
      root: { borderRadius: '1.5rem' },
    },
  },
})

export const primeVueAyarlari: PrimeVueConfiguration = {
  theme: {
    preset: FinansTemasi,
    options: {
      // Koyu tema şimdilik kapalı; ileride <html class="app-dark"> ile açılabilir.
      darkModeSelector: '.app-dark',
      cssLayer: { name: 'primevue', order: 'theme, base, primevue, components, utilities' },
    },
  },
  locale: {
    startsWith: 'İle başlar',
    contains: 'İçerir',
    notContains: 'İçermez',
    endsWith: 'İle biter',
    equals: 'Eşittir',
    notEquals: 'Eşit değil',
    noFilter: 'Filtre yok',
    lt: 'Küçüktür',
    lte: 'Küçük veya eşit',
    gt: 'Büyüktür',
    gte: 'Büyük veya eşit',
    dateIs: 'Tarih',
    dateIsNot: 'Tarih değil',
    dateBefore: 'Öncesi',
    dateAfter: 'Sonrası',
    clear: 'Temizle',
    apply: 'Uygula',
    matchAll: 'Tümü eşleşsin',
    matchAny: 'Herhangi biri eşleşsin',
    addRule: 'Kural ekle',
    removeRule: 'Kuralı kaldır',
    accept: 'Evet',
    reject: 'Hayır',
    choose: 'Seç',
    upload: 'Yükle',
    cancel: 'Vazgeç',
    completed: 'Tamamlandı',
    pending: 'Bekliyor',
    fileSizeTypes: ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'],
    dayNames: ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'],
    dayNamesShort: ['Paz', 'Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt'],
    dayNamesMin: ['Pz', 'Pt', 'Sa', 'Ça', 'Pe', 'Cu', 'Ct'],
    monthNames: [
      'Ocak',
      'Şubat',
      'Mart',
      'Nisan',
      'Mayıs',
      'Haziran',
      'Temmuz',
      'Ağustos',
      'Eylül',
      'Ekim',
      'Kasım',
      'Aralık',
    ],
    monthNamesShort: [
      'Oca',
      'Şub',
      'Mar',
      'Nis',
      'May',
      'Haz',
      'Tem',
      'Ağu',
      'Eyl',
      'Eki',
      'Kas',
      'Ara',
    ],
    chooseYear: 'Yıl seç',
    chooseMonth: 'Ay seç',
    chooseDate: 'Tarih seç',
    prevYear: 'Önceki yıl',
    nextYear: 'Sonraki yıl',
    prevMonth: 'Önceki ay',
    nextMonth: 'Sonraki ay',
    today: 'Bugün',
    weekHeader: 'Hf',
    firstDayOfWeek: 1,
    dateFormat: 'dd.mm.yy',
    weak: 'Zayıf',
    medium: 'Orta',
    strong: 'Güçlü',
    passwordPrompt: 'Şifre girin',
  },
}
