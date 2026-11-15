<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import KaydirmaliPaneller from '@/components/KaydirmaliPaneller.vue'
import AySecici from '@/components/aile/AySecici.vue'
import GelirlerView from '@/views/aile/GelirlerView.vue'
import GiderlerView from '@/views/aile/GiderlerView.vue'
import { buAy } from '@/utils/donem'

/**
 * Gelir / Gider tek sekmede: üstte ortak, tam genişlikte 150 px ay seçici, altında yana kaydırmalı iki panel. Ekranda
 * "Gelir" / "Gider" yazısı yok; panelin sağ kenarında ince dikey çizgi (gelir yeşil, gider kırmızı) hangisinde
 * olunduğunu gösterir. Telefonda tek panel ekranı kaplar, sağa-sola kaydırınca diğeri açılır; geniş ekranda ikisi yan yana. `?panel=gider` ile Gider açık başlar (eski adresler buraya yönlenir).
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
    <!-- Dönem seçici ekranı yatay kaplar, 150 px yüksekliğinde -->
    <AySecici v-model="donem" :ileri-sinir="null" genis />

    <KaydirmaliPaneller
      v-model:sira="sira"
      :ogeler="[...PANELLER]"
      :sutun="2"
      tam-genislik
      :noktalar="false"
      :anahtar="(p) => p.anahtar"
      :etiket="(p) => p.etiket"
    >
      <template #panel="{ oge: p }">
        <section
          class="relative flex flex-col gap-3"
          :data-test="`panel-icerik-${p.anahtar}`"
          :aria-label="p.etiket"
        >
          <!-- Ekranın sağ kenarında ince dikey çizgi: gelir yeşil, gider kırmızı (yazı yok) -->
          <div
            class="pointer-events-none absolute inset-y-0 -right-4 w-[3px] rounded-full sm:right-0"
            :style="{ background: `var(--p-${p.renk}-500)` }"
            :data-test="`panel-cizgi-${p.anahtar}`"
            aria-hidden="true"
          />
          <GelirlerView v-if="p.anahtar === 'gelir'" :donem="donem" />
          <GiderlerView v-else :donem="donem" />
        </section>
      </template>
    </KaydirmaliPaneller>
  </div>
</template>
