<script setup lang="ts">
import { onMounted, ref } from 'vue'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import BosDurum from '@/components/BosDurum.vue'
import TemettuKarti from '@/components/TemettuKarti.vue'
import TemettuPlanFormu from '@/components/TemettuPlanFormu.vue'
import TemettuYattiDialog from '@/components/TemettuYattiDialog.vue'
import { finansApi, type TemettuListesi, type TemettuPlani } from '@/api/finans'
import { hataMesaji } from '@/api/http'
import { tl } from '@/utils/bicim'

/**
 * Portföy → Temettü: önceden planlanan temettüler (yaklaşan en üstte; ödeme gününe 3 gün kalan ve günü geçenler amber
 * rozetli), yatanlar altta. Her kartta hisse, lot, temettü oranı ve yatacak net para; "Yattı" gerçek temettüye çevirir.
 */
const confirm = useConfirm()
const toast = useToast()
const veri = ref<TemettuListesi | null>(null)
const formAcik = ref(false)
const duzenlenen = ref<TemettuPlani | null>(null)
const yattiAcik = ref(false)
const yattiPlan = ref<TemettuPlani | null>(null)

async function yukle(): Promise<void> {
  veri.value = await finansApi.temettuPlanlari()
}
onMounted(yukle)

function planla(): void {
  duzenlenen.value = null
  formAcik.value = true
}
function duzenle(p: TemettuPlani): void {
  duzenlenen.value = p
  formAcik.value = true
}
function yatti(p: TemettuPlani): void {
  yattiPlan.value = p
  yattiAcik.value = true
}
function sil(p: TemettuPlani): void {
  confirm.require({
    header: 'Planı sil',
    message: `${p.varlik.kod} temettü planı silinsin mi?`,
    acceptProps: { label: 'Sil', severity: 'danger' },
    rejectProps: { label: 'Vazgeç', severity: 'secondary', text: true },
    accept: async () => {
      try {
        await finansApi.temettuSil(p.id)
        toast.add({ severity: 'success', summary: 'Plan silindi', life: 3000 })
        await yukle()
      } catch (e) {
        toast.add({ severity: 'error', summary: 'Silinemedi', detail: hataMesaji(e), life: 5000 })
      }
    },
  })
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div v-if="veri && veri.bekleyenler.length">
        <p class="text-xs text-surface-500">Bekleyen temettü (net)</p>
        <p class="text-xl font-extrabold text-green-700 tabular-nums" data-test="temettu-toplam">
          {{ tl(veri.toplam_net) }}
        </p>
      </div>
      <Button
        label="Temettü planla"
        icon="pi pi-plus"
        class="ml-auto"
        data-test="temettu-planla"
        @click="planla"
      />
    </div>

    <Skeleton v-if="!veri" height="9rem" border-radius="1.5rem" />
    <template v-else>
      <section class="flex flex-col gap-3" data-test="temettu-bekleyenler">
        <h2 class="bolum-etiketi">Yaklaşan</h2>
        <BosDurum
          v-if="!veri.bekleyenler.length"
          kucuk
          baslik="Planlı temettü yok"
          aciklama="Hisse başı temettü tutarı ve ödeme tarihini girin; günü yaklaşınca Özet'te hatırlatılır."
          ikon="pi pi-calendar"
        />
        <TemettuKarti
          v-for="p in veri.bekleyenler"
          :key="p.id"
          :plan="p"
          @yatti="yatti(p)"
          @duzenle="duzenle(p)"
          @sil="sil(p)"
        />
      </section>
      <section v-if="veri.yatanlar.length" class="flex flex-col gap-3" data-test="temettu-yatanlar">
        <h2 class="bolum-etiketi">Yatanlar</h2>
        <TemettuKarti v-for="p in veri.yatanlar" :key="p.id" :plan="p" yatan />
      </section>
    </template>

    <TemettuPlanFormu v-model:visible="formAcik" :plan="duzenlenen" @kaydedildi="yukle" />
    <TemettuYattiDialog v-model:visible="yattiAcik" :plan="yattiPlan" @kaydedildi="yukle" />
  </div>
</template>
