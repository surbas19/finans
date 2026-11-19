<script setup lang="ts">
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import type { Tasarruf } from '@/api/aile'
import IslemNo from '@/components/IslemNo.vue'
import { miktar as miktarMetni, tarih, tarihSaat, tl } from '@/utils/bicim'

/**
 * Tasarruf kalemi (Özet ve Tasarruf sekmesi): ne alındı, adet, tutar, hesap ya da "Verildi", gün adı ve açıklama,
 * işlem numaraları (kırmızı, küçük), alım tarihi ve işlem (kayıt) zamanı. Kaleme dokununca düzeltme penceresi açılır.
 */
defineProps<{ kalem: Tasarruf }>()
const emit = defineEmits<{
  sil: [k: Tasarruf]
  dahil: [k: Tasarruf, dahil: boolean]
  duzenle: [k: Tasarruf]
}>()
</script>

<template>
  <div
    class="flex items-start gap-3 py-2.5"
    :class="kalem.islem_dahil === false ? 'opacity-55' : ''"
    :data-test="kalem.hesaba_eklendi ? 'tasarruf-kalemi' : 'tasarruf-kalemi-verilen'"
  >
    <button
      type="button"
      class="-my-1 min-w-0 flex-1 rounded-xl py-1 text-left transition-colors hover:bg-surface-50"
      :aria-label="`${kalem.varlik_ad} tasarrufunu düzelt`"
      data-test="tasarruf-duzenle"
      @click="emit('duzenle', kalem)"
    >
      <span class="flex flex-wrap items-center gap-1.5">
        <span class="truncate text-sm font-semibold">{{ kalem.varlik_ad }}</span>
        <Tag
          v-if="!kalem.hesaba_eklendi"
          value="Verildi"
          severity="warn"
          data-test="verildi-etiketi"
        />
        <Tag v-if="kalem.islem_dahil === false" value="Hariç" severity="secondary" />
      </span>
      <span class="block text-xs text-surface-500">
        <template v-if="kalem.miktar !== null && kalem.varlik_tur !== 'nakit'"
          ><span data-test="tasarruf-adet"
            >{{ miktarMetni(kalem.miktar) }} {{ kalem.varlik_birim }}</span
          >
          ·
        </template>
        <template v-if="kalem.hesaba_eklendi">{{ kalem.hesap_ad }}</template>
        <template v-else>hiçbir hesaba eklenmedi</template>
      </span>
      <span
        v-if="kalem.gun_ad || kalem.not"
        class="block text-xs text-surface-600"
        data-test="tasarruf-aciklama"
      >
        <template v-if="kalem.gun_ad">{{ kalem.gun_ad }}</template
        ><template v-if="kalem.gun_ad && kalem.not"> — </template
        ><template v-if="kalem.not">{{ kalem.not }}</template>
      </span>
      <span class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-surface-400">
        <IslemNo :no="kalem.no" />
        <IslemNo v-if="kalem.islem_no" :no="kalem.islem_no" />
        <span>Alım: {{ tarih(kalem.tarih) }}</span>
        <span v-if="kalem.islem_zamani" data-test="islem-zamani"
          >İşlem: {{ tarihSaat(kalem.islem_zamani) }}</span
        >
      </span>
    </button>
    <span class="font-semibold text-sky-700 tabular-nums">{{ tl(kalem.tutar) }}</span>
    <span class="flex shrink-0 flex-col items-center">
      <Button
        v-if="kalem.islem_id !== null"
        :icon="kalem.islem_dahil === false ? 'pi pi-eye-slash' : 'pi pi-eye'"
        text
        rounded
        size="small"
        severity="secondary"
        :aria-label="kalem.islem_dahil === false ? 'Genel toplama ekle' : 'Genel toplamdan çıkar'"
        data-test="tasarruf-dahil"
        @click="emit('dahil', kalem, kalem.islem_dahil === false)"
      />
      <Button
        icon="pi pi-trash"
        severity="danger"
        text
        rounded
        size="small"
        :aria-label="`${kalem.varlik_ad} tasarrufunu sil`"
        @click="emit('sil', kalem)"
      />
    </span>
  </div>
</template>
