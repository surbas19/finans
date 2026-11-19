<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import Select from 'primevue/select'
import Skeleton from 'primevue/skeleton'
import { useToast } from 'primevue/usetoast'
import EvNakdiHareketFormu from '@/components/aile/EvNakdiHareketFormu.vue'
import { aileApi, type EvNakdiHareketi, type EvNakdiOzeti } from '@/api/aile'
import { finansApi, type HesapOzeti } from '@/api/finans'
import { hataMesaji } from '@/api/http'
import { tarih, tl } from '@/utils/bicim'

/**
 * Bütçe Özet'te ev nakdi. "Hangi hesap" seçilirse (ör. "Ev") bakiye o portföy hesabının Nakit (TL)'sidir: ev
 * harcaması ve para ekle / çıkar o hesaba yazılır, Portföy'de de görünür (hareketler Portföy'den düzenlenir).
 * Seçilmemişse ayrı kasa: bakiye = eklenenler − çıkarılanlar − "Ev" ödeme yerli giderler; kasa hareketi burada silinir.
 */
const toast = useToast()
const ozet = ref<EvNakdiOzeti | null>(null)
const formAcik = ref(false)
const formTuru = ref<'ekleme' | 'cikarma'>('ekleme')

// Hesap listesi bakiyeyle birlikte gelir: seçici seçenekler hazırken çizilsin (sonradan gelen seçenekte seçili
// hesabın adı görünmüyordu)
onMounted(async () => {
  const [veri, liste] = await Promise.allSettled([
    Promise.resolve().then(() => aileApi.evNakdi()),
    Promise.resolve().then(() => finansApi.hesaplar()),
  ])
  hesaplar.value = liste.status === 'fulfilled' ? liste.value.hesaplar : []
  ozet.value = veri.status === 'fulfilled' ? veri.value : null
})

/** Ev nakdi olarak kullanılacak hesap ("Ayrı kasa" = null) */
const hesaplar = ref<HesapOzeti[]>([])
const hesapSecenekleri = computed(() => [
  { id: null as number | null, ad: 'Ayrı kasa' },
  ...hesaplar.value.map((h) => ({ id: h.id as number | null, ad: h.ad })),
])
const hesapKaydediliyor = ref(false)
async function hesapSec(id: number | null): Promise<void> {
  hesapKaydediliyor.value = true
  try {
    const yanit = await aileApi.evNakdiHesap(id)
    ozet.value = yanit
    toast.add({ severity: 'success', summary: yanit.message, life: 3500 })
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Değişmedi', detail: hataMesaji(e), life: 5000 })
  } finally {
    hesapKaydediliyor.value = false
  }
}

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
          <p v-if="ozet.hesap" class="text-xs text-surface-500" data-test="ev-hesap-adi">
            "{{ ozet.hesap.ad }}" hesabının nakdi · Portföy'de de görünür
          </p>
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
      <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
        <label for="ev-hesap" class="text-surface-600">Hangi hesap</label>
        <Select
          input-id="ev-hesap"
          :model-value="ozet.hesap?.id ?? null"
          :options="hesapSecenekleri"
          option-label="ad"
          option-value="id"
          size="small"
          class="min-w-40"
          :loading="hesapKaydediliyor"
          data-test="ev-hesap-sec"
          @update:model-value="hesapSec"
        />
      </div>
      <p class="mt-2 text-xs text-surface-500">
        Giren {{ tl(ozet.giren) }} · çıkan {{ tl(ozet.cikan) }} · harcanan {{ tl(ozet.harcama) }}.
        Nakit harcamada "Ev" seçilince tutar
        <template v-if="ozet.hesap">"{{ ozet.hesap.ad }}" hesabından</template
        ><template v-else>buradan</template> düşer; bakiye yetmiyorsa kaydedilmez.
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
            v-if="h.silinebilir ?? h.tip !== 'harcama'"
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
