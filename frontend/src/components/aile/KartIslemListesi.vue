<script setup lang="ts">
import { onBeforeUnmount } from 'vue'
import KartEtiketi from '@/components/aile/KartEtiketi.vue'
import { KART_ISLEM_TURLERI, type KartIslemi } from '@/api/aile'
import { useUzunBasma } from '@/composables/useUzunBasma'
import { useAileStore } from '@/stores/aile'
import { tarih, tl } from '@/utils/bicim'
import { kategoriNoktasi, kategoriSeridi } from '@/utils/kategoriRengi'

/**
 * Kart satırları: tarih, açıklama, kart, kategori, tür, taksit; tutarın rengi gider yönünü gösterir (gider, iade,
 * gidere girmeyen). Satırın solunda kategorinin rengiyle çok yumuşak, yarı saydam bir gradient vardır (kategorisizde
 * gri). Satıra dokununca düzenleme; basılı tutunca (ya da sağ tık) kategori seçimi.
 */
defineProps<{ islemler: KartIslemi[]; kartGoster?: boolean }>()
const emit = defineEmits<{ sec: [islem: KartIslemi]; kategori: [islem: KartIslemi] }>()
const aile = useAileStore()
const uzun = useUzunBasma<KartIslemi>((i) => emit('kategori', i))
onBeforeUnmount(uzun.temizle)

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
    <li v-for="i in islemler" :key="i.id" class="relative overflow-hidden">
      <!-- Kategori rengi: soldan sağa sönen, yarı saydam gradient -->
      <span
        class="pointer-events-none absolute inset-y-0 left-0 w-16"
        :style="{ background: kategoriSeridi(aile.kategori(i.kategori_id)) }"
        aria-hidden="true"
        data-test="kategori-seridi"
      />
      <button
        type="button"
        data-test="kart-islemi"
        class="relative flex w-full [-webkit-touch-callout:none] items-center gap-3 px-1 py-3 pl-3 text-left transition-colors select-none hover:bg-surface-50/60 sm:px-2 sm:pl-3"
        @pointerdown="uzun.basla($event, i)"
        @pointermove="uzun.hareket"
        @pointerup="uzun.iptal"
        @pointercancel="uzun.iptal"
        @pointerleave="uzun.iptal"
        @contextmenu="uzun.menu($event, i)"
        @click="tikla(i)"
      >
        <span class="w-11 shrink-0 text-center text-xs leading-tight text-surface-500 tabular-nums">
          {{ i.islem_tarihi ? tarih(i.islem_tarihi).slice(0, 5) : '—' }}
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
    </li>
  </ul>
</template>
