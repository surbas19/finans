<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Password from 'primevue/password'
import FormAlani from '@/components/FormAlani.vue'
import SayfaBasligi from '@/components/SayfaBasligi.vue'
import UygulamaKurKarti from '@/components/UygulamaKurKarti.vue'
import { finansApi } from '@/api/finans'
import { useFormGonder } from '@/composables/useFormGonder'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const form = ref({ mevcut_sifre: '', yeni_sifre: '', yeni_sifre_confirmation: '' })
const { hatalar, gonderiliyor, gonder } = useFormGonder()

async function sifreDegistir(): Promise<void> {
  const sonuc = await gonder(() => finansApi.sifre(form.value), 'Şifreniz değiştirildi')
  if (sonuc) form.value = { mevcut_sifre: '', yeni_sifre: '', yeni_sifre_confirmation: '' }
}

async function cikis(): Promise<void> {
  await auth.cikis()
  await router.push({ name: 'giris' })
}
</script>

<template>
  <div class="flex max-w-xl flex-col gap-4">
    <SayfaBasligi baslik="Profil" />
    <section class="kart flex items-center gap-4 p-5">
      <span
        class="flex h-12 w-12 items-center justify-center rounded-full bg-primary-50 text-lg text-primary-600"
      >
        <i class="pi pi-user" />
      </span>
      <div class="min-w-0 flex-1">
        <p class="truncate font-semibold text-surface-900">{{ auth.kullanici?.ad_soyad }}</p>
        <p class="truncate text-sm text-surface-500">{{ auth.kullanici?.email }}</p>
      </div>
      <Button label="Çıkış" icon="pi pi-sign-out" severity="secondary" outlined @click="cikis" />
    </section>

    <UygulamaKurKarti />

    <section class="kart p-5">
      <h2 class="bolum-etiketi mb-3">Şifre değiştir</h2>
      <form class="flex flex-col gap-4" @submit.prevent="sifreDegistir">
        <FormAlani etiket="Mevcut şifre" for="mevcut" :hata="hatalar.mevcut_sifre">
          <Password
            v-model="form.mevcut_sifre"
            input-id="mevcut"
            :feedback="false"
            toggle-mask
            autocomplete="current-password"
            fluid
          />
        </FormAlani>
        <FormAlani
          etiket="Yeni şifre"
          for="yeni"
          :hata="hatalar.yeni_sifre"
          ipucu="En az 8 karakter; harf ve rakam içermeli."
        >
          <Password
            v-model="form.yeni_sifre"
            input-id="yeni"
            :feedback="false"
            toggle-mask
            autocomplete="new-password"
            fluid
          />
        </FormAlani>
        <FormAlani etiket="Yeni şifre (tekrar)" for="yeni2">
          <Password
            v-model="form.yeni_sifre_confirmation"
            input-id="yeni2"
            :feedback="false"
            toggle-mask
            autocomplete="new-password"
            fluid
          />
        </FormAlani>
        <p class="text-xs text-surface-500">Şifre değişince öbür cihazlardaki oturumlar kapanır.</p>
        <div class="flex justify-end">
          <Button
            type="submit"
            label="Şifreyi değiştir"
            icon="pi pi-check"
            :loading="gonderiliyor"
          />
        </div>
      </form>
    </section>
  </div>
</template>
