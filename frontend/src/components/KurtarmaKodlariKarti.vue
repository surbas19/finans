<script setup lang="ts">
import { onMounted, ref } from 'vue'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Password from 'primevue/password'
import { useToast } from 'primevue/usetoast'
import FormAlani from '@/components/FormAlani.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import { finansApi } from '@/api/finans'
import { dogrulamaHatalari, hataMesaji } from '@/api/http'

/**
 * Yönetici kurtarma kodları (Profil): şifre unutulursa e-posta olmadan sıfırlamak için 8 tek kullanımlık kod. Üretmek
 * mevcut şifreyi ister, eski kodları geçersiz kılar; kodlar yalnızca üretildiği anda bir kez gösterilir.
 */
const toast = useToast()
const kalan = ref<number | null>(null)
const acik = ref(false)
const sifre = ref('')
const kodlar = ref<string[] | null>(null)
const hata = ref('')
const gonderiliyor = ref(false)
const yazdim = ref(false)

async function yukle(): Promise<void> {
  try {
    kalan.value = (await finansApi.kurtarmaKalan()).kalan
  } catch {
    kalan.value = null
  }
}
onMounted(yukle)

function ac(): void {
  sifre.value = ''
  kodlar.value = null
  hata.value = ''
  yazdim.value = false
  acik.value = true
}

async function uret(): Promise<void> {
  hata.value = ''
  gonderiliyor.value = true
  try {
    kodlar.value = (await finansApi.kurtarmaUret(sifre.value)).kodlar
    sifre.value = ''
    await yukle()
  } catch (e) {
    hata.value = Object.values(dogrulamaHatalari(e))[0] ?? hataMesaji(e)
  } finally {
    gonderiliyor.value = false
  }
}

function kapat(): void {
  acik.value = false
  kodlar.value = null
  toast.add({
    severity: 'info',
    summary: 'Kurtarma kodlarını güvenli bir yerde saklayın',
    life: 4000,
  })
}
</script>

<template>
  <section class="kart flex flex-col gap-3 p-5" data-test="kurtarma-karti">
    <h2 class="bolum-etiketi">Kurtarma kodları</h2>
    <p class="text-sm text-surface-600">
      Şifrenizi unutursanız e-posta olmadan sıfırlamak için kullanılır. 8 tek kullanımlık kod
      üretilir; bir yere yazın.
    </p>
    <p
      v-if="kalan !== null"
      class="text-sm font-semibold"
      :class="kalan <= 2 ? 'text-amber-700' : 'text-surface-900'"
      data-test="kurtarma-kalan"
    >
      Kalan kod: {{ kalan }}
      <span v-if="kalan <= 2" class="font-normal"> · yeni kodlar üretmeniz önerilir</span>
    </p>
    <Button
      :label="kalan ? 'Yeni kodlar üret' : 'Kodları üret'"
      icon="pi pi-key"
      severity="secondary"
      outlined
      class="self-start"
      data-test="kurtarma-ac"
      @click="ac"
    />

    <UygulamaPenceresi v-model:visible="acik" baslik="Kurtarma kodları" :genislik="26">
      <div v-if="!kodlar" class="flex flex-col gap-4">
        <Message severity="warn" size="small"
          >Yeni kodlar üretildiğinde eski kodlar geçersiz olur. Devam etmek için şifrenizi
          yazın.</Message
        >
        <FormAlani etiket="Mevcut şifre" for="kurtarma-sifre">
          <Password
            v-model="sifre"
            input-id="kurtarma-sifre"
            :feedback="false"
            toggle-mask
            autocomplete="current-password"
            fluid
            data-test="kurtarma-sifre"
          />
        </FormAlani>
        <Message v-if="hata" severity="error" size="small">{{ hata }}</Message>
        <div class="flex justify-end gap-2">
          <Button label="Vazgeç" severity="secondary" text @click="acik = false" />
          <Button
            label="Kodları üret"
            icon="pi pi-key"
            :loading="gonderiliyor"
            :disabled="!sifre"
            data-test="kurtarma-uret"
            @click="uret"
          />
        </div>
      </div>
      <div v-else class="flex flex-col gap-4">
        <Message severity="warn" size="small"
          >Bu kodlar bir daha gösterilmez. Güvenli bir yere yazın; her biri bir kez
          kullanılır.</Message
        >
        <ul class="grid grid-cols-1 gap-1.5 sm:grid-cols-2" data-test="kurtarma-kodlar">
          <li
            v-for="k in kodlar"
            :key="k"
            class="rounded-xl bg-surface-50 px-3 py-2 text-center font-mono text-sm font-semibold tracking-wider text-surface-900 select-all"
          >
            {{ k }}
          </li>
        </ul>
        <label class="flex items-center gap-2 text-sm text-surface-700">
          <input v-model="yazdim" type="checkbox" data-test="kurtarma-yazdim" /> Kodları güvenli bir
          yere yazdım
        </label>
        <div class="flex justify-end">
          <Button
            label="Kapat"
            icon="pi pi-check"
            :disabled="!yazdim"
            data-test="kurtarma-kapat"
            @click="kapat"
          />
        </div>
      </div>
    </UygulamaPenceresi>
  </section>
</template>
