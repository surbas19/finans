<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue'
import KartEtiketi from '@/components/aile/KartEtiketi.vue'
import SirketGecmisiDialog from '@/components/aile/SirketGecmisiDialog.vue'
import { KART_ISLEM_TURLERI, type KartIslemi } from '@/api/aile'
import { useUzunBasma } from '@/composables/useUzunBasma'
import { useAileStore } from '@/stores/aile'
import { tl } from '@/utils/bicim'
import { kategoriKutusu, kategoriNoktasi } from '@/utils/kategoriRengi'

/**
 * Kart satırları: tarih kutusu (kategori renginde; kategorisizde gri), açıklama, kart, kategori, tür, taksit; tutarın
 * rengi gider yönünü gösterir (gider, iade, gidere girmeyen). Satıra dokununca düzenleme; basılı tutunca (ya da sağ
 * tık) kategori seçimi. Gidere giren satırın sağındaki grafik düğmesi şirket geçmişini (aynı şirketten bütün
 * harcamalar, ay ay toplamlar) tam ekran açar.
 */
defineProps<{ islemler: KartIslemi[]; kartGoster?: boolean }>()
const emit = defineEmits<{ sec: [islem: KartIslemi]; kategori: [islem: KartIslemi] }>()
const aile = useAileStore()
const uzun = useUzunBasma<KartIslemi>((i) => emit('kategori', i))
onBeforeUnmount(uzun.temizle)

const gecmisAcik = ref(false)
const gecmisIslem = ref<KartIslemi | null>(null)
function gecmisAc(i: KartIslemi): void {
  gecmisIslem.value = i
  gecmisAcik.value = true
}

/** Tarih kutusu: gün ve kısa ay ("05" / "Kas") */
function gun(t: string | null): string {
  return t ? t.slice(8, 10) : '—'
}
function ay(t: string | null): string {
  return t ? new Date(`${t}T00:00:00`).toLocaleDateString('tr-TR', { month: 'short' }) : ''
}

function tutarRengi(i: KartIslemi): string {
  const yon = KART_ISLEM_TURLERI[i.tur].gider

  return yon > 0 ? 'text-surface-900' : yon < 0 ? 'text-emerald-700' : 'text-surface-400'
}

function isaret(i: KartIslemi): string {
  const yon = KART_ISLEM_TURLERI[i.tur].gider

  return yon < 0 ? '−' : ''
}

function tikla(i: KartIslemi): void {
  if (!uzun.tiklamaYut()) emit('sec', i)
}
</script>

<template>
  <ul class="divide-y divide-surface-100">
    <li v-for="i in islemler" :key="i.id" class="relative flex items-center">
      <button
        type="button"
        data-test="kart-islemi"
        class="relative flex min-w-0 flex-1 [-webkit-touch-callout:none] items-center gap-3 px-1 py-2.5 text-left transition-colors select-none hover:bg-surface-50/60 sm:px-2"
        @pointerdown="uzun.basla($event, i)"
        @pointermove="uzun.hareket"
        @pointerup="uzun.iptal"
        @pointercancel="uzun.iptal"
        @pointerleave="uzun.iptal"
        @contextmenu="uzun.menu($event, i)"
        @click="tikla(i)"
      >
        <!-- Tarih kutusu kategori renginde -->
        <span
          class="flex size-10 shrink-0 flex-col items-center justify-center rounded-xl text-center leading-none tabular-nums"
          :style="kategoriKutusu(aile.kategori(i.kategori_id))"
          data-test="tarih-kutusu"
        >
          <span class="text-sm font-bold">{{ gun(i.islem_tarihi) }}</span>
          <span class="mt-0.5 text-xs font-semibold">{{ ay(i.islem_tarihi) }}</span>
        </span>
        <span class="min-w-0 flex-1">
          <span class="block truncate font-semibold text-surface-900">{{ i.aciklama }}</span>
          <span class="flex flex-wrap items-center gap-x-1.5 text-xs text-surface-500">
            <KartEtiketi v-if="kartGoster" :kart-id="i.kart_id" />
            <span
              v-if="aile.kategori(i.kategori_id)"
              class="inline-flex items-center gap-1"
              data-test="kategori-adi"
            >
              <span
                class="size-1.5 rounded-full"
                :style="{ background: kategoriNoktasi(aile.kategori(i.kategori_id)) }"
              />
              {{ aile.kategori(i.kategori_id)!.ad }}
            </span>
            <span v-if="i.tur !== 'harcama'" class="font-medium text-surface-600"
              >· {{ KART_ISLEM_TURLERI[i.tur].etiket }}</span
            >
            <span v-if="i.taksit_sayisi">· taksit {{ i.taksit_no }}/{{ i.taksit_sayisi }}</span>
            <span v-if="i.ekstre_donemi">· {{ i.ekstre_donemi }} ekstresi</span>
          </span>
        </span>
        <span
          class="text-right font-semibold whitespace-nowrap tabular-nums"
          :class="tutarRengi(i)"
        >
          {{ isaret(i) }}{{ tl(i.tutar) }}
        </span>
      </button>
      <!-- Şirket geçmişi (gidere giren satırlarda); ödeme / transfer satırında yer tutucu, tutarlar hizalı kalsın -->
      <button
        v-if="KART_ISLEM_TURLERI[i.tur].gider !== 0"
        type="button"
        class="mr-1 ml-1 flex size-8 shrink-0 items-center justify-center rounded-full bg-primary-50 text-primary-700 transition-colors hover:bg-primary-100 focus-visible:outline-2 focus-visible:outline-primary-500"
        :aria-label="`${i.aciklama}: geçmiş harcamalar`"
        data-test="sirket-gecmisi-dugmesi"
        @click="gecmisAc(i)"
      >
        <i class="pi pi-chart-bar text-sm" />
      </button>
      <span v-else class="mr-1 ml-1 size-8 shrink-0" aria-hidden="true" />
    </li>
  </ul>
  <SirketGecmisiDialog v-model:visible="gecmisAcik" :islem="gecmisIslem" />
</template>
