import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

declare module 'vue-router' {
  interface RouteMeta {
    baslik?: string
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
    path: '/',
    component: () => import('@/layouts/AnaDuzen.vue'),
    children: [
      {
        path: '',
        name: 'ozet',
        component: () => import('@/views/OzetView.vue'),
        meta: { baslik: 'Özet' },
      },
      {
        path: 'hesaplar',
        name: 'hesaplar',
        component: () => import('@/views/HesaplarView.vue'),
        meta: { baslik: 'Hesaplar' },
      },
      {
        path: 'hesaplar/:id(\\d+)',
        name: 'hesap',
        component: () => import('@/views/HesapDetayView.vue'),
        props: (r) => ({ id: Number(r.params.id) }),
        meta: { baslik: 'Hesap' },
      },
      {
        path: 'izleme',
        name: 'izleme',
        component: () => import('@/views/IzlemeView.vue'),
        meta: { baslik: 'İzleme listesi' },
      },
      {
        path: 'varliklar/:id(\\d+)',
        name: 'varlik',
        component: () => import('@/views/VarlikDetayView.vue'),
        props: (r) => ({ id: Number(r.params.id) }),
        meta: { baslik: 'Varlık' },
      },
      {
        path: 'islemler',
        name: 'islemler',
        component: () => import('@/views/IslemlerView.vue'),
        meta: { baslik: 'İşlemler' },
      },
      {
        path: 'profil',
        name: 'profil',
        component: () => import('@/views/ProfilView.vue'),
        meta: { baslik: 'Profil' },
      },
      {
        path: 'yonetim',
        name: 'yonetim',
        component: () => import('@/views/YonetimView.vue'),
        meta: { baslik: 'Yönetim', yonetici: true },
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
