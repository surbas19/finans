import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

/** Ana bölüm: alt menüde / üst menüde seçili görünen */
export type Bolum = 'portfoy' | 'butce' | 'izleme' | 'profil' | 'yonetim'

declare module 'vue-router' {
  interface RouteMeta {
    baslik?: string
    /** Ana bölüm (üst rotadan miras alınır) */
    bolum?: Bolum
    /** Detay sayfası: üst çubukta ‹ geri düğmesi; tarayıcı geçmişi yoksa bu rotaya gider */
    geri?: string
    /** Giriş yapmadan açılır */
    misafir?: boolean
    yonetici?: boolean
  }
}

export const rotalar: RouteRecordRaw[] = [
  {
    path: '/giris',
    name: 'giris',
    component: () => import('@/views/GirisView.vue'),
    meta: { misafir: true, baslik: 'Giriş' },
  },
  {
    path: '/sifre-unuttum',
    name: 'sifre-unuttum',
    component: () => import('@/views/SifreUnuttumView.vue'),
    meta: { misafir: true, baslik: 'Şifremi unuttum' },
  },
  {
    path: '/sifre-sifirla',
    name: 'sifre-sifirla',
    component: () => import('@/views/SifreSifirlaView.vue'),
    meta: { misafir: true, baslik: 'Şifre sıfırla' },
  },
  {
    path: '/',
    component: () => import('@/layouts/AnaDuzen.vue'),
    children: [
      {
        path: '',
        name: 'ozet',
        component: () => import('@/views/OzetView.vue'),
        meta: { baslik: 'Özet', bolum: 'portfoy' },
      },
      {
        path: 'hesaplar',
        name: 'hesaplar',
        component: () => import('@/views/HesaplarView.vue'),
        meta: { baslik: 'Hesaplar', bolum: 'portfoy' },
      },
      {
        path: 'hesaplar/:id(\\d+)',
        name: 'hesap',
        component: () => import('@/views/HesapDetayView.vue'),
        props: (r) => ({ id: Number(r.params.id) }),
        meta: { baslik: 'Hesap', bolum: 'portfoy', geri: 'hesaplar' },
      },
      {
        path: 'izleme',
        name: 'izleme',
        component: () => import('@/views/IzlemeView.vue'),
        meta: { baslik: 'İzleme listesi', bolum: 'izleme' },
      },
      {
        path: 'varliklar/:id(\\d+)',
        name: 'varlik',
        component: () => import('@/views/VarlikDetayView.vue'),
        props: (r) => ({ id: Number(r.params.id) }),
        meta: { baslik: 'Varlık', bolum: 'izleme', geri: 'izleme' },
      },
      {
        path: 'islemler',
        name: 'islemler',
        component: () => import('@/views/IslemlerView.vue'),
        meta: { baslik: 'Portföy işlemleri', bolum: 'portfoy' },
      },
      {
        path: 'temettu',
        name: 'temettu',
        component: () => import('@/views/TemettuView.vue'),
        meta: { baslik: 'Temettü', bolum: 'portfoy' },
      },
      {
        // Aile finansı (Bütçe): hanenin gelirleri, kartları, ekstreleri, faturaları
        path: 'aile',
        component: () => import('@/layouts/AileDuzeni.vue'),
        meta: { bolum: 'butce' },
        children: [
          {
            path: '',
            name: 'aile',
            component: () => import('@/views/aile/AileOzetView.vue'),
            meta: { baslik: 'Bütçe' },
          },
          {
            // Gelir ve Gider tek sekmede, yana kaydırmalı iki panel
            path: 'gelir-gider',
            name: 'aile-gelir-gider',
            component: () => import('@/views/aile/GelirGiderView.vue'),
            meta: { baslik: 'Gelir / Gider' },
          },
          // Eski adresler yeni sekmeye yönlenir
          {
            path: 'gelirler',
            name: 'aile-gelirler',
            redirect: { name: 'aile-gelir-gider', query: { panel: 'gelir' } },
          },
          {
            path: 'giderler',
            name: 'aile-giderler',
            redirect: { name: 'aile-gelir-gider', query: { panel: 'gider' } },
          },
          {
            path: 'tasarruf',
            name: 'aile-tasarruf',
            component: () => import('@/views/aile/TasarrufView.vue'),
            meta: { baslik: 'Tasarruf' },
          },
          {
            path: 'borclar',
            name: 'aile-borclar',
            component: () => import('@/views/aile/BorclarView.vue'),
            meta: { baslik: 'Borç / Alacak' },
          },
          {
            path: 'kartlar',
            name: 'aile-kartlar',
            component: () => import('@/views/aile/KartlarView.vue'),
            meta: { baslik: 'Kartlar' },
          },
          {
            path: 'kartlar/:id(\\d+)',
            name: 'aile-kart',
            component: () => import('@/views/aile/KartDetayView.vue'),
            props: (r) => ({ id: Number(r.params.id) }),
            meta: { baslik: 'Kart', geri: 'aile-kartlar' },
          },
          {
            path: 'ekstreler',
            name: 'aile-ekstreler',
            component: () => import('@/views/aile/EkstrelerView.vue'),
            meta: { baslik: 'Ekstreler', geri: 'aile-kartlar' },
          },
          {
            path: 'hesap-ekstreleri/:id(\\d+)',
            name: 'aile-hesap-ekstresi',
            component: () => import('@/views/aile/HesapEkstresiView.vue'),
            props: (r) => ({ id: Number(r.params.id) }),
            meta: { baslik: 'Banka hesabı ekstresi', geri: 'aile-ekstreler' },
          },
          {
            path: 'ekstreler/:id(\\d+)',
            name: 'aile-ekstre',
            component: () => import('@/views/aile/EkstreView.vue'),
            props: (r) => ({ id: Number(r.params.id) }),
            meta: { baslik: 'Ekstre', geri: 'aile-ekstreler' },
          },
          {
            path: 'islemler',
            name: 'aile-islemler',
            component: () => import('@/views/aile/AileIslemlerView.vue'),
            meta: { baslik: 'Kart işlemleri' },
          },
          {
            path: 'faturalar',
            name: 'aile-faturalar',
            component: () => import('@/views/aile/FaturalarView.vue'),
            meta: { baslik: 'Faturalar' },
          },
          {
            // Zekât Yönetim'in bir sekmesidir (yalnızca yönetici); eski adres oraya yönlenir
            path: 'zekat',
            name: 'aile-zekat',
            redirect: { name: 'yonetim', query: { sekme: 'zekat' } },
          },
          {
            path: 'silinenler',
            name: 'aile-silinenler',
            component: () => import('@/views/aile/SilinenlerView.vue'),
            meta: { baslik: 'Silinenler', geri: 'aile-ayarlar' },
          },
          {
            path: 'ayarlar',
            name: 'aile-ayarlar',
            component: () => import('@/views/aile/AileAyarlarView.vue'),
            meta: { baslik: 'Bütçe ayarları' },
          },
        ],
      },
      {
        path: 'profil',
        name: 'profil',
        component: () => import('@/views/ProfilView.vue'),
        meta: { baslik: 'Profil', bolum: 'profil' },
      },
      {
        path: 'yonetim',
        name: 'yonetim',
        component: () => import('@/views/YonetimView.vue'),
        meta: { baslik: 'Yönetim', yonetici: true, bolum: 'yonetim' },
      },
    ],
  },
  { path: '/:yol(.*)*', redirect: { name: 'ozet' } },
]

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: rotalar,
  scrollBehavior: (_to, _from, kayitli) => kayitli ?? { top: 0 },
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  await auth.denetle()

  if (to.meta.misafir) {
    return auth.kullanici ? { name: 'ozet' } : true
  }
  if (!auth.kullanici) {
    return { name: 'giris', query: to.fullPath !== '/' ? { geri: to.fullPath } : {} }
  }
  if (to.meta.yonetici && !auth.yonetici) {
    return { name: 'ozet' }
  }

  return true
})

router.afterEach((to) => {
  document.title = to.meta.baslik ? `${to.meta.baslik} · Finans` : 'Finans'
})

export default router
