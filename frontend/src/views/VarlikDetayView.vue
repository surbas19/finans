<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import DegisimEtiketi from '@/components/DegisimEtiketi.vue'
import FiyatGrafigi from '@/components/FiyatGrafigi.vue'
import FormAlani from '@/components/FormAlani.vue'
import IslemFormu from '@/components/IslemFormu.vue'
import IslemListesi from '@/components/IslemListesi.vue'
import PozisyonListesi from '@/components/PozisyonListesi.vue'
import {
  finansApi,
  sabitFiyatli,
  VARLIK_TURLERI,
  type Islem,
  type Pozisyon,
  type Varlik,
} from '@/api/finans'
import { hataMesaji } from '@/api/http'
import { useFormGonder } from '@/composables/useFormGonder'
import { useSonIstek } from '@/composables/useSonIstek'
import { useAuthStore } from '@/stores/auth'
import { fiyat, miktar, tarihSaat, tl } from '@/utils/bicim'

const props = defineProps<{ id: number }>()
const auth = useAuthStore()
const toast = useToast()

type Ayrinti = Varlik & {
  onceki_kapanis: number | null
  tl_fiyat: number | null
  izleniyor: boolean
}
const varlik = ref<Ayrinti | null>(null)
/** Nakit ve bireysel emeklilik: fiyatı, grafiği, izlemesi yok */
const sabit = computed(() => sabitFiyatli(varlik.value?.tur))
const toplam = ref<Pozisyon | null>(null)
const pozisyonlar = ref<Pozisyon[]>([])
const islemler = ref<Islem[]>([])
const bulunamadi = ref(false)
const islemFormuAcik = ref(false)
const duzenlenen = ref<Islem | null>(null)
const elleAcik = ref(false)
const elleFiyat = ref<number | null>(null)
const grafikAnahtari = ref(0)
const sonIstek = useSonIstek()
const elle = useFormGonder()

async function yukle(): Promise<void> {
  try {
    const veri = await sonIstek(() =>
      Promise.all([finansApi.varlik(props.id), finansApi.islemler({ varlik_id: props.id })]),
    )
    if (veri === undefined) return
    varlik.value = veri[0].varlik
    toplam.value = veri[0].toplam
    pozisyonlar.value = veri[0].pozisyonlar
    islemler.value = veri[1]
  } catch {
    bulunamadi.value = true
  }
}

onMounted(yukle)

async function izlemeDegistir(): Promise<void> {
  if (!varlik.value) return
  try {
    if (varlik.value.izleniyor) await finansApi.izlemeCikar(props.id)
    else await finansApi.izlemeEkle(props.id)
    varlik.value.izleniyor = !varlik.value.izleniyor
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Yapılamadı', detail: hataMesaji(e), life: 4000 })
  }
}

function islemEkle(): void {
  duzenlenen.value = null
  islemFormuAcik.value = true
}

function islemDuzenle(i: Islem): void {
  duzenlenen.value = i
  islemFormuAcik.value = true
}

function elleAc(): void {
  elleFiyat.value = varlik.value?.fiyat?.fiyat ?? null
  elle.hatalar.value = {}
  elleAcik.value = true
}

async function elleKaydet(): Promise<void> {
  if (elleFiyat.value === null) return
  const sonuc = await elle.gonder(
    () => finansApi.elleFiyat(props.id, elleFiyat.value!),
    'Fiyat kaydedildi',
  )
  if (sonuc) {
    elleAcik.value = false
    grafikAnahtari.value++
    await yukle()
  }
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <Message v-if="bulunamadi" severity="error">Varlık bulunamadı.</Message>
    <template v-else>
      <section class="kart p-5 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="flex min-w-0 items-center gap-3">
            <span
              class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-surface-100 text-lg text-surface-600"
            >
              <i :class="varlik ? VARLIK_TURLERI[varlik.tur].ikon : 'pi pi-circle'" />
            </span>
            <div class="min-w-0">
              <Skeleton v-if="!varlik" width="10rem" height="1.5rem" />
              <h1 v-else class="truncate text-lg font-bold text-surface-900">{{ varlik.ad }}</h1>
              <p v-if="varlik" class="flex flex-wrap items-center gap-2 text-xs text-surface-500">
                {{ varlik.kod }} · {{ VARLIK_TURLERI[varlik.tur].etiket }} · {{ varlik.birim }}
                <Tag
                  v-if="varlik.gecikmeli"
                  value="~15 dk gecikmeli"
                  severity="secondary"
                  class="!text-[10px]"
                />
              </p>
            </div>
          </div>
          <Button
            v-if="varlik && !sabit"
            :icon="varlik.izleniyor ? 'pi pi-eye-slash' : 'pi pi-eye'"
            :label="varlik.izleniyor ? 'İzlemeden çıkar' : 'İzle'"
            severity="secondary"
            outlined
            size="small"
            @click="izlemeDegistir"
          />
        </div>

        <p v-if="varlik && sabit" class="mt-3 text-sm text-surface-500">
          {{
            varlik.tur === 'bes'
              ? 'Toplam tutarı ve kâr yüzdesini "Güncel tutar" ile girersiniz; her giriş öncekinin yerine geçer.'
              : 'Hesaptaki TL nakit: yatırma, çekme ya da elde olan tutar olarak girilir.'
          }}
        </p>
        <div v-else-if="varlik" class="mt-4 flex flex-wrap items-end justify-between gap-3">
          <div>
            <p
              class="text-2xl font-bold tracking-tight text-surface-900 tabular-nums min-[375px]:text-3xl"
            >
              {{
                varlik.fiyat
                  ? fiyat(varlik.fiyat.satis ?? varlik.fiyat.fiyat, varlik.para_birimi)
                  : 'Fiyat yok'
              }}
            </p>
            <p class="mt-1 flex flex-wrap items-center gap-x-3 text-sm text-surface-500">
              <DegisimEtiketi :yuzde="varlik.fiyat?.degisim_yuzde ?? null" />
              <span v-if="varlik.fiyat?.alis && varlik.fiyat?.satis" class="tabular-nums">
                Alış {{ fiyat(varlik.fiyat.alis, varlik.para_birimi) }} · Satış
                {{ fiyat(varlik.fiyat.satis, varlik.para_birimi) }}
              </span>
              <span v-if="varlik.para_birimi !== 'TRY' && varlik.tl_fiyat" class="tabular-nums"
                >≈ {{ fiyat(varlik.tl_fiyat) }}</span
              >
            </p>
            <p class="mt-1 text-xs text-surface-400">
              {{
                varlik.fiyat
                  ? `${tarihSaat(varlik.fiyat.zaman)} · ${varlik.fiyat.elle ? 'elle girildi' : varlik.kaynak}`
                  : 'Bu varlığın fiyatı henüz girilmedi.'
              }}
            </p>
          </div>
          <Button
            v-if="auth.yonetici"
            label="Elle fiyat"
            icon="pi pi-pencil"
            severity="secondary"
            text
            size="small"
            @click="elleAc"
          />
        </div>
      </section>

      <section v-if="varlik && !sabit" class="kart p-4 sm:p-5">
        <FiyatGrafigi :key="grafikAnahtari" :varlik-id="id" :para-birimi="varlik.para_birimi" />
      </section>

      <section class="kart p-4 sm:p-5">
        <div class="mb-2 flex items-center justify-between gap-2">
          <h2 class="bolum-etiketi">Bende</h2>
          <Button label="İşlem ekle" icon="pi pi-plus" size="small" @click="islemEkle" />
        </div>
        <p v-if="!toplam" class="py-4 text-center text-sm text-surface-500">
          Bu varlıktan hiçbir hesabınızda yok.
        </p>
        <template v-else>
          <div class="mb-2 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div v-if="!sabit" class="rounded-2xl bg-surface-50 p-3">
              <p class="text-xs text-surface-500">Miktar</p>
              <p class="font-semibold tabular-nums">
                {{ miktar(toplam.miktar) }} {{ toplam.varlik.birim }}
              </p>
            </div>
            <div class="rounded-2xl bg-surface-50 p-3">
              <p class="text-xs text-surface-500">Değer</p>
              <p class="font-semibold tabular-nums">{{ tl(toplam.deger) }}</p>
            </div>
            <div v-if="!sabit" class="rounded-2xl bg-surface-50 p-3">
              <p class="text-xs text-surface-500">Ort. maliyet</p>
              <p class="font-semibold tabular-nums">
                {{ toplam.ortalama_maliyet !== null ? fiyat(toplam.ortalama_maliyet) : '—' }}
              </p>
            </div>
            <div v-if="toplam.varlik.tur === 'bes'" class="rounded-2xl bg-surface-50 p-3">
              <p class="text-xs text-surface-500">Ödenen</p>
              <p class="font-semibold tabular-nums">{{ tl(toplam.maliyet) }}</p>
            </div>
            <div v-if="toplam.varlik.tur !== 'nakit'" class="rounded-2xl bg-surface-50 p-3">
              <p class="text-xs text-surface-500">Kâr / zarar</p>
              <DegisimEtiketi :tutar="toplam.kz" :yuzde="toplam.kz_yuzde" />
            </div>
          </div>
          <PozisyonListesi
            v-if="pozisyonlar.length > 1"
            :pozisyonlar="pozisyonlar"
            :hesap-adi="(p) => p.hesap_ad"
          />
          <p v-else-if="pozisyonlar[0]" class="text-xs text-surface-500">
            Hesap: {{ pozisyonlar[0].hesap_ad }}
          </p>
        </template>
      </section>

      <section v-if="islemler.length > 0" class="kart p-4 sm:p-5">
        <h2 class="bolum-etiketi mb-1">İşlemlerim</h2>
        <IslemListesi :islemler="islemler" hesap-goster @duzenle="islemDuzenle" @silindi="yukle" />
      </section>
    </template>

    <IslemFormu
      v-model:visible="islemFormuAcik"
      :islem="duzenlenen"
      :varsayilan="{ varlik_id: id }"
      @kaydedildi="yukle"
    />

    <UygulamaPenceresi v-model:visible="elleAcik" baslik="Elle fiyat" :genislik="24">
      <form class="flex flex-col gap-4" @submit.prevent="elleKaydet">
        <p class="text-sm text-surface-500">
          Fonlar ya da kaynak bozulduğunda kullanılır. Otomatik kaynaklı varlıkta bir sonraki
          güncelleme bu fiyatın üzerine yazar.
        </p>
        <FormAlani
          :etiket="`Fiyat (${varlik?.para_birimi === 'USD' ? 'USD' : 'TL'})`"
          for="elle-fiyat"
          :hata="elle.hatalar.value.fiyat"
        >
          <InputNumber
            v-model="elleFiyat"
            input-id="elle-fiyat"
            locale="tr-TR"
            :max-fraction-digits="8"
            :min="0"
            inputmode="decimal"
            autofocus
          />
        </FormAlani>
        <div class="flex justify-end gap-2">
          <Button label="Vazgeç" severity="secondary" text @click="elleAcik = false" />
          <Button
            type="submit"
            label="Kaydet"
            icon="pi pi-check"
            :loading="elle.gonderiliyor.value"
            :disabled="!elleFiyat"
          />
        </div>
      </form>
    </UygulamaPenceresi>
  </div>
</template>
