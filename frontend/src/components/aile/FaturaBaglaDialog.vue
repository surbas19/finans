<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Skeleton from 'primevue/skeleton'
import { useToast } from 'primevue/usetoast'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import { aileApi, type Fatura, type KartIslemi } from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { useAileStore } from '@/stores/aile'
import { tarih, tl } from '@/utils/bicim'
import { buAy, donemEkle } from '@/utils/donem'
import { sadeMetin } from '@/utils/topluIslem'

/**
 * Faturayı kartla ödendiği satıra bağlama: son 3 ayın onaylı harcamaları, başka faturaya bağlı olmayanlar;
 * sağlayıcının adı geçenler üstte. Bir satır tek faturaya bağlanır (sunucu da denetler).
 */
const props = defineProps<{ fatura: Fatura | null; bagliIslemler: number[] }>()
const gorunur = defineModel<boolean>('visible', { default: false })
const emit = defineEmits<{ baglandi: [] }>()

const aile = useAileStore()
const toast = useToast()
const islemler = ref<KartIslemi[] | null>(null)
const ara = ref('')
const baglaniyor = ref<number | null>(null)

watch(gorunur, async (acik) => {
  if (!acik) return
  ara.value = ''
  islemler.value = null
  const aylar = [buAy(), donemEkle(buAy(), -1), donemEkle(buAy(), -2)]
  const sonuc = await Promise.all(
    aylar.map((ay) => aileApi.kartIslemleri({ ay, donem_turu: 'islem', tur: 'harcama' })),
  )
  islemler.value = sonuc.flatMap((s) => s.islemler)
})

const saglayiciAdi = computed(() => sadeMetin(aile.saglayici(props.fatura?.saglayici_id)?.ad ?? ''))
const adaylar = computed(() => {
  const anahtar = sadeMetin(ara.value)

  return (islemler.value ?? [])
    .filter((i) => !props.bagliIslemler.includes(i.id))
    .filter((i) => !anahtar || sadeMetin(i.aciklama).includes(anahtar))
    .sort(
      (a, b) =>
        Number(sadeMetin(b.aciklama).includes(saglayiciAdi.value)) -
        Number(sadeMetin(a.aciklama).includes(saglayiciAdi.value)),
    )
    .slice(0, 60)
})

async function bagla(i: KartIslemi): Promise<void> {
  if (!props.fatura) return
  baglaniyor.value = i.id
  try {
    await aileApi.faturaBagla(props.fatura.id, i.id)
    toast.add({ severity: 'success', summary: 'Fatura kart satırına bağlandı', life: 3000 })
    gorunur.value = false
    emit('baglandi')
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Bağlanamadı', detail: hataMesaji(e), life: 5000 })
  } finally {
    baglaniyor.value = null
  }
}
</script>

<template>
  <UygulamaPenceresi v-model:visible="gorunur" baslik="Kart satırına bağla" :genislik="34">
    <div class="flex flex-col gap-3">
      <p class="text-sm text-surface-600">
        {{ aile.saglayici(fatura?.saglayici_id)?.ad }} ·
        {{ fatura?.hizmet_donemi_etiketi ?? 'dönem bilinmiyor' }} ·
        {{ tl(fatura?.tutar) }}
      </p>
      <InputText v-model="ara" placeholder="Açıklamada ara" aria-label="Açıklamada ara" />
      <Skeleton v-if="islemler === null" height="8rem" />
      <p v-else-if="!adaylar.length" class="py-4 text-center text-sm text-surface-500">
        Son 3 ayda bağlanmamış harcama bulunamadı.
      </p>
      <ul v-else class="max-h-[50dvh] divide-y divide-surface-100 overflow-y-auto">
        <li v-for="i in adaylar" :key="i.id" class="flex items-center gap-3 py-2.5">
          <span class="min-w-0 flex-1">
            <span class="block truncate text-sm font-semibold">{{ i.aciklama }}</span>
            <span class="block text-xs text-surface-500"
              >{{ tarih(i.islem_tarihi) }} · {{ aile.kartAdi(i.kart_id) }}</span
            >
          </span>
          <span class="text-sm font-semibold tabular-nums">{{ tl(i.tutar) }}</span>
          <Button
            label="Bağla"
            size="small"
            outlined
            :loading="baglaniyor === i.id"
            @click="bagla(i)"
          />
        </li>
      </ul>
    </div>
  </UygulamaPenceresi>
</template>
