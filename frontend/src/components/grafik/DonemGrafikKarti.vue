<script setup lang="ts">
import Accordion from 'primevue/accordion'
import AccordionContent from 'primevue/accordioncontent'
import AccordionHeader from 'primevue/accordionheader'
import AccordionPanel from 'primevue/accordionpanel'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'

/**
 * Grafik bölümü: kapanabilir (varsayılan açık) kart; içinde yıl seçici (önceki / sonraki yıl), yükleniyor iskeleti,
 * hata iletisi ve grafik (varsayılan yuva). Telefonda taşmaz (içerik min-w-0, kırpılır).
 */
defineProps<{ baslik: string; yukleniyor?: boolean; hata?: string | null }>()
const yil = defineModel<number>('yil', { required: true })
</script>

<template>
  <section class="kart p-2" data-test="grafik-karti">
    <Accordion
      :value="['grafik']"
      multiple
      class="[&_.p-accordioncontent-wrapper]:min-w-0 [&_.p-accordioncontent-wrapper]:overflow-hidden"
    >
      <AccordionPanel value="grafik">
        <AccordionHeader data-test="grafik-baslik">{{ baslik }}</AccordionHeader>
        <AccordionContent>
          <div class="mb-2 flex items-center justify-between gap-2">
            <Button
              icon="pi pi-chevron-left"
              severity="secondary"
              text
              rounded
              size="small"
              aria-label="Önceki yıl"
              data-test="yil-onceki"
              @click="yil--"
            />
            <span class="text-sm font-bold tabular-nums" data-test="grafik-yil">{{ yil }}</span>
            <Button
              icon="pi pi-chevron-right"
              severity="secondary"
              text
              rounded
              size="small"
              aria-label="Sonraki yıl"
              data-test="yil-sonraki"
              @click="yil++"
            />
          </div>
          <Skeleton v-if="yukleniyor" height="15rem" border-radius="1.25rem" />
          <Message v-else-if="hata" severity="error" :closable="false">{{ hata }}</Message>
          <slot v-else />
        </AccordionContent>
      </AccordionPanel>
    </Accordion>
  </section>
</template>
