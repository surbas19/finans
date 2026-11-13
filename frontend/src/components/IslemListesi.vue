<script setup lang="ts">
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import { finansApi, ISLEM_TURLERI, type Islem } from '@/api/finans'
import { dogrulamaHatalari, hataMesaji } from '@/api/http'
import { fiyat, miktar, tarih, tl } from '@/utils/bicim'

/**
 * İşlem geçmişi: tarih, tür, varlık, miktar × fiyat, tutar; düzenle / sil.
 */
defineProps<{
  islemler: Islem[]
  /** Satırda hesap adı gösterilsin (tüm işlemler sayfası) */
  hesapGoster?: boolean
}>()
const emit = defineEmits<{ duzenle: [islem: Islem]; silindi: [] }>()

const confirm = useConfirm()
const toast = useToast()

const TUR_RENGI = { alis: 'success', satis: 'danger', mevcut: 'secondary' } as const

function sil(islem: Islem): void {
  confirm.require({
    header: 'İşlemi sil',
    message: `${tarih(islem.tarih)} tarihli ${ISLEM_TURLERI[islem.tur].toLocaleLowerCase('tr-TR')} kaydı silinsin mi?`,
    acceptProps: { label: 'Sil', severity: 'danger' },
    rejectProps: { label: 'Vazgeç', severity: 'secondary', text: true },
    accept: async () => {
      try {
        await finansApi.islemSil(islem.id)
        toast.add({ severity: 'success', summary: 'İşlem silindi', life: 3000 })
        emit('silindi')
      } catch (e) {
        const hata = Object.values(dogrulamaHatalari(e))[0]
        toast.add({
          severity: 'error',
          summary: 'Silinemedi',
          detail: hata ?? hataMesaji(e),
          life: 6000,
        })
      }
    },
  })
}
</script>

<template>
  <ul class="divide-y divide-surface-100">
    <li v-for="i in islemler" :key="i.id" class="flex items-center gap-3 py-3">
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
          @click="sil(i)"
        />
      </div>
    </li>
  </ul>
</template>
