<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Password from 'primevue/password'
import FormAlani from '@/components/FormAlani.vue'
import { dogrulamaHatalari, hataMesaji } from '@/api/http'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const email = ref('')
const sifre = ref('')
const hatirla = ref(true)
const hata = ref('')
const gonderiliyor = ref(false)

async function giris(): Promise<void> {
  hata.value = ''
  gonderiliyor.value = true
  try {
    await auth.giris(email.value, sifre.value, hatirla.value)
    const geri =
      typeof route.query.geri === 'string' && route.query.geri.startsWith('/')
        ? route.query.geri
        : '/'
    await router.replace(geri)
  } catch (e) {
    hata.value = Object.values(dogrulamaHatalari(e))[0] ?? hataMesaji(e)
  } finally {
    gonderiliyor.value = false
  }
}
</script>

<template>
  <div class="flex min-h-dvh items-center justify-center px-4 py-10">
    <div class="kart w-full max-w-sm p-6 sm:p-8">
      <div class="mb-6 flex flex-col items-center gap-2 text-center">
        <img src="/favicon.svg" alt="" class="h-14 w-14" />
        <h1 class="text-xl font-bold text-surface-900">Finans</h1>
        <p class="text-sm text-surface-500">Altın, döviz, hisse ve kripto takibi</p>
      </div>
      <form class="flex flex-col gap-4" @submit.prevent="giris">
        <FormAlani etiket="E-posta" for="email">
          <InputText
            id="email"
            v-model="email"
            type="email"
            autocomplete="username"
            inputmode="email"
            autofocus
          />
        </FormAlani>
        <FormAlani etiket="Şifre" for="sifre">
          <Password
            v-model="sifre"
            input-id="sifre"
            :feedback="false"
            toggle-mask
            autocomplete="current-password"
            fluid
          />
        </FormAlani>
        <label class="flex items-center gap-2 text-sm text-surface-600">
          <Checkbox v-model="hatirla" binary input-id="hatirla" />
          Beni hatırla
        </label>
        <Message v-if="hata" severity="error" size="small">{{ hata }}</Message>
        <Button
          type="submit"
          label="Giriş yap"
          icon="pi pi-sign-in"
          :loading="gonderiliyor"
          :disabled="!email || !sifre"
        />
      </form>
    </div>
  </div>
</template>
