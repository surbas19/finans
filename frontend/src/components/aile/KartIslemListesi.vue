<script setup lang="ts">
import { KART_ISLEM_TURLERI, type KartIslemi } from '@/api/aile'
import { useAileStore } from '@/stores/aile'
import { tarih, tl } from '@/utils/bicim'

/**
 * Kart satırları: tarih, açıklama, kart, kategori, tür, taksit; tutarın rengi gider yönünü gösterir (gider, iade,
 * gidere girmeyen). Satıra dokununca düzenleme.
 */
defineProps<{ islemler: KartIslemi[]; kartGoster?: boolean }>()
const emit = defineEmits<{ sec: [islem: KartIslemi] }>()
const aile = useAileStore()

function tutarRengi(i: KartIslemi): string {
  const yon = KART_ISLEM_TURLERI[i.tur].gider

  return yon > 0 ? 'text-surface-900' : yon < 0 ? 'text-emerald-700' : 'text-surface-400'
}

function isaret(i: KartIslemi): string {
  const yon = KART_ISLEM_TURLERI[i.tur].gider

  return yon < 0 ? '−' : ''
}
</script>

<template>
  <ul class="divide-y divide-surface-100">
    <li v-for="i in islemler" :key="i.id">
      <button
        type="button"
        data-test="kart-islemi"
        class="flex w-full items-center gap-3 px-1 py-3 text-left transition-colors hover:bg-surface-50 sm:px-2"
        @click="emit('sec', i)"
      >
        <span class="w-11 shrink-0 text-center text-xs leading-tight text-surface-500 tabular-nums">
          {{ i.islem_tarihi ? tarih(i.islem_tarihi).slice(0, 5) : '—' }}
        </span>
        <span class="min-w-0 flex-1">
          <span class="block truncate font-semibold text-surface-900">{{ i.aciklama }}</span>
          <span class="flex flex-wrap gap-x-1.5 text-xs text-surface-500">
            <span v-if="kartGoster">{{ aile.kartAdi(i.kart_id) }}</span>
            <span v-if="aile.kategori(i.kategori_id)"
              >· {{ aile.kategori(i.kategori_id)!.ad }}</span
            >
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
