<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'
import BosDurum from '@/components/BosDurum.vue'
import KisiEtiketi from '@/components/KisiEtiketi.vue'
import EkstreYukleDialog from '@/components/aile/EkstreYukleDialog.vue'
import KartFormu from '@/components/aile/KartFormu.vue'
import { aileApi, type Ekstre } from '@/api/aile'
import { useAileStore } from '@/stores/aile'
import { tarih, tl } from '@/utils/bicim'

/**
 * Bankalar ve kartları; incelemeyi bekleyen ekstreler en üstte. Kart sayfasında ekstreler, işlemler ve faturalar.
 */
const aile = useAileStore()
const ekstreler = ref<Ekstre[] | null>(null)
const kartFormuAcik = ref(false)
const yukleAcik = ref(false)

async function yukle(): Promise<void> {
  await aile.yukle()
  ekstreler.value = await aileApi.ekstreler()
}
onMounted(yukle)

const bekleyenler = computed(() => (ekstreler.value ?? []).filter((e) => e.durum === 'taslak'))
const bankalar = computed(() =>
  aile.bankalar
    .map((b) => ({ ...b, kartlar: aile.kartlar.filter((k) => k.banka_id === b.id) }))
    .filter((b) => b.kartlar.length > 0),
)

function sonEkstre(kartId: number): Ekstre | undefined {
  return (ekstreler.value ?? []).find((e) => e.kart_id === kartId && e.durum === 'onaylandi')
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="flex flex-wrap justify-end gap-2">
      <Button
        label="Ekstre yükle"
        icon="pi pi-upload"
        :disabled="!aile.kartlar.length"
        @click="yukleAcik = true"
      />
      <Button
        label="Kart ekle"
        icon="pi pi-plus"
        severity="secondary"
        outlined
        @click="kartFormuAcik = true"
      />
    </div>

    <section v-if="bekleyenler.length" class="kart border-amber-200 bg-amber-50/60 p-4">
      <h2 class="bolum-etiketi mb-2 !text-amber-800">İncelemeyi bekleyen ekstreler</h2>
      <ul class="flex flex-col gap-2">
        <li v-for="e in bekleyenler" :key="e.id">
          <RouterLink
            :to="{ name: 'aile-ekstre', params: { id: e.id } }"
            class="flex items-center justify-between gap-3 rounded-2xl bg-white p-3 ring-1 ring-amber-200"
          >
            <span class="min-w-0">
              <span class="block truncate font-semibold"
                >{{ aile.kartAdi(e.kart_id) }} · {{ e.donem_etiketi ?? 'Dönem okunamadı' }}</span
              >
              <span class="block text-xs text-surface-500"
                >{{ e.satir_sayisi }} satır · {{ e.dosya_adi }}</span
              >
            </span>
            <span class="text-sm font-semibold whitespace-nowrap text-primary-600"
              >İncele <i class="pi pi-arrow-right text-xs"
            /></span>
          </RouterLink>
        </li>
      </ul>
    </section>

    <div v-if="ekstreler === null" class="flex flex-col gap-3">
      <Skeleton v-for="n in 3" :key="n" height="5rem" border-radius="1.5rem" />
    </div>
    <BosDurum
      v-else-if="!aile.kartlar.length"
      baslik="Henüz kart yok"
      aciklama="Bankanızı ve kredi kartınızı adıyla ekleyin (numara istenmez). Sonra ekstre PDF'ini yükleyin."
      ikon="pi pi-credit-card"
    >
      <Button label="Kart ekle" icon="pi pi-plus" @click="kartFormuAcik = true" />
    </BosDurum>
    <template v-else>
      <section v-for="b in bankalar" :key="b.id" class="kart p-4 sm:p-5">
        <h2 class="bolum-etiketi mb-2">{{ b.ad }}</h2>
        <ul class="divide-y divide-surface-100">
          <li v-for="k in b.kartlar" :key="k.id">
            <RouterLink
              :to="{ name: 'aile-kart', params: { id: k.id } }"
              class="flex items-center gap-3 py-3"
              data-test="kart-satiri"
            >
              <span
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-surface-100 text-surface-600"
              >
                <i class="pi pi-credit-card" />
              </span>
              <span class="min-w-0 flex-1">
                <span class="flex items-center gap-2">
                  <span class="truncate font-semibold text-surface-900">{{ k.ad }}</span>
                  <Tag v-if="!k.aktif" value="Pasif" severity="secondary" />
                </span>
                <span class="block text-xs text-surface-500">
                  <KisiEtiketi :kisi="aile.uye(k.sahip_uye_id)" />
                  <template v-if="k.kesim_gunu"> · kesim {{ k.kesim_gunu }}</template>
                  <template v-if="k.son_odeme_gunu"> · son ödeme {{ k.son_odeme_gunu }}</template>
                </span>
              </span>
              <span v-if="sonEkstre(k.id)" class="text-right text-xs text-surface-500">
                <span class="block font-semibold text-surface-900 tabular-nums">{{
                  tl(sonEkstre(k.id)!.donem_borcu)
                }}</span>
                <template v-if="sonEkstre(k.id)!.son_odeme_tarihi"
                  >son ödeme {{ tarih(sonEkstre(k.id)!.son_odeme_tarihi) }}</template
                >
              </span>
            </RouterLink>
          </li>
        </ul>
      </section>
    </template>

    <KartFormu v-model:visible="kartFormuAcik" @kaydedildi="yukle" />
    <EkstreYukleDialog v-model:visible="yukleAcik" />
  </div>
</template>
