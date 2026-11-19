<script setup lang="ts">
import { onMounted, ref } from 'vue'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import { useToast } from 'primevue/usetoast'
import EvNakdiHareketFormu from '@/components/aile/EvNakdiHareketFormu.vue'
import { aileApi, type EvNakdiHareketi, type EvNakdiOzeti } from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { tarih, tl } from '@/utils/bicim'

/**
 * Bütçe Özet'te ev nakdi kasası: bakiye = eklenenler − çıkarılanlar − "Ev" ödeme yerli giderler. Elle para ekleme /
 * çıkarma; harcamalar giderden gelir (burada silinmez).
 */
const toast = useToast()
const ozet = ref<EvNakdiOzeti | null>(null)
const formAcik = ref(false)
const formTuru = ref<'ekleme' | 'cikarma'>('ekleme')

async function yukle(): Promise<void> {
  try {
    ozet.value = await aileApi.evNakdi()
  } catch {
    ozet.value = null
  }
}
onMounted(yukle)

function ac(tur: 'ekleme' | 'cikarma'): void {
  formTuru.value = tur
  formAcik.value = true
}

async function sil(h: EvNakdiHareketi): Promise<void> {
  try {
    ozet.value = await aileApi.evNakdiHareketSil(h.id)
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Silinemedi', detail: hataMesaji(e), life: 5000 })
  }
}

const ETIKET = { ekleme: 'Para eklendi', cikarma: 'Para çıkarıldı', harcama: 'Harcama' } as const
</script>

<template>
  <section class="kart p-4 sm:p-5" data-test="ev-nakdi">
    <Skeleton v-if="ozet === null" height="5rem" border-radius="1rem" />
    <template v-else>
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 class="bolum-etiketi">Ev nakdi</h2>
          <p
            class="text-2xl font-bold tabular-nums"
            :class="ozet.bakiye < 0 ? 'text-red-600' : 'text-surface-900'"
            data-test="ev-bakiye"
          >
            {{ tl(ozet.bakiye) }}
          </p>
        </div>
        <div class="flex gap-2">
          <Button
            label="Ekle"
            icon="pi pi-plus"
            size="small"
            data-test="ev-ekle"
            @click="ac('ekleme')"
          />
          <Button
            label="Çıkar"
            icon="pi pi-minus"
            size="small"
            severity="secondary"
            outlined
            data-test="ev-cikar"
            @click="ac('cikarma')"
          />
        </div>
      </div>
      <p class="mt-1 text-xs text-surface-500">
        Giren {{ tl(ozet.giren) }} · çıkan {{ tl(ozet.cikan) }} · harcanan {{ tl(ozet.harcama) }}.
        Nakit harcamada "Ev" seçilince tutar buradan düşer; bakiye yetmiyorsa kaydedilmez.
      </p>
      <ul v-if="ozet.hareketler.length" class="mt-2 divide-y divide-surface-100">
        <li
          v-for="h in ozet.hareketler.slice(0, 5)"
          :key="`${h.tip}-${h.id}`"
          class="flex items-center gap-2 py-2 text-sm"
        >
          <span class="min-w-0 flex-1">
            <span class="block truncate font-semibold text-surface-900">{{ ETIKET[h.tip] }}</span>
            <span class="block truncate text-xs text-surface-500"
              >{{ tarih(h.tarih) }}<template v-if="h.aciklama"> · {{ h.aciklama }}</template></span
            >
          </span>
          <span
            class="font-semibold whitespace-nowrap tabular-nums"
            :class="h.tip === 'ekleme' ? 'text-green-700' : 'text-surface-900'"
            >{{ h.tip === 'ekleme' ? '+' : '−' }}{{ tl(h.tutar) }}</span
          >
          <Button
            v-if="h.tip !== 'harcama'"
            icon="pi pi-trash"
            severity="danger"
            text
            rounded
            size="small"
            aria-label="Hareketi sil"
            data-test="ev-hareket-sil"
            @click="sil(h)"
          />
        </li>
      </ul>
      <RouterLink
        :to="{ name: 'aile-gelir-gider', query: { panel: 'gider' } }"
        class="mt-2 inline-block text-sm font-semibold text-primary-600"
        data-test="ev-tum-nakit"
        >Tüm nakit harcamalar</RouterLink
      >
    </template>
    <EvNakdiHareketFormu v-model:visible="formAcik" :tur="formTuru" @kaydedildi="ozet = $event" />
  </section>
</template>
