<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Password from 'primevue/password'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import FormAlani from '@/components/FormAlani.vue'
import KisiResmi from '@/components/KisiResmi.vue'
import SayfaBasligi from '@/components/SayfaBasligi.vue'
import UygulamaKurKarti from '@/components/UygulamaKurKarti.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import { finansApi } from '@/api/finans'
import { useFormGonder } from '@/composables/useFormGonder'
import { useAuthStore } from '@/stores/auth'
import { kareKucult } from '@/utils/profilResmi'

const auth = useAuthStore()
const router = useRouter()
const confirm = useConfirm()
const toast = useToast()
const form = ref({ mevcut_sifre: '', yeni_sifre: '', yeni_sifre_confirmation: '' })
const { hatalar, gonderiliyor, gonder } = useFormGonder()

async function sifreDegistir(): Promise<void> {
  const sonuc = await gonder(() => finansApi.sifre(form.value), 'Şifreniz değiştirildi')
  if (sonuc) form.value = { mevcut_sifre: '', yeni_sifre: '', yeni_sifre_confirmation: '' }
}

// --- Profil resmi: seç → tarayıcıda kare kırp / küçült → önizle → kaydet ---
const dosyaGirdisi = ref<HTMLInputElement | null>(null)
const onizleme = ref<{ blob: Blob; adres: string } | null>(null)
const onizlemeAcik = ref(false)
const hazirlaniyor = ref(false)
const resim = useFormGonder()

function onizlemeyiBirak(): void {
  if (onizleme.value) URL.revokeObjectURL(onizleme.value.adres)
  onizleme.value = null
}
watch(onizlemeAcik, (acik) => {
  if (!acik) onizlemeyiBirak()
})
onBeforeUnmount(onizlemeyiBirak)

async function resimSecildi(olay: Event): Promise<void> {
  const girdi = olay.target as HTMLInputElement
  const dosya = girdi.files?.[0]
  girdi.value = '' // aynı dosya yeniden seçilebilsin
  if (!dosya) return
  hazirlaniyor.value = true
  try {
    const blob = await kareKucult(dosya)
    onizlemeyiBirak()
    onizleme.value = { blob, adres: URL.createObjectURL(blob) }
    resim.hatalar.value = {}
    onizlemeAcik.value = true
  } catch (e) {
    toast.add({
      severity: 'error',
      summary: 'Resim açılamadı',
      detail: e instanceof Error ? e.message : undefined,
      life: 5000,
    })
  } finally {
    hazirlaniyor.value = false
  }
}

async function resmiKaydet(): Promise<void> {
  const blob = onizleme.value?.blob
  if (!blob) return
  const kullanici = await resim.gonder(() => finansApi.resimYukle(blob), 'Profil resmi kaydedildi')
  if (kullanici) {
    auth.kullanici = kullanici
    onizlemeAcik.value = false
  }
}

function resmiKaldir(): void {
  confirm.require({
    header: 'Profil resmini kaldır',
    message: 'Resminiz silinsin mi? Yerine adınızın baş harfleri görünür.',
    acceptProps: { label: 'Kaldır', severity: 'danger' },
    rejectProps: { label: 'Vazgeç', severity: 'secondary', text: true },
    accept: async () => {
      const kullanici = await resim.gonder(() => finansApi.resimSil(), 'Profil resmi kaldırıldı')
      if (kullanici) auth.kullanici = kullanici
    },
  })
}

async function cikis(): Promise<void> {
  await auth.cikis()
  await router.push({ name: 'giris' })
}
</script>

<template>
  <div class="flex max-w-xl flex-col gap-4">
    <SayfaBasligi baslik="Profil" />
    <section class="kart flex flex-col gap-4 p-5">
      <div class="flex items-center gap-4">
        <KisiResmi
          :ad="auth.kullanici?.ad_soyad ?? ''"
          :resim="auth.kullanici?.resim"
          boyut="lg"
          data-test="profil-resmi"
        />
        <div class="min-w-0 flex-1">
          <p class="truncate font-semibold text-surface-900">{{ auth.kullanici?.ad_soyad }}</p>
          <p class="truncate text-sm text-surface-500">{{ auth.kullanici?.email }}</p>
        </div>
      </div>
      <div class="flex flex-wrap gap-2">
        <!-- Telefonda "Resim" kamera ya da galeri sorar -->
        <input
          ref="dosyaGirdisi"
          type="file"
          accept="image/jpeg,image/png,image/webp,image/*"
          class="hidden"
          data-test="resim-girdisi"
          @change="resimSecildi"
        />
        <Button
          label="Resmi değiştir"
          icon="pi pi-camera"
          severity="secondary"
          outlined
          :loading="hazirlaniyor"
          @click="dosyaGirdisi?.click()"
        />
        <Button
          v-if="auth.kullanici?.resim"
          label="Kaldır"
          icon="pi pi-trash"
          severity="danger"
          text
          @click="resmiKaldir"
        />
        <Button
          label="Çıkış"
          icon="pi pi-sign-out"
          severity="secondary"
          outlined
          class="ml-auto"
          @click="cikis"
        />
      </div>
    </section>

    <UygulamaPenceresi v-model:visible="onizlemeAcik" baslik="Profil resmi" :genislik="24">
      <div class="flex flex-col items-center gap-4">
        <img
          v-if="onizleme"
          :src="onizleme.adres"
          alt="Yeni profil resmi"
          class="h-40 w-40 rounded-full object-cover shadow-sm"
          data-test="resim-onizleme"
        />
        <p class="text-center text-sm text-surface-500">
          Fotoğrafın ortası kare olarak alınır. Resmi siz, hanenizdekiler ve yönetici görür.
        </p>
        <Message v-if="resim.hatalar.value.resim" severity="error" class="w-full">{{
          resim.hatalar.value.resim
        }}</Message>
        <div class="flex w-full justify-end gap-2">
          <Button label="Vazgeç" severity="secondary" text @click="onizlemeAcik = false" />
          <Button
            label="Kaydet"
            icon="pi pi-check"
            :loading="resim.gonderiliyor.value"
            @click="resmiKaydet"
          />
        </div>
      </div>
    </UygulamaPenceresi>

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
