<script setup lang="ts">
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import { islemEtiketi, type Islem } from '@/api/finans'
import { useIslemSil } from '@/composables/useIslemSil'
import { fiyat, miktar, tarih, tl, yuzde } from '@/utils/bicim'

/**
 * İşlem geçmişi: tarih, tür, varlık, miktar × fiyat, tutar.
 * Telefonda her satır kart gibidir: dokununca düzenleme açılır (silme düzenleme penceresinde). Masaüstünde tarih
 * sütunu ve satır sonunda düzenle / sil düğmeleri.
 */
const props = defineProps<{
  islemler: Islem[]
  /** Satırda hesap adı gösterilsin (tüm işlemler sayfası) */
  hesapGoster?: boolean
}>()
const emit = defineEmits<{ duzenle: [islem: Islem]; silindi: [] }>()

const { sil } = useIslemSil()

const TUR_RENGI = {
  alis: 'success',
  satis: 'danger',
  mevcut: 'secondary',
  deger: 'info',
} as const

/** Miktar × fiyat; nakitte yok (tutar yeterli), bireysel emeklilikte kâr yüzdesi */
function ayrinti(i: Islem): string | null {
  const tur = i.varlik?.tur
  if (tur === 'nakit') return null
  if (tur === 'bes') return i.kar_yuzde != null ? `${yuzde(i.kar_yuzde)} kâr` : 'kâr yüzdesi yok'
  const birim = `${miktar(i.miktar)} ${i.varlik?.birim ?? ''}`.trim()

  return i.birim_fiyat !== null
    ? `${birim} × ${fiyat(i.birim_fiyat)}`
    : `${birim} · fiyat bilinmiyor`
}

/** Satırın alt yazısı: (telefonda tarih) · ayrıntı · komisyon · hesap */
function ayrintiMetni(i: Islem, tarihli: boolean): string {
  return [
    tarihli ? tarih(i.tarih) : null,
    ayrinti(i),
    i.komisyon > 0 ? `komisyon ${tl(i.komisyon)}` : null,
    props.hesapGoster && i.hesap_ad ? i.hesap_ad : null,
  ]
    .filter(Boolean)
    .join(' · ')
}
</script>

<template>
  <ul class="divide-y divide-surface-100">
    <li v-for="i in islemler" :key="i.id">
      <!-- Telefon -->
      <button
        type="button"
        class="flex min-h-14 w-full items-center gap-3 py-3 text-left transition-colors active:bg-surface-50 sm:hidden"
        :aria-label="`${tarih(i.tarih)} ${islemEtiketi(i.tur, i.varlik?.tur)} ${i.varlik?.ad ?? ''}: düzenle`"
        data-test="islem-karti"
        @click="emit('duzenle', i)"
      >
        <span class="min-w-0 flex-1">
          <span class="flex items-center gap-2">
            <Tag
              :value="islemEtiketi(i.tur, i.varlik?.tur)"
              :severity="TUR_RENGI[i.tur]"
              class="shrink-0"
            />
            <span class="truncate font-medium text-surface-900">{{ i.varlik?.ad ?? '' }}</span>
          </span>
          <span class="mt-1 line-clamp-2 text-xs text-surface-500 tabular-nums">
            {{ ayrintiMetni(i, true) }}
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
            <Tag :value="islemEtiketi(i.tur, i.varlik?.tur)" :severity="TUR_RENGI[i.tur]" />
            <span class="truncate font-medium text-surface-900">{{ i.varlik?.ad ?? '' }}</span>
          </div>
          <div class="mt-0.5 truncate text-xs text-surface-500">
            {{ ayrintiMetni(i, false) }}
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
