<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import Accordion from 'primevue/accordion'
import AccordionContent from 'primevue/accordioncontent'
import AccordionHeader from 'primevue/accordionheader'
import AccordionPanel from 'primevue/accordionpanel'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import TasarrufFormu from '@/components/aile/TasarrufFormu.vue'
import TasarrufKalemi from '@/components/aile/TasarrufKalemi.vue'
import { aileApi, type TasarrufAyi, type TasarrufOzeti } from '@/api/aile'
import { useSonIstek } from '@/composables/useSonIstek'
import { useTasarrufEylemleri } from '@/composables/useTasarrufEylemleri'
import { tl } from '@/utils/bicim'

/**
 * Bütçe → Tasarruf: yılın aylarında gelir, gider ve tasarruf (gelire göre oranlar), dönem dönem tasarruf kalemleri ve
 * toplamları. Başkasına verilen gün alımları da ay kalemlerindedir ("Verildi"; hiçbir hesapta yoktur).
 */
const yil = ref(new Date().getFullYear())
const veri = ref<TasarrufOzeti | null>(null)
const formAcik = ref(false)
const sonIstek = useSonIstek()

async function yukle(): Promise<void> {
  const v = await sonIstek(() => aileApi.tasarrufOzeti(yil.value))
  if (v !== undefined) veri.value = v
}
onMounted(yukle)
watch(yil, () => {
  veri.value = null
  void yukle()
})

const eylem = useTasarrufEylemleri(() => yukle())

/** Kalemi olan aylar açık başlar */
const acikAylar = computed(() =>
  (veri.value?.aylar ?? []).filter((a) => a.kalemler.length).map((a) => a.donem),
)

const oranMetni = (o: number | null): string =>
  o === null ? '—' : `%${o.toLocaleString('tr-TR', { maximumFractionDigits: 1 })}`

/** Yığılmış çubuk genişlikleri (gelire göre %; taşan / eksi değerler 0–100'e sıkıştırılır) */
function cubuk(a: Pick<TasarrufAyi, 'gider_orani' | 'tasarruf_orani' | 'kalan_orani'>) {
  const k = (o: number | null): number => Math.max(0, Math.min(100, o ?? 0))

  return { gider: k(a.gider_orani), tasarruf: k(a.tasarruf_orani), kalan: k(a.kalan_orani) }
}
</script>

<template>
  <div class="flex flex-col gap-4" data-test="tasarruf-sayfasi">
    <div class="flex items-center justify-between gap-2">
      <div
        class="inline-flex items-center gap-1 rounded-full bg-white p-1 ring-1 ring-surface-200"
        data-test="yil-secici"
      >
        <Button
          icon="pi pi-chevron-left"
          text
          rounded
          size="small"
          severity="secondary"
          aria-label="Önceki yıl"
          @click="yil--"
        />
        <span class="min-w-[4rem] text-center text-sm font-semibold" data-test="yil">{{
          yil
        }}</span>
        <Button
          icon="pi pi-chevron-right"
          text
          rounded
          size="small"
          severity="secondary"
          aria-label="Sonraki yıl"
          @click="yil++"
        />
      </div>
      <Button
        label="Tasarruf yap"
        icon="pi pi-wallet"
        size="small"
        data-test="tasarruf-yap"
        @click="formAcik = true"
      />
    </div>

    <Skeleton v-if="!veri" height="10rem" border-radius="1.5rem" />
    <template v-else>
      <!-- Yıl özeti -->
      <section class="kart p-4 sm:p-5" data-test="yil-ozeti">
        <h2 class="bolum-etiketi mb-2">{{ veri.yil }} özeti</h2>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <div class="rounded-2xl bg-surface-50 p-3">
            <p class="text-xs text-surface-500">Gelir</p>
            <p class="font-semibold text-emerald-700 tabular-nums" data-test="yil-gelir">
              {{ tl(veri.toplam.gelir) }}
            </p>
          </div>
          <div class="rounded-2xl bg-surface-50 p-3">
            <p class="text-xs text-surface-500">Gider</p>
            <p class="font-semibold text-red-700 tabular-nums">{{ tl(veri.toplam.gider) }}</p>
            <p class="text-xs text-surface-500">{{ oranMetni(veri.toplam.gider_orani) }}</p>
          </div>
          <div class="rounded-2xl bg-surface-50 p-3">
            <p class="text-xs text-surface-500">Tasarruf</p>
            <p class="font-semibold text-sky-700 tabular-nums" data-test="yil-tasarruf">
              {{ tl(veri.toplam.tasarruf) }}
            </p>
            <p class="text-xs text-surface-500" data-test="yil-tasarruf-orani">
              {{ oranMetni(veri.toplam.tasarruf_orani) }}
            </p>
          </div>
          <div class="rounded-2xl bg-surface-50 p-3">
            <p class="text-xs text-surface-500">Kalan</p>
            <p class="font-semibold tabular-nums">{{ tl(veri.toplam.kalan) }}</p>
            <p class="text-xs text-surface-500">{{ oranMetni(veri.toplam.kalan_orani) }}</p>
          </div>
        </div>
      </section>

      <!-- Aylık oranlar -->
      <section class="kart p-4 sm:p-5" data-test="aylik-oranlar">
        <h2 class="bolum-etiketi mb-2">Aylık gelir, gider ve tasarruf</h2>
        <p class="mb-2 flex flex-wrap gap-x-3 text-xs text-surface-500">
          <span><span class="mr-1 inline-block h-2 w-2 rounded-full bg-red-500" />Gider</span>
          <span><span class="mr-1 inline-block h-2 w-2 rounded-full bg-sky-500" />Tasarruf</span>
          <span><span class="mr-1 inline-block h-2 w-2 rounded-full bg-emerald-500" />Kalan</span>
          <span>(oranlar gelire göre)</span>
        </p>
        <ul class="flex flex-col gap-3">
          <li v-for="a in veri.aylar" :key="a.donem" data-test="ay-orani">
            <div class="flex items-baseline justify-between gap-2 text-sm">
              <span class="font-semibold">{{ a.etiket }}</span>
              <span class="text-xs text-surface-500 tabular-nums"
                >Gelir {{ tl(a.gelir) }} · Gider {{ tl(a.gider) }} · Tasarruf
                {{ tl(a.tasarruf) }}</span
              >
            </div>
            <div
              class="mt-1 flex h-2.5 w-full overflow-hidden rounded-full bg-surface-100"
              role="img"
              :aria-label="`${a.etiket}: gider ${oranMetni(a.gider_orani)}, tasarruf ${oranMetni(a.tasarruf_orani)}, kalan ${oranMetni(a.kalan_orani)}`"
            >
              <span class="h-full bg-red-500" :style="{ width: `${cubuk(a).gider}%` }" />
              <span class="h-full bg-sky-500" :style="{ width: `${cubuk(a).tasarruf}%` }" />
              <span class="h-full bg-emerald-500" :style="{ width: `${cubuk(a).kalan}%` }" />
            </div>
            <p class="mt-0.5 text-[11px] text-surface-500 tabular-nums" data-test="oran-metni">
              Gider {{ oranMetni(a.gider_orani) }} · Tasarruf {{ oranMetni(a.tasarruf_orani) }} ·
              Kalan {{ oranMetni(a.kalan_orani) }}
            </p>
          </li>
        </ul>
      </section>

      <!-- Dönem dönem tasarruf kalemleri -->
      <section class="kart p-2" data-test="donem-akordiyonu">
        <Accordion
          :key="veri.yil"
          :value="acikAylar"
          multiple
          class="[&_.p-accordioncontent-wrapper]:min-w-0 [&_.p-accordioncontent-wrapper]:overflow-hidden"
        >
          <AccordionPanel v-for="a in veri.aylar" :key="a.donem" :value="a.donem">
            <AccordionHeader data-test="donem-baslik">
              <span class="flex w-full items-baseline justify-between gap-2 pr-2">
                <span>{{ a.etiket }}</span>
                <span class="text-sm font-normal text-surface-500 tabular-nums"
                  >{{ tl(a.tasarruf) }} · {{ oranMetni(a.tasarruf_orani) }}</span
                >
              </span>
            </AccordionHeader>
            <AccordionContent>
              <p v-if="!a.kalemler.length" class="py-1 text-sm text-surface-500">
                Bu ay tasarruf yok.
              </p>
              <div v-else class="divide-y divide-surface-100">
                <TasarrufKalemi
                  v-for="k in a.kalemler"
                  :key="k.id"
                  :kalem="k"
                  @sil="eylem.sil"
                  @dahil="eylem.dahil"
                />
                <p class="flex justify-between pt-2 text-sm font-semibold">
                  <span>{{ a.etiket }} toplamı</span>
                  <span class="tabular-nums" data-test="ay-toplami">{{ tl(a.tasarruf) }}</span>
                </p>
              </div>
            </AccordionContent>
          </AccordionPanel>
        </Accordion>
      </section>
    </template>

    <TasarrufFormu v-model:visible="formAcik" @kaydedildi="yukle" />
  </div>
</template>
