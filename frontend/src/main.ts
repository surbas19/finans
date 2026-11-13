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
