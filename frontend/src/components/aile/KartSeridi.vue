<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue'
import type { Kart } from '@/api/aile'
import { kartDegradesi, kartRengiCss } from '@/utils/kartRengi'

/**
 * Kart detayında kartlar arası şerit: yana kayan çipler (kartın renginde nokta + ad; seçili olan kartın degradesiyle
 * dolu). Pasif kart soluk. Seçili çip açılışta ortaya kaydırılır. Dokununca `sec` (yönüyle: sağdaki kart +1).
 */
const props = defineProps<{ kartlar: Kart[]; seciliId: number }>()
const emit = defineEmits<{ sec: [id: number, yon: number] }>()

const serit = ref<HTMLElement | null>(null)
onMounted(async () => {
  await nextTick()
  const secili = serit.value?.querySelector<HTMLElement>('[aria-current="page"]')
  secili?.scrollIntoView?.({ inline: 'center', block: 'nearest' })
})

function sec(id: number): void {
  if (id === props.seciliId) return
  const simdi = props.kartlar.findIndex((k) => k.id === props.seciliId)
  const hedef = props.kartlar.findIndex((k) => k.id === id)
  emit('sec', id, hedef > simdi ? 1 : -1)
}
</script>

<template>
  <nav
    ref="serit"
    class="-mx-1 flex gap-2 overflow-x-auto px-1 py-0.5 [scrollbar-width:none]"
    aria-label="Kartlar"
    data-test="kart-seridi"
  >
    <button
      v-for="k in kartlar"
      :key="k.id"
      type="button"
      class="flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-semibold whitespace-nowrap ring-1 transition-all active:scale-95"
      :class="[
        k.id === seciliId
          ? 'text-white shadow-sm ring-transparent'
          : 'bg-surface-0 text-surface-700 ring-surface-200',
        k.aktif ? '' : 'opacity-60',
      ]"
      :style="k.id === seciliId ? { background: kartDegradesi(k) } : undefined"
      :aria-current="k.id === seciliId ? 'page' : undefined"
      data-test="kart-cipi"
      @click="sec(k.id)"
    >
      <span
        v-if="k.id !== seciliId"
        class="size-2.5 rounded-full"
        :style="{ background: kartRengiCss(k) }"
        aria-hidden="true"
      />
      {{ k.ad }}
    </button>
  </nav>
</template>
