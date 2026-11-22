<script setup lang="ts">
import { computed } from 'vue'
import Button from 'primevue/button'
import type { TemettuPlani } from '@/api/finans'
import { tarih, tl } from '@/utils/bicim'

/**
 * Planlı temettü kartı: hisse, kalan gün rozeti, yatacak net para büyük; altında lot × hisse başı, oran ve stopaj.
 * Yatan temettüde (`yatanlar`) eylem düğmesi yoktur.
 */
const props = defineProps<{ plan: TemettuPlani; yatan?: boolean }>()
defineEmits<{ yatti: []; duzenle: []; sil: [] }>()

const gun = computed(() => {
  const g = props.plan.gun_kaldi
  const kisa = new Date(`${props.plan.tarih}T00:00:00`).toLocaleDateString('tr-TR', {
    day: 'numeric',
    month: 'short',
  })
  if (props.yatan) return tarih(props.plan.tarih)
  if (g === 0) return `Bugün · ${kisa}`
  if (g > 0) return `${g} gün · ${kisa}`

  return `${-g} gün geçti · ${kisa}`
})
const gecti = computed(() => !props.yatan && props.plan.gun_kaldi < 0)
const yakin = computed(() => !props.yatan && props.plan.gun_kaldi <= 3)
</script>

<template>
  <article class="kart flex flex-col gap-1.5 p-4" data-test="temettu-karti">
    <div class="flex items-center justify-between gap-2">
      <span class="font-extrabold text-surface-900" data-test="temettu-kod">{{
        plan.varlik.kod
      }}</span>
      <span
        class="rounded-full px-2.5 py-0.5 text-xs font-bold"
        :class="gecti || yakin ? 'bg-amber-100 text-amber-800' : 'bg-primary-50 text-primary-700'"
        data-test="temettu-gun"
        >{{ gun }}</span
      >
    </div>
    <p class="flex items-baseline gap-2">
      <span class="text-2xl font-extrabold text-green-700 tabular-nums" data-test="temettu-net">{{
        plan.net !== null ? tl(plan.net) : '—'
      }}</span>
      <span class="text-xs text-surface-500">{{ yatan ? 'yattı (net)' : 'yatacak (net)' }}</span>
    </p>
    <p
      v-if="!yatan && plan.lot !== null"
      class="text-xs text-surface-500"
      data-test="temettu-ayrinti"
    >
      {{ plan.lot }} lot × {{ tl(plan.hisse_basi)
      }}<template v-if="plan.oran_yuzde !== null">
        · temettü oranı %{{ plan.oran_yuzde.toLocaleString('tr-TR') }}</template
      >
      · stopaj −{{ tl(plan.stopaj) }} (%{{ plan.stopaj_yuzde }}) · {{ plan.hesap_ad }}
    </p>
    <p v-if="plan.not" class="text-xs text-surface-500">{{ plan.not }}</p>
    <div v-if="!yatan" class="mt-1 flex items-center gap-2">
      <Button
        label="Yattı"
        icon="pi pi-check"
        size="small"
        data-test="temettu-yatti"
        @click="$emit('yatti')"
      />
      <Button
        icon="pi pi-pencil"
        text
        rounded
        size="small"
        severity="secondary"
        aria-label="Düzenle"
        @click="$emit('duzenle')"
      />
      <Button
        icon="pi pi-trash"
        text
        rounded
        size="small"
        severity="danger"
        aria-label="Planı sil"
        data-test="temettu-sil"
        @click="$emit('sil')"
      />
    </div>
  </article>
</template>
