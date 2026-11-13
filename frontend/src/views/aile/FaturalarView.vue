<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import Button from 'primevue/button'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import Skeleton from 'primevue/skeleton'
import { useToast } from 'primevue/usetoast'
import BosDurum from '@/components/BosDurum.vue'
import AySecici from '@/components/aile/AySecici.vue'
import FaturaBaglaDialog from '@/components/aile/FaturaBaglaDialog.vue'
import FaturaFormu from '@/components/aile/FaturaFormu.vue'
import { aileApi, GUVEN_ETIKETLERI, type Fatura, type FaturaDurumu } from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { useSonIstek } from '@/composables/useSonIstek'
import { useAileStore } from '@/stores/aile'
import { tarih, tl } from '@/utils/bicim'
import { buAy } from '@/utils/donem'

/**
 * Faturalar hizmet ayına göre (ödendiği aya göre değil). Kartla ödenen faturanın kart satırı görünür; yanlış
 * eşleşme "Bağı çöz" ile düzeltilir, doğru satıra bağlanır. Dönemi bilinmeyenler ayrı listelenir.
 */
const aile = useAileStore()
const toast = useToast()
const sonIstek = useSonIstek()

const gorunum = ref<'ay' | 'bilinmeyen'>('ay')
const donem = ref(buAy())
const turId = ref<number | null>(null)
const durum = ref<FaturaDurumu | null>(null)
const faturalar = ref<Fatura[] | null>(null)
const toplam = ref(0)
const formAcik = ref(false)
const duzenlenen = ref<Fatura | null>(null)
const baglaAcik = ref(false)
const baglanan = ref<Fatura | null>(null)
const tumBagliIslemler = ref<number[]>([])

const GORUNUMLER = [
  { deger: 'ay', etiket: 'Hizmet ayına göre' },
  { deger: 'bilinmeyen', etiket: 'Dönemi bilinmeyen' },
]
const DURUMLAR = [
  { deger: 'bekliyor', etiket: 'Ödenmedi' },
  { deger: 'odendi', etiket: 'Ödendi' },
]

async function yukle(): Promise<void> {
  const veri = await sonIstek(() =>
    Promise.all([
      aileApi.faturalar({
        donem: gorunum.value === 'ay' ? donem.value : null,
        bilinmeyen: gorunum.value === 'bilinmeyen',
        fatura_turu_id: turId.value,
        durum: durum.value,
      }),
      aileApi.faturalar({}),
    ]),
  )
  if (veri === undefined) return
  faturalar.value = veri[0].faturalar
  toplam.value = veri[0].toplam
  // Bir kart satırı tek faturaya bağlanır: bağlama penceresinde bağlıları gizlemek için
  tumBagliIslemler.value = veri[1].faturalar
    .map((f) => f.kart_islemi_id)
    .filter((id): id is number => id !== null)
}

onMounted(async () => {
  await aile.yukle()
  await yukle()
})
watch([gorunum, donem, turId, durum], () => {
  faturalar.value = null
  void yukle()
})

const durumEtiketi = (f: Fatura) =>
  f.gecikmis
    ? { metin: 'Gecikmiş', renk: 'bg-red-100 text-red-700' }
    : f.durum === 'odendi'
      ? { metin: 'Ödendi', renk: 'bg-emerald-100 text-emerald-800' }
      : { metin: 'Ödenmedi', renk: 'bg-amber-100 text-amber-800' }

const turler = computed(() => aile.turler)

function duzenle(f: Fatura | null): void {
  duzenlenen.value = f
  formAcik.value = true
}

function baglamayaAc(f: Fatura): void {
  baglanan.value = f
  baglaAcik.value = true
}

async function coz(f: Fatura): Promise<void> {
  try {
    await aileApi.faturaCoz(f.id)
    toast.add({
      severity: 'success',
      summary: 'Bağ çözüldü; fatura ödenmemiş görünüyor',
      life: 4000,
    })
    await yukle()
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Yapılamadı', detail: hataMesaji(e), life: 5000 })
  }
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <SelectButton
        v-model="gorunum"
        :options="GORUNUMLER"
        option-label="etiket"
        option-value="deger"
        :allow-empty="false"
        aria-label="Görünüm"
      />
      <Button
        label="Fatura ekle"
        icon="pi pi-plus"
        :disabled="!aile.saglayicilar.length"
        @click="duzenle(null)"
      />
    </div>
    <p v-if="!aile.saglayicilar.length" class="kart p-4 text-sm text-surface-600">
      Önce Ayarlar'dan fatura sağlayıcılarınızı (ör. Enerjisa, İSKİ, İGDAŞ) ve ekstrede görünen
      yazımlarını ekleyin; ekstre yüklenince fatura ödemeleri bunlarla bulunur.
    </p>
    <div class="flex flex-wrap items-center gap-2">
      <AySecici v-if="gorunum === 'ay'" v-model="donem" />
      <Select
        v-model="turId"
        :options="turler"
        option-label="ad"
        option-value="id"
        placeholder="Tür"
        show-clear
        aria-label="Fatura türü"
        class="w-36"
      />
      <Select
        v-model="durum"
        :options="DURUMLAR"
        option-label="etiket"
        option-value="deger"
        placeholder="Durum"
        show-clear
        aria-label="Durum"
        class="w-36"
      />
      <span class="ml-auto text-sm font-semibold text-surface-900 tabular-nums"
        >Toplam {{ tl(toplam) }}</span
      >
    </div>

    <div v-if="faturalar === null" class="flex flex-col gap-2">
      <Skeleton v-for="n in 3" :key="n" height="5rem" border-radius="1.5rem" />
    </div>
    <BosDurum
      v-else-if="!faturalar.length"
      baslik="Fatura yok"
      aciklama="Ekstre yükleyince fatura ödemeleri bulunur; eski faturaları elle de girebilirsiniz."
      ikon="pi pi-bolt"
    />
    <ul v-else class="flex flex-col gap-2">
      <li v-for="f in faturalar" :key="f.id" class="kart p-4" data-test="fatura">
        <div class="flex items-start gap-3">
          <span
            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-surface-100 text-surface-600"
          >
            <i :class="aile.tur(f.fatura_turu_id)?.ikon ?? 'pi pi-file'" />
          </span>
          <button type="button" class="min-w-0 flex-1 text-left" @click="duzenle(f)">
            <span class="block truncate font-semibold text-surface-900">{{ f.saglayici_ad }}</span>
            <span class="block text-xs text-surface-500">
              Hizmet: {{ f.hizmet_donemi_etiketi ?? 'bilinmiyor' }}
              <template v-if="f.donem_kaynagi === 'aciklama'"> (ekstreden)</template>
              <template v-if="f.son_odeme_tarihi">
                · son ödeme {{ tarih(f.son_odeme_tarihi) }}</template
              >
              <template v-if="f.odeme_tarihi"> · ödendi {{ tarih(f.odeme_tarihi) }}</template>
            </span>
          </button>
          <span class="flex flex-col items-end gap-1">
            <span class="font-semibold tabular-nums">{{ tl(f.tutar) }}</span>
            <span
              class="rounded-full px-2 py-0.5 text-[11px] font-semibold"
              :class="durumEtiketi(f).renk"
              >{{ durumEtiketi(f).metin }}</span
            >
          </span>
        </div>
        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs sm:pl-13">
          <template v-if="f.kart_islemi">
            <span class="text-surface-600">
              <i class="pi pi-credit-card" /> {{ aile.kartAdi(f.kart_islemi.kart_id) }} ·
              {{ tarih(f.kart_islemi.islem_tarihi) }} · {{ f.kart_islemi.aciklama }} ·
              {{ tl(f.kart_islemi.tutar) }}
            </span>
            <span
              v-if="f.eslesme?.guven"
              class="rounded-full px-2 py-0.5 font-semibold"
              :class="GUVEN_ETIKETLERI[f.eslesme.guven].renk"
            >
              {{ f.eslesme.otomatik ? 'Otomatik' : GUVEN_ETIKETLERI[f.eslesme.guven].etiket }}
            </span>
            <Button
              label="Bağı çöz"
              size="small"
              text
              severity="danger"
              class="ml-auto"
              @click="coz(f)"
            />
          </template>
          <Button
            v-else
            label="Kart satırına bağla"
            icon="pi pi-link"
            size="small"
            text
            class="ml-auto"
            @click="baglamayaAc(f)"
          />
        </div>
      </li>
    </ul>

    <FaturaFormu
      v-model:visible="formAcik"
      :fatura="duzenlenen"
      :varsayilan-donem="gorunum === 'ay' ? donem : null"
      @kaydedildi="yukle"
    />
    <FaturaBaglaDialog
      v-model:visible="baglaAcik"
      :fatura="baglanan"
      :bagli-islemler="tumBagliIslemler"
      @baglandi="yukle"
    />
  </div>
</template>
