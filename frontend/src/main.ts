import '@fontsource-variable/plus-jakarta-sans'
import './assets/main.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import ConfirmationService from 'primevue/confirmationservice'

import App from './App.vue'
import router from './router'
import { oturumDustuAyarla } from './api/http'
import { primeVueAyarlari } from './config/primevue'
import { useAuthStore } from './stores/auth'
import { kurulumDinle } from './composables/useUygulamaKurulumu'

// "Ana ekrana ekle" istemi sayfa açılır açılmaz gelir; bileşenlerden önce yakalanır.
kurulumDinle()
// Yalnızca derlenmiş uygulamada (canlı): telefona kurulabilirlik ve çevrimdışı uyarı sayfası. Veri önbelleğe alınmaz.
if (import.meta.env.PROD && 'serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => {
      // Kayıt olmazsa uygulama normal web sayfası olarak çalışmaya devam eder.
    })
  })
}

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.use(PrimeVue, primeVueAyarlari)
app.use(ToastService)
app.use(ConfirmationService)

const auth = useAuthStore()
oturumDustuAyarla(() => {
  auth.temizle()
  void router.push({ name: 'giris' })
})

app.mount('#app')
