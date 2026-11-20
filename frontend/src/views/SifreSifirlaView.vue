<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Password from 'primevue/password'
import { useToast } from 'primevue/usetoast'
import FormAlani from '@/components/FormAlani.vue'
import { dogrulamaHatalari, hataMesaji } from '@/api/http'
import { finansApi } from '@/api/finans'

/**
 * E-postadaki bağlantıdan gelinen yeni şifre belirleme sayfası (adreste `token` ve `email`). Başarıda tüm oturumlar
 * kapanır; kişi yeni şifresiyle girişe yönlenir.
 */
const route = useRoute()
const logo = '/favicon.svg'
const router = useRouter()
const toast = useToast()
const token = computed(() => (typeof route.query.token === 'string' ? route.query.token : ''))
const email = computed(() => (typeof route.query.email === 'string' ? route.query.email : ''))
const yeni = ref('')
const tekrar = ref('')
const gonderiliyor = ref(false)
const hata = ref('')

async function sifirla(): Promise<void> {
  hata.value = ''
  gonderiliyor.value = true
  try {
    const yanit = await finansApi.sifreSifirla({
      email: email.value,
      token: token.value,
      yeni_sifre: yeni.value,
      yeni_sifre_confirmation: tekrar.value,
    })
    toast.add({
      severity: 'success',
      summary: 'Şifreniz değişti',
      detail: yanit.message,
      life: 6000,
    })
    await router.replace({ name: 'giris' })
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
      <div class="mb-5 flex flex-col items-center gap-2 text-center">
        <img :src="logo" alt="" class="h-12 w-12" />
        <h1 class="text-xl font-bold text-surface-900">Yeni şifre belirle</h1>
        <p v-if="email" class="text-sm text-surface-500">{{ email }}</p>
      </div>
      <Message v-if="!token || !email" severity="error" size="small" data-test="sifirla-gecersiz"
        >Bağlantı eksik ya da bozuk. E-postadaki bağlantıyı yeniden açın ya da yeni bağlantı
        isteyin.</Message
      >
      <form v-else class="flex flex-col gap-4" @submit.prevent="sifirla">
        <FormAlani etiket="Yeni şifre" for="yeni-sifre">
          <Password
            v-model="yeni"
            input-id="yeni-sifre"
            toggle-mask
            autocomplete="new-password"
            fluid
          />
        </FormAlani>
        <FormAlani etiket="Yeni şifre (tekrar)" for="yeni-sifre-tekrar">
          <Password
            v-model="tekrar"
            input-id="yeni-sifre-tekrar"
            :feedback="false"
            toggle-mask
            autocomplete="new-password"
            fluid
          />
        </FormAlani>
        <Message v-if="hata" severity="error" size="small" data-test="sifirla-hata">{{
          hata
        }}</Message>
        <Button
          type="submit"
          label="Şifreyi değiştir"
          icon="pi pi-check"
          :loading="gonderiliyor"
          :disabled="!yeni || yeni !== tekrar"
          data-test="sifirla-gonder"
        />
      </form>
      <RouterLink
        :to="{ name: 'sifre-unuttum' }"
        class="mt-4 block text-center text-sm font-semibold text-primary-600 hover:text-primary-700"
        >Yeni bağlantı iste</RouterLink
      >
    </div>
  </div>
</template>
