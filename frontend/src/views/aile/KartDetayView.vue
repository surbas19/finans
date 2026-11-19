<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'
import ToggleSwitch from 'primevue/toggleswitch'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import BosDurum from '@/components/BosDurum.vue'
import KisiEtiketi from '@/components/KisiEtiketi.vue'
import ElleEkstreDialog from '@/components/aile/ElleEkstreDialog.vue'
import EkstreYukleDialog from '@/components/aile/EkstreYukleDialog.vue'
import KartFormu from '@/components/aile/KartFormu.vue'
import KartIslemFormu from '@/components/aile/KartIslemFormu.vue'
import KartIslemListesi from '@/components/aile/KartIslemListesi.vue'
import { aileApi, type Ekstre, type Fatura, type Kart, type KartIslemi } from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { useSayfaBasligi } from '@/composables/useSayfaBasligi'
import { useYanaKaydirma } from '@/composables/useYanaKaydirma'
import KartSeridi from '@/components/aile/KartSeridi.vue'
import { useAileStore } from '@/stores/aile'
import { tarih, tl } from '@/utils/bicim'
import { kartRengiCss } from '@/utils/kartRengi'

/**
 * Kart sayfası: ekstreler (dönem, borç, son ödeme, ödendi), son işlemler ve bu kartla ödenen faturalar. Üstte kartlar
 * arası şerit (Kartlar sayfasının sırası); başlık kartında parmakla sola kaydırınca sonraki, sağa kaydırınca önceki kart.
 * Geçiş router.replace ile (geri tuşu Kartlar'a döner), yönü history.state.sayfaYonu ile (AnaDuzen yönlü kaydırır).
 */
const props = defineProps<{ id: number }>()
const aile = useAileStore()
const router = useRouter()
const confirm = useConfirm()
const toast = useToast()

const kart = ref<Kart | null>(null)
useSayfaBasligi(() => kart.value?.ad)
const ekstreler = ref<Ekstre[]>([])
const islemler = ref<KartIslemi[] | null>(null)
const faturalar = ref<Fatura[]>([])
const bulunamadi = ref(false)
const kartFormuAcik = ref(false)
const yukleAcik = ref(false)
const yukleTuru = ref<'kart' | 'yapistir'>('kart')
const elleAcik = ref(false)

function yukleAc(tur: 'kart' | 'yapistir'): void {
  yukleTuru.value = tur
  yukleAcik.value = true
}
const islemFormuAcik = ref(false)
const duzenlenen = ref<KartIslemi | null>(null)

async function yukle(): Promise<void> {
  try {
    await aile.yukle()
    const [k, i, f] = await Promise.all([
      aileApi.kart(props.id),
      aileApi.kartIslemleri({ kart_id: props.id }),
      aileApi.faturalar({ kart_id: props.id }),
    ])
    kart.value = k.kart
    ekstreler.value = k.ekstreler
    islemler.value = i.islemler.slice(0, 30)
    faturalar.value = f.faturalar
  } catch {
    bulunamadi.value = true
  }
}
onMounted(yukle)

/** Kartlar arası gezinme: aynı sayfa başka kartla yeniden kurulur (RouterView anahtarı yola bağlı) */
function karta(id: number, yon: number): void {
  void router.replace({ name: 'aile-kart', params: { id }, state: { sayfaYonu: yon } })
}
function komsu(adim: number): void {
  const liste = aile.siraliKartlar
  const i = liste.findIndex((k) => k.id === props.id)
  const hedef = i < 0 ? undefined : liste[i + adim]
  if (hedef) karta(hedef.id, adim)
}
const kaydirma = useYanaKaydirma({ sola: () => komsu(1), saga: () => komsu(-1) })

async function odendi(e: Ekstre, deger: boolean): Promise<void> {
  try {
    await aileApi.ekstreGuncelle(e.id, { odendi: deger })
    e.odendi = deger
  } catch (hata) {
    toast.add({ severity: 'error', summary: 'Kaydedilemedi', detail: hataMesaji(hata), life: 5000 })
  }
}

/** İnceleme bekleyen (taslak) ekstreyi siler; satırları ve PDF'i de gider */
function ekstreSil(e: Ekstre): void {
  confirm.require({
    header: 'Ekstreyi sil',
    message: `${e.donem_etiketi ?? 'Bu ekstre'} silinsin mi? Satırları ve PDF dosyası da silinir.`,
    acceptProps: { label: 'Sil', severity: 'danger' },
    rejectProps: { label: 'Vazgeç', severity: 'secondary', text: true },
    accept: async () => {
      try {
        await aileApi.ekstreSil(e.id)
        ekstreler.value = ekstreler.value.filter((x) => x.id !== e.id)
        toast.add({ severity: 'success', summary: 'Ekstre silindi', life: 3000 })
      } catch (hata) {
        toast.add({
          severity: 'error',
          summary: 'Silinemedi',
          detail: hataMesaji(hata),
          life: 5000,
        })
      }
    },
  })
}

function islemSec(i: KartIslemi | null): void {
  duzenlenen.value = i
  islemFormuAcik.value = true
}

function kartiSil(): void {
  confirm.require({
    header: 'Kartı sil',
    message: `"${kart.value?.ad}" silinsin mi? Ekstresi ya da işlemi olan kart silinmez (pasif yapılır).`,
    acceptProps: { label: 'Sil', severity: 'danger' },
    rejectProps: { label: 'Vazgeç', severity: 'secondary', text: true },
    accept: async () => {
      try {
        await aileApi.kartSil(props.id)
        await aile.yukle(true)
        await router.replace({ name: 'aile-kartlar' })
      } catch (e) {
        toast.add({ severity: 'error', summary: 'Silinemedi', detail: hataMesaji(e), life: 5000 })
      }
    },
  })
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <Message v-if="bulunamadi" severity="error">Kart bulunamadı.</Message>
    <template v-else>
      <KartSeridi
        v-if="aile.siraliKartlar.length > 1"
        :kartlar="aile.siraliKartlar"
        :secili-id="id"
        @sec="karta"
      />
      <section
        class="kart touch-pan-y border-t-4 p-4 sm:p-5"
        :style="{ borderTopColor: kart ? kartRengiCss(kart) : undefined }"
        data-test="kart-baslik"
        v-bind="kaydirma"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <Skeleton v-if="!kart" width="10rem" height="1.5rem" />
            <template v-else>
              <h2 class="truncate text-lg font-bold text-surface-900">{{ kart.ad }}</h2>
              <p class="text-sm text-surface-500">
                {{ aile.bankaAdi(kart.banka_id) }} ·
                <KisiEtiketi :kisi="aile.uye(kart.sahip_uye_id)" />
                <template v-if="kart.kesim_gunu"> · kesim {{ kart.kesim_gunu }}</template>
                <template v-if="kart.son_odeme_gunu">
                  · son ödeme {{ kart.son_odeme_gunu }}</template
                >
              </p>
            </template>
          </div>
          <div class="flex gap-1">
            <Button
              icon="pi pi-pencil"
              text
              rounded
              aria-label="Kartı düzenle"
              :disabled="!kart"
              @click="kartFormuAcik = true"
            />
            <Button
              icon="pi pi-trash"
              text
              rounded
              severity="danger"
              aria-label="Kartı sil"
              :disabled="!kart"
              @click="kartiSil"
            />
          </div>
        </div>
        <div class="mt-3 flex flex-wrap gap-2">
          <Button
            label="Ekstre yükle"
            icon="pi pi-upload"
            data-test="kart-ekstre-yukle"
            @click="yukleAc('kart')"
          />
          <Button
            label="Dış yapay zekâ (JSON)"
            icon="pi pi-clipboard"
            severity="secondary"
            outlined
            data-test="kart-ekstre-json"
            @click="yukleAc('yapistir')"
          />
          <Button
            label="Tutarla ekstre gir"
            icon="pi pi-pencil"
            severity="secondary"
            outlined
            data-test="kart-ekstre-elle"
            @click="elleAcik = true"
          />
          <Button
            label="İşlem ekle"
            icon="pi pi-plus"
            severity="secondary"
            outlined
            @click="islemSec(null)"
          />
          <Button
            label="Tüm işlemler"
            icon="pi pi-list"
            severity="secondary"
            text
            @click="router.push({ name: 'aile-islemler', query: { kart_id: id } })"
          />
        </div>
      </section>

      <section class="kart p-4 sm:p-5">
        <h2 class="bolum-etiketi mb-2">Ekstreler</h2>
        <BosDurum
          v-if="!ekstreler.length"
          kucuk
          baslik="Ekstre yok"
          aciklama="PDF ekstrenizi yükleyin; eski aylarınkiler de olur."
          ikon="pi pi-file-pdf"
        />
        <ul v-else class="divide-y divide-surface-100">
          <li v-for="e in ekstreler" :key="e.id" class="flex items-center gap-3 py-3">
            <RouterLink :to="{ name: 'aile-ekstre', params: { id: e.id } }" class="min-w-0 flex-1">
              <span class="flex items-center gap-2">
                <span class="truncate font-semibold text-surface-900">{{
                  e.donem_etiketi ?? 'Dönem okunamadı'
                }}</span>
                <Tag v-if="e.durum === 'taslak'" value="İnceleme bekliyor" severity="warn" />
              </span>
              <span class="block text-xs text-surface-500">
                <template v-if="e.kesim_tarihi">kesim {{ tarih(e.kesim_tarihi) }}</template>
                <template v-if="e.son_odeme_tarihi">
                  · son ödeme {{ tarih(e.son_odeme_tarihi) }}</template
                >
                · {{ e.satir_sayisi }} satır
              </span>
            </RouterLink>
            <span class="text-right text-sm font-semibold whitespace-nowrap tabular-nums">{{
              tl(e.donem_borcu)
            }}</span>
            <Button
              v-if="e.durum === 'taslak'"
              icon="pi pi-trash"
              severity="danger"
              text
              rounded
              size="small"
              aria-label="Ekstreyi sil"
              title="Ekstreyi sil"
              data-test="ekstre-sil"
              @click="ekstreSil(e)"
            />
            <label
              v-if="e.durum === 'onaylandi'"
              class="flex items-center gap-1.5 text-xs text-surface-600"
            >
              <ToggleSwitch
                :model-value="e.odendi"
                @update:model-value="(d: boolean) => odendi(e, d)"
              />
              Ödendi
            </label>
          </li>
        </ul>
      </section>

      <section class="kart p-4 sm:p-5">
        <h2 class="bolum-etiketi mb-2">Son işlemler</h2>
        <Skeleton v-if="islemler === null" height="6rem" />
        <p v-else-if="!islemler.length" class="py-3 text-sm text-surface-500">Onaylı işlem yok.</p>
        <KartIslemListesi v-else :islemler="islemler" @sec="islemSec" />
      </section>

      <section v-if="faturalar.length" class="kart p-4 sm:p-5">
        <h2 class="bolum-etiketi mb-2">Bu kartla ödenen faturalar</h2>
        <ul class="divide-y divide-surface-100">
          <li
            v-for="f in faturalar"
            :key="f.id"
            class="flex items-center justify-between gap-3 py-2.5 text-sm"
          >
            <span class="min-w-0">
              <span class="block truncate font-semibold">{{ f.saglayici_ad }}</span>
              <span class="block text-xs text-surface-500">
                Hizmet: {{ f.hizmet_donemi_etiketi ?? 'bilinmiyor' }} · ödeme
                {{ tarih(f.odeme_tarihi) }}
              </span>
            </span>
            <span class="font-semibold tabular-nums">{{ tl(f.tutar) }}</span>
          </li>
        </ul>
      </section>
    </template>

    <KartFormu v-model:visible="kartFormuAcik" :kart="kart" @kaydedildi="yukle" />
    <EkstreYukleDialog v-model:visible="yukleAcik" :kart-id="id" :baslangic-turu="yukleTuru" />
    <ElleEkstreDialog v-model:visible="elleAcik" :kart-id="id" />
    <KartIslemFormu
      v-model:visible="islemFormuAcik"
      :islem="duzenlenen"
      :kart-id="id"
      @kaydedildi="yukle"
    />
  </div>
</template>
