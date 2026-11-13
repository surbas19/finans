<script setup lang="ts">
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import { ISLEM_TURLERI, type Islem } from '@/api/finans'
import { useIslemSil } from '@/composables/useIslemSil'
import { fiyat, miktar, tarih, tl } from '@/utils/bicim'

/**
 * İşlem geçmişi: tarih, tür, varlık, miktar × fiyat, tutar.
 * Telefonda her satır kart gibidir: dokununca düzenleme açılır (silme düzenleme penceresinde). Masaüstünde tarih
 * sütunu ve satır sonunda düzenle / sil düğmeleri.
 */
defineProps<{
  islemler: Islem[]
  /** Satırda hesap adı gösterilsin (tüm işlemler sayfası) */
  hesapGoster?: boolean
}>()
const emit = defineEmits<{ duzenle: [islem: Islem]; silindi: [] }>()

const { sil } = useIslemSil()

const TUR_RENGI = { alis: 'success', satis: 'danger', mevcut: 'secondary' } as const
</script>

<template>
  <ul class="divide-y divide-surface-100">
    <li v-for="i in islemler" :key="i.id">
      <!-- Telefon -->
      <button
        type="button"
        class="flex min-h-14 w-full items-center gap-3 py-3 text-left transition-colors active:bg-surface-50 sm:hidden"
        :aria-label="`${tarih(i.tarih)} ${ISLEM_TURLERI[i.tur]} ${i.varlik?.ad ?? ''}: düzenle`"
        data-test="islem-karti"
        @click="emit('duzenle', i)"
      >
        <span class="min-w-0 flex-1">
          <span class="flex items-center gap-2">
            <Tag :value="ISLEM_TURLERI[i.tur]" :severity="TUR_RENGI[i.tur]" class="shrink-0" />
            <span class="truncate font-medium text-surface-900">{{ i.varlik?.ad ?? '' }}</span>
          </span>
          <span class="mt-1 line-clamp-2 text-xs text-surface-500 tabular-nums">
            {{ tarih(i.tarih) }} · {{ miktar(i.miktar) }} {{ i.varlik?.birim }}
            <template v-if="i.birim_fiyat !== null"> × {{ fiyat(i.birim_fiyat) }}</template>
            <template v-else> · fiyat bilinmiyor</template>
            <template v-if="i.komisyon > 0"> · komisyon {{ tl(i.komisyon) }}</template>
            <template v-if="hesapGoster && i.hesap_ad"> · {{ i.hesap_ad }}</template>
          </span>
          <span v-if="i.not" class="mt-0.5 block truncate text-xs text-surface-400">{{
            i.not
          }}</span>
        </span>
        <span class="flex shrink-0 items-center gap-1.5">
          <span class="font-semibold whitespace-nowrap text-surface-900 tabular-nums">
            {{ tl(i.tutar) }}
          </span>
          <i class="pi pi-angle-right text-sm text-surface-400" />
        </span>
      </button>

      <!-- Masaüstü -->
      <div class="hidden items-center gap-3 py-3 sm:flex">
        <div class="w-20 shrink-0 text-xs text-surface-500 tabular-nums">{{ tarih(i.tarih) }}</div>
        <div class="min-w-0 flex-1">
          <div class="flex flex-wrap items-center gap-2">
            <Tag :value="ISLEM_TURLERI[i.tur]" :severity="TUR_RENGI[i.tur]" />
            <span class="truncate font-medium text-surface-900">{{ i.varlik?.ad ?? '' }}</span>
          </div>
          <div class="mt-0.5 truncate text-xs text-surface-500">
            {{ miktar(i.miktar) }} {{ i.varlik?.birim }}
            <template v-if="i.birim_fiyat !== null"> × {{ fiyat(i.birim_fiyat) }}</template>
            <template v-else> · fiyat bilinmiyor</template>
            <template v-if="i.komisyon > 0"> · komisyon {{ tl(i.komisyon) }}</template>
            <template v-if="hesapGoster && i.hesap_ad"> · {{ i.hesap_ad }}</template>
          </div>
          <div v-if="i.not" class="mt-0.5 truncate text-xs text-surface-400">{{ i.not }}</div>
        </div>
        <div class="shrink-0 text-right font-semibold text-surface-900 tabular-nums">
          {{ tl(i.tutar) }}
        </div>
        <div class="flex shrink-0">
          <Button
            icon="pi pi-pencil"
            text
            rounded
            size="small"
            aria-label="Düzenle"
            @click="emit('duzenle', i)"
          />
          <Button
            icon="pi pi-trash"
            text
            rounded
            size="small"
            severity="danger"
            aria-label="Sil"
            @click="sil(i, () => emit('silindi'))"
          />
        </div>
      </div>
    </li>
  </ul>
</template>
