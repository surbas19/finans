<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import KaydirmaliPaneller from '@/components/KaydirmaliPaneller.vue'
import AySecici from '@/components/aile/AySecici.vue'
import GelirlerView from '@/views/aile/GelirlerView.vue'
import GiderlerView from '@/views/aile/GiderlerView.vue'
import { buAy } from '@/utils/donem'

/**
 * Gelir / Gider tek sekmede: üstte ortak ay seçici, altında yana kaydırmalı iki panel. Gelir panelinin üstünde ince
 * yeşil, Gider panelinin üstünde ince kırmızı çizgi. Telefonda tek panel görünür, sağa-sola kaydırınca diğeri açılır;
 * geniş ekranda ikisi yan yana. `?panel=gider` ile Gider açık başlar (eski adresler buraya yönlenir).
 */
const PANELLER = [
  { anahtar: 'gelir', etiket: 'Gelir', renk: 'emerald' },
  { anahtar: 'gider', etiket: 'Gider', renk: 'red' },
] as const

const route = useRoute()
const router = useRouter()
const donem = ref(buAy())
const sira = ref(route.query.panel === 'gider' ? 1 : 0)

/** Seçili panel adrese yazılır (yenilemede ve geri dönüşte aynı panel açılır) */
watch(sira, (i) => {
  const panel = PANELLER[i]?.anahtar
  if (panel && route.query.panel !== panel)
    void router.replace({ query: { ...route.query, panel } })
})
</script>

<template>
  <div class="flex flex-col gap-3">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <AySecici v-model="donem" :ileri-sinir="null" />
      <!-- Hızlı geçiş: paneller kaydırılarak da değişir -->
      <div class="flex gap-1.5" role="tablist" aria-label="Gelir / Gider">
        <button
          v-for="(p, i) in PANELLER"
          :key="p.anahtar"
          type="button"
          role="tab"
          class="flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-semibold transition-colors"
          :class="
            sira === i
              ? 'bg-white text-surface-900 ring-2'
              : 'text-surface-500 ring-1 ring-surface-200'
          "
          :style="sira === i ? { '--tw-ring-color': `var(--p-${p.renk}-500)` } : undefined"
          :aria-selected="sira === i"
          :data-test="`panel-${p.anahtar}`"
          @click="sira = i"
        >
          <span class="h-2 w-2 rounded-full" :style="{ background: `var(--p-${p.renk}-500)` }" />
          {{ p.etiket }}
        </button>
      </div>
    </div>

    <KaydirmaliPaneller
      v-model:sira="sira"
      :ogeler="[...PANELLER]"
      :sutun="2"
      :anahtar="(p) => p.anahtar"
      :etiket="(p) => p.etiket"
    >
      <template #panel="{ oge: p }">
        <section class="flex flex-col gap-3" :data-test="`panel-icerik-${p.anahtar}`">
          <!-- İnce renkli çizgi: gelir yeşil, gider kırmızı -->
          <div
            class="h-[3px] w-full rounded-full"
            :style="{ background: `var(--p-${p.renk}-500)` }"
            :data-test="`panel-cizgi-${p.anahtar}`"
          />
          <GelirlerView v-if="p.anahtar === 'gelir'" :donem="donem" />
          <GiderlerView v-else :donem="donem" />
        </section>
      </template>
    </KaydirmaliPaneller>
  </div>
</template>
