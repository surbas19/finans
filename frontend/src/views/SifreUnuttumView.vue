<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Password from 'primevue/password'
import SelectButton from 'primevue/selectbutton'
import { useToast } from 'primevue/usetoast'
import FormAlani from '@/components/FormAlani.vue'
import { dogrulamaHatalari, hataMesaji } from '@/api/http'
import { finansApi } from '@/api/finans'

/**
 * Şifremi unuttum (giriş yapmadan): e-postayla sıfırlama bağlantısı (herkes) ya da yönetici kurtarma koduyla.
 * Yanıtlar sabittir; hesabın var olup olmadığı söylenmez. E-posta ayarı yoksa (503) yöneticiye yönlendirilir.
 */
const logo = '/favicon.svg'
const router = useRouter()
const toast = useToast()
const YOLLAR = [
  { deger: 'eposta', etiket: 'E-posta ile' },
  { deger: 'kod', etiket: 'Kurtarma kodu ile' },
]
const yol = ref<'eposta' | 'kod'>('eposta')
const email = ref('')
const kod = ref('')
const yeni = ref('')
const tekrar = ref('')
const gonderiliyor = ref(false)
const hata = ref('')
const bilgi = ref('')

async function gonder(): Promise<void> {
  hata.value = ''
  bilgi.value = ''
  gonderiliyor.value = true
  try {
    if (yol.value === 'eposta') {
      bilgi.value = (await finansApi.sifreUnuttum(email.value)).message
    } else {
      const yanit = await finansApi.sifreKodla({
        email: email.value,
        kod: kod.value,
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
    }
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
        <h1 class="text-xl font-bold text-surface-900">Şifremi unuttum</h1>
      </div>
      <SelectButton
        v-model="yol"
        :options="YOLLAR"
        option-label="etiket"
        option-value="deger"
        :allow-empty="false"
        aria-label="Sıfırlama yolu"
        class="mb-4 w-full [&>.p-togglebutton]:flex-1"
        data-test="sifre-yol"
      />
      <form class="flex flex-col gap-4" @submit.prevent="gonder">
        <p v-if="yol === 'eposta'" class="text-sm text-surface-600">
          E-postanızı yazın; kayıtlıysa şifre sıfırlama bağlantısı gönderilir (60 dakika geçerli).
        </p>
        <p v-else class="text-sm text-surface-600">
          Yöneticiyseniz, Profil'den ürettiğiniz kurtarma kodlarından birini yazın. Her kod bir kez
          kullanılır.
        </p>
        <FormAlani etiket="E-posta" for="sifre-email">
          <InputText
            id="sifre-email"
            v-model="email"
            type="email"
            autocomplete="username"
            inputmode="email"
            autofocus
            data-test="sifre-email"
          />
        </FormAlani>
        <template v-if="yol === 'kod'">
          <FormAlani etiket="Kurtarma kodu" for="sifre-kod">
            <InputText
              id="sifre-kod"
              v-model="kod"
              autocomplete="off"
              spellcheck="false"
              placeholder="XXXX-XXXX-XXXX-XXXX"
              class="font-mono"
              data-test="sifre-kod"
            />
          </FormAlani>
          <FormAlani etiket="Yeni şifre" for="sifre-yeni">
            <Password
              v-model="yeni"
              input-id="sifre-yeni"
              toggle-mask
              autocomplete="new-password"
              fluid
            />
          </FormAlani>
          <FormAlani etiket="Yeni şifre (tekrar)" for="sifre-tekrar">
            <Password
              v-model="tekrar"
              input-id="sifre-tekrar"
              :feedback="false"
              toggle-mask
              autocomplete="new-password"
              fluid
            />
          </FormAlani>
        </template>
        <Message v-if="bilgi" severity="success" size="small" data-test="sifre-bilgi">{{
          bilgi
        }}</Message>
        <Message v-if="hata" severity="error" size="small" data-test="sifre-hata">{{
          hata
        }}</Message>
        <Button
          type="submit"
          :label="yol === 'eposta' ? 'Bağlantı gönder' : 'Şifreyi sıfırla'"
          :icon="yol === 'eposta' ? 'pi pi-envelope' : 'pi pi-key'"
          :loading="gonderiliyor"
          :disabled="!email || (yol === 'kod' && (!kod || !yeni || yeni !== tekrar))"
          data-test="sifre-gonder"
        />
        <RouterLink
          :to="{ name: 'giris' }"
          class="text-center text-sm font-semibold text-primary-600 hover:text-primary-700"
          >Girişe dön</RouterLink
        >
      </form>
    </div>
  </div>
</template>
