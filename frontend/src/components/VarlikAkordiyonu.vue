<script setup lang="ts">
import { computed, ref } from 'vue'
import Accordion from 'primevue/accordion'
import AccordionContent from 'primevue/accordioncontent'
import AccordionHeader from 'primevue/accordionheader'
import AccordionPanel from 'primevue/accordionpanel'
import DegisimEtiketi from '@/components/DegisimEtiketi.vue'
import PozisyonListesi from '@/components/PozisyonListesi.vue'
import { VARLIK_TURLERI, type Pozisyon, type VarlikTuru } from '@/api/finans'
import { tl } from '@/utils/bicim'

/**
 * Varlıklar türe göre akordiyon (Altın, Hisse, Döviz, Fon, Kripto, Nakit, BES; yalnız olanlar, değeri büyükten küçüğe).
 * Başlıkta ikon, tür, varlık sayısı ve pay; sağda değer ve K/Z; altında türün dağılım renginde pay çubuğu. Başta
 * hepsi kapalı; açılanlar hesap değişince de açık kalır. İçerik PozisyonListesi (aktar / dahil / şeritler aynen).
 * Toplamlar yalnız sunucunun satır değerlerinden toplanır: genel toplama dahil olmayan satır ve fiyatı olmayan değer
 * katılmaz; grupta maliyeti bilinmeyen satır varsa grubun K/Z'si "—" (sıfır sayılmaz).
 */
const props = defineProps<{
  pozisyonlar: Pozisyon[]
  renkler?: Map<number, string>
  degistirilebilir?: boolean
  hesapAdi?: (p: Pozisyon) => string | undefined
}>()
const emit = defineEmits<{ dahilDegistir: [p: Pozisyon, dahil: boolean]; aktar: [p: Pozisyon] }>()

const acik = ref<string[]>([])

const genelToplam = computed(() =>
  props.pozisyonlar.filter((p) => p.dahil !== false).reduce((t, p) => t + (p.deger ?? 0), 0),
)

const gruplar = computed(() =>
  (Object.keys(VARLIK_TURLERI) as VarlikTuru[])
    .map((tur) => {
      const liste = props.pozisyonlar.filter((p) => p.varlik.tur === tur)
      const dahil = liste.filter((p) => p.dahil !== false)
      const deger = dahil.reduce((t, p) => t + (p.deger ?? 0), 0)
      const kzBilinmiyor = dahil.some((p) => p.kz === null && (p.deger ?? 0) > 0)

      return {
        tur,
        bilgi: VARLIK_TURLERI[tur],
        liste,
        deger,
        kz: tur === 'nakit' || kzBilinmiyor ? null : dahil.reduce((t, p) => t + (p.kz ?? 0), 0),
        pay: genelToplam.value > 0 ? (deger / genelToplam.value) * 100 : 0,
      }
    })
    .filter((g) => g.liste.length > 0)
    .sort((a, b) => b.deger - a.deger),
)

const yuzde = (n: number): string => `%${n.toLocaleString('tr-TR', { maximumFractionDigits: 0 })}`
</script>

<template>
  <Accordion
    v-model:value="acik"
    multiple
    class="varlik-akordiyonu [&_.p-accordioncontent-content]:!px-0 [&_.p-accordioncontent-wrapper]:min-w-0 [&_.p-accordioncontent-wrapper]:overflow-hidden"
    data-test="varlik-akordiyonu"
  >
    <AccordionPanel
      v-for="g in gruplar"
      :key="g.tur"
      :value="g.tur"
      class="border-surface-100"
      data-test="varlik-grubu"
    >
      <AccordionHeader class="!px-1 !py-3 sm:!px-2" :data-test="`varlik-grubu-${g.tur}`">
        <span class="flex min-w-0 flex-1 items-center gap-3 pr-2">
          <span
            class="flex size-9 shrink-0 items-center justify-center rounded-xl text-white"
            :style="{ background: `var(${g.bilgi.renk})` }"
            aria-hidden="true"
          >
            <i :class="g.bilgi.ikon" class="text-sm" />
          </span>
          <span class="min-w-0 flex-1 text-left">
            <span class="block truncate text-sm font-bold text-surface-900">{{
              g.bilgi.etiket
            }}</span>
            <span class="block text-xs font-normal text-surface-500"
              >{{ g.liste.length }} varlık · {{ yuzde(g.pay) }}</span
            >
            <span class="mt-1 block h-1 overflow-hidden rounded-full bg-surface-100">
              <span
                class="block h-full rounded-full"
                :style="{ width: `${Math.min(100, g.pay)}%`, background: `var(${g.bilgi.renk})` }"
                data-test="pay-cubugu"
              />
            </span>
          </span>
          <span class="shrink-0 text-right">
            <span
              class="block text-sm font-bold text-surface-900 tabular-nums"
              data-test="grup-deger"
              >{{ tl(g.deger) }}</span
            >
            <DegisimEtiketi v-if="g.kz !== null" :tutar="g.kz" kucuk class="justify-end" />
            <span v-else-if="g.tur !== 'nakit'" class="block text-xs text-surface-400">K/Z —</span>
          </span>
        </span>
      </AccordionHeader>
      <AccordionContent>
        <PozisyonListesi
          :pozisyonlar="g.liste"
          :renkler="renkler"
          :hesap-adi="hesapAdi"
          :degistirilebilir="degistirilebilir"
          @dahil-degistir="(p, d) => emit('dahilDegistir', p, d)"
          @aktar="(p) => emit('aktar', p)"
        />
      </AccordionContent>
    </AccordionPanel>
  </Accordion>
</template>
