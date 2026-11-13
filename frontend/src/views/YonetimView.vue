<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Select from 'primevue/select'
import Tab from 'primevue/tab'
import TabList from 'primevue/tablist'
import TabPanel from 'primevue/tabpanel'
import TabPanels from 'primevue/tabpanels'
import Tabs from 'primevue/tabs'
import Tag from 'primevue/tag'
import ToggleSwitch from 'primevue/toggleswitch'
import { useToast } from 'primevue/usetoast'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import FormAlani from '@/components/FormAlani.vue'
import SayfaBasligi from '@/components/SayfaBasligi.vue'
import {
  finansApi,
  type KaynakDurumu,
  type YonetimHanesi,
  type YonetimKullanicisi,
} from '@/api/finans'
import { hataMesaji } from '@/api/http'
import { useFormGonder } from '@/composables/useFormGonder'
import { useAuthStore } from '@/stores/auth'
import { neZaman, tarihSaat } from '@/utils/bicim'

/**
 * Yönetici: kullanıcılar (kayıt kapalı; kullanıcıyı yönetici ekler) ve fiyat kaynaklarının durumu.
 * Kişinin portföyü burada görünmez.
 */
const auth = useAuthStore()
const toast = useToast()
const sekme = ref('kaynaklar')
const kullanicilar = ref<YonetimKullanicisi[]>([])
const kaynaklar = ref<KaynakDurumu[]>([])
const guncelleniyor = ref(false)

const ekleAcik = ref(false)
const yeni = ref({ ad: '', soyad: '', email: '', sifre: '', yonetici: false })
const ekleme = useFormGonder()

const sifreAcik = ref(false)
const sifreKisi = ref<YonetimKullanicisi | null>(null)
const yeniSifre = ref('')
const sifreFormu = useFormGonder()

const haneler = ref<YonetimHanesi[]>([])

async function yukle(): Promise<void> {
  const [k, d, h] = await Promise.all([
    finansApi.kullanicilar(),
    finansApi.kaynaklar(),
    finansApi.haneler(),
  ])
  kullanicilar.value = k
  kaynaklar.value = d
  haneler.value = h
}

/** Hane seçenekleri: mevcut haneler (içindekilerle) + yeni hane */
const haneSecenekleri = computed(() => [
  ...haneler.value.map((h) => ({
    deger: h.id as number | 'yeni',
    etiket: `${h.ad} (${h.kullanicilar.join(', ') || 'boş'})`,
  })),
  { deger: 'yeni' as number | 'yeni', etiket: 'Yeni hane (yalnız)' },
])

async function haneDegistir(k: YonetimKullanicisi, hane: number | 'yeni'): Promise<void> {
  try {
    await finansApi.kullaniciGuncelle(k.id, { hane })
    toast.add({ severity: 'success', summary: `${k.ad_soyad} haneye bağlandı`, life: 3000 })
    await yukle()
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Hane değişmedi', detail: hataMesaji(e), life: 7000 })
  }
}

onMounted(yukle)

async function simdiGuncelle(): Promise<void> {
  guncelleniyor.value = true
  try {
    const sonuc = await finansApi.kaynaklariGuncelle()
    const ozet = Object.entries(sonuc.rapor)
      .map(([k, v]) => `${k}: ${v}`)
      .join(' · ')
    toast.add({ severity: 'success', summary: 'Fiyatlar güncellendi', detail: ozet, life: 6000 })
    kaynaklar.value = await finansApi.kaynaklar()
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Güncellenemedi', detail: hataMesaji(e), life: 5000 })
  } finally {
    guncelleniyor.value = false
  }
}

async function ekle(): Promise<void> {
  const sonuc = await ekleme.gonder(() => finansApi.kullaniciEkle(yeni.value), 'Kullanıcı eklendi')
  if (sonuc) {
    ekleAcik.value = false
    yeni.value = { ad: '', soyad: '', email: '', sifre: '', yonetici: false }
    kullanicilar.value = await finansApi.kullanicilar()
  }
}

async function aktifDegistir(k: YonetimKullanicisi, aktif: boolean): Promise<void> {
  try {
    await finansApi.kullaniciGuncelle(k.id, { aktif })
    k.aktif = aktif
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Yapılamadı', detail: hataMesaji(e), life: 5000 })
  }
}

function sifreAc(k: YonetimKullanicisi): void {
  sifreKisi.value = k
  yeniSifre.value = ''
  sifreFormu.hatalar.value = {}
  sifreAcik.value = true
}

async function sifreKaydet(): Promise<void> {
  if (!sifreKisi.value) return
  const sonuc = await sifreFormu.gonder(
    () => finansApi.kullaniciGuncelle(sifreKisi.value!.id, { sifre: yeniSifre.value }),
    'Şifre değiştirildi; kişinin açık oturumları kapandı',
  )
  if (sonuc) sifreAcik.value = false
}
</script>

<template>
  <div>
    <SayfaBasligi baslik="Yönetim" />
    <section class="kart p-2 sm:p-4">
      <Tabs v-model:value="sekme">
        <TabList>
          <Tab value="kaynaklar">Fiyat kaynakları</Tab>
          <Tab value="kullanicilar">Kullanıcılar</Tab>
        </TabList>
        <TabPanels>
          <TabPanel value="kaynaklar">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
              <p class="text-sm text-surface-500">Fiyatlar 15 dakikada bir otomatik güncellenir.</p>
              <Button
                label="Şimdi güncelle"
                icon="pi pi-refresh"
                size="small"
                :loading="guncelleniyor"
                @click="simdiGuncelle"
              />
            </div>
            <ul class="divide-y divide-surface-100">
              <li v-for="k in kaynaklar" :key="k.kaynak" class="flex flex-col gap-1 py-3">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="font-semibold text-surface-900">{{ k.ad }}</span>
                  <Tag :value="`${k.varlik_sayisi} varlık`" severity="secondary" />
                  <Tag
                    v-if="k.kaynak !== 'elle' && k.art_arda_hata > 0"
                    :value="`${k.art_arda_hata} kez hata`"
                    severity="danger"
                  />
                  <Tag
                    v-else-if="k.kaynak !== 'elle' && k.son_basari"
                    value="çalışıyor"
                    severity="success"
                  />
                </div>
                <p v-if="k.kaynak !== 'elle'" class="text-xs text-surface-500">
                  Son başarılı:
                  {{
                    k.son_basari
                      ? `${tarihSaat(k.son_basari)} (${neZaman(k.son_basari)})`
                      : 'henüz yok'
                  }}
                  <template v-if="k.son_basari"> · {{ k.guncellenen }} fiyat</template>
                </p>
                <p v-if="k.son_hata" class="text-xs text-amber-700">
                  {{ k.son_hata }}
                  <span class="text-surface-400">({{ neZaman(k.son_hata_zamani) }})</span>
                </p>
              </li>
            </ul>
          </TabPanel>
          <TabPanel value="kullanicilar">
            <div class="mb-3 flex justify-end">
              <Button
                label="Kullanıcı ekle"
                icon="pi pi-user-plus"
                size="small"
                @click="ekleAcik = true"
              />
            </div>
            <ul class="divide-y divide-surface-100">
              <li
                v-for="k in kullanicilar"
                :key="k.id"
                class="flex flex-wrap items-center gap-3 py-3"
              >
                <div class="min-w-0 flex-1">
                  <p class="flex items-center gap-2 font-semibold text-surface-900">
                    {{ k.ad_soyad }}
                    <Tag v-if="k.yonetici" value="yönetici" severity="info" />
                  </p>
                  <p class="truncate text-xs text-surface-500">
                    {{ k.email }} · son giriş {{ k.son_giris ? neZaman(k.son_giris) : 'yok' }}
                  </p>
                </div>
                <!-- Telefonda adın altında ayrı satır, masaüstünde aynı satırda -->
                <div
                  class="flex w-full items-center justify-between gap-3 sm:w-auto sm:justify-end"
                >
                  <Select
                    :model-value="k.hane?.id ?? null"
                    :options="haneSecenekleri"
                    option-label="etiket"
                    option-value="deger"
                    placeholder="Hane yok"
                    size="small"
                    class="min-w-0 flex-1 sm:w-56 sm:flex-none"
                    :aria-label="`${k.ad_soyad} hanesi`"
                    @update:model-value="(h: number | 'yeni') => haneDegistir(k, h)"
                  />
                  <Button label="Şifre" icon="pi pi-key" text size="small" @click="sifreAc(k)" />
                  <label class="flex items-center gap-2 text-sm text-surface-600">
                    <ToggleSwitch
                      :model-value="k.aktif"
                      :disabled="k.id === auth.kullanici?.id"
                      @update:model-value="(v: boolean) => aktifDegistir(k, v)"
                    />
                    {{ k.aktif ? 'Açık' : 'Kapalı' }}
                  </label>
                </div>
              </li>
            </ul>
          </TabPanel>
        </TabPanels>
      </Tabs>
    </section>

    <UygulamaPenceresi v-model:visible="ekleAcik" baslik="Kullanıcı ekle" :genislik="28">
      <form class="flex flex-col gap-4" @submit.prevent="ekle">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormAlani etiket="Ad" for="y-ad" :hata="ekleme.hatalar.value.ad" zorunlu
            ><InputText id="y-ad" v-model="yeni.ad"
          /></FormAlani>
          <FormAlani etiket="Soyad" for="y-soyad" :hata="ekleme.hatalar.value.soyad" zorunlu
            ><InputText id="y-soyad" v-model="yeni.soyad"
          /></FormAlani>
        </div>
        <FormAlani etiket="E-posta" for="y-email" :hata="ekleme.hatalar.value.email" zorunlu>
          <InputText id="y-email" v-model="yeni.email" type="email" inputmode="email" />
        </FormAlani>
        <FormAlani
          etiket="Şifre"
          for="y-sifre"
          :hata="ekleme.hatalar.value.sifre"
          ipucu="En az 8 karakter; harf ve rakam. Kişiye siz iletirsiniz."
          zorunlu
        >
          <Password
            v-model="yeni.sifre"
            input-id="y-sifre"
            :feedback="false"
            toggle-mask
            autocomplete="new-password"
            fluid
          />
        </FormAlani>
        <label class="flex items-center gap-2 text-sm text-surface-600">
          <Checkbox v-model="yeni.yonetici" binary input-id="y-yonetici" /> Yönetici (kullanıcı
          ekler, fiyat kaynaklarını görür)
        </label>
        <div class="flex justify-end gap-2">
          <Button label="Vazgeç" severity="secondary" text @click="ekleAcik = false" />
          <Button
            type="submit"
            label="Ekle"
            icon="pi pi-check"
            :loading="ekleme.gonderiliyor.value"
          />
        </div>
      </form>
    </UygulamaPenceresi>

    <UygulamaPenceresi
      v-model:visible="sifreAcik"
      :baslik="`Şifre: ${sifreKisi?.ad_soyad ?? ''}`"
      :genislik="24"
    >
      <form class="flex flex-col gap-4" @submit.prevent="sifreKaydet">
        <FormAlani
          etiket="Yeni şifre"
          for="s-sifre"
          :hata="sifreFormu.hatalar.value.sifre"
          ipucu="En az 8 karakter; harf ve rakam."
        >
          <Password
            v-model="yeniSifre"
            input-id="s-sifre"
            :feedback="false"
            toggle-mask
            autocomplete="new-password"
            fluid
          />
        </FormAlani>
        <div class="flex justify-end gap-2">
          <Button label="Vazgeç" severity="secondary" text @click="sifreAcik = false" />
          <Button
            type="submit"
            label="Kaydet"
            icon="pi pi-check"
            :loading="sifreFormu.gonderiliyor.value"
          />
        </div>
      </form>
    </UygulamaPenceresi>
  </div>
</template>
