<script setup lang="ts">
import BosDurum from '@/components/BosDurum.vue'
import type { ZekatYili } from '@/api/aile'
import { tarih, tl } from '@/utils/bicim'

/**
 * Zekât yılı geçmişi: gün, durum, zekât tutarı, ödenen ve kalan. Bir satıra dokununca ayrıntı açılır.
 */
defineProps<{ yillar: ZekatYili[] }>()
defineEmits<{ sec: [yil: ZekatYili] }>()
</script>

<template>
  <BosDurum
    v-if="!yillar.length"
    kucuk
    baslik="Henüz zekât kaydı yok"
    aciklama="Hesapladıktan sonra anlık görüntüyü kaydedin; kesinleştirip ödemeleri ekleyin."
    ikon="pi pi-history"
  />
  <ul v-else class="flex flex-col gap-2" data-test="zekat-yillar">
    <li v-for="y in yillar" :key="y.id">
      <button
        type="button"
        class="kart flex w-full items-center gap-3 p-3 text-left sm:p-4"
        @click="$emit('sec', y)"
      >
        <span class="min-w-0 flex-1">
          <span class="flex flex-wrap items-center gap-2">
            <span class="font-bold text-surface-900">{{ tarih(y.zekat_gunu) }}</span>
            <span
              class="rounded-full px-2 py-0.5 text-xs font-semibold"
              :class="
                y.durum === 'kesinlesti'
                  ? 'bg-emerald-100 text-emerald-800'
                  : 'bg-amber-100 text-amber-800'
              "
              >{{ y.durum === 'kesinlesti' ? 'Kesinleşti' : 'Taslak' }}</span
            >
          </span>
          <span class="block text-xs text-surface-500">
            <template v-if="y.nisab_ustu"
              >Ödenen {{ tl(y.odenen) }} · kalan {{ tl(y.kalan) }}</template
            >
            <template v-else>Nisabın altında</template>
            <template v-if="y.eksik_veri"> · eksik veri</template>
          </span>
        </span>
        <span class="text-right font-semibold whitespace-nowrap tabular-nums">{{
          tl(y.zekat)
        }}</span>
        <i class="pi pi-angle-right text-surface-400" />
      </button>
    </li>
  </ul>
</template>
