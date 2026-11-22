<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import { useToast } from 'primevue/usetoast'
import BosDurum from '@/components/BosDurum.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import { finansApi, type Ozet, type Pozisyon } from '@/api/finans'
import { bugun, miktar, tarih, tl } from '@/utils/bicim'

/**
 * Portföy özeti: hızlı işlemler menüsünden açılır. Her hesap belirgin bir bantla, altında varlık / adet / güncel değer.
 * Başlıktaki kopyala simgesi aynı içeriği düz metin olarak panoya alar; pencerenin kapat simgesi başlıktadır.
 */
const gorunur = defineModel<boolean>('visible', { default: false })
const toast = useToast()
const ozet = ref<Ozet | null>(null)
const hata = ref(false)

watch(gorunur, async (acik) => {
  if (!acik) return
  ozet.value = null
  hata.value = false
  try {
    ozet.value = await finansApi.ozet(null)
  } catch {
    hata.value = true
  }
})

interface HesapBolumu {
  id: number
  ad: string
  renk: string | null
  deger: number
  satirlar: Pozisyon[]
}

const hesaplar = computed<HesapBolumu[]>(() =>
  (ozet.value?.hesaplar ?? [])
    .map((h) => ({
      id: h.id,
      ad: h.ad,
      renk: h.renk,
      deger: h.deger,
      satirlar: (ozet.value?.pozisyonlar ?? [])
        .filter((p) => p.hesap_id === h.id)
        .sort((a, b) => (b.deger ?? 0) - (a.deger ?? 0)),
    }))
    .filter((h) => h.satirlar.length > 0),
)
const genel = computed(() => hesaplar.value.reduce((t, h) => t + h.deger, 0))

function adetMetni(p: Pozisyon): string {
  return `${miktar(p.miktar)} ${p.varlik.birim}`
}

/** Panoya alınan düz metin */
const metin = computed(() => {
  const satirlar = [`Portföy özeti · ${tarih(bugun())}`]
  for (const h of hesaplar.value) {
    satirlar.push('', `${h.ad.toLocaleUpperCase('tr-TR')} — ${tl(h.deger)}`)
    for (const p of h.satirlar) {
      satirlar.push(`${p.varlik.kod} · ${adetMetni(p)} · ${tl(p.deger)}`)
    }
  }
  satirlar.push('', `GENEL TOPLAM — ${tl(genel.value)}`)

  return satirlar.join('\n')
})

async function kopyala(): Promise<void> {
  try {
    await navigator.clipboard.writeText(metin.value)
    toast.add({ severity: 'success', summary: 'Kopyalandı', life: 2000 })
  } catch {
    toast.add({
      severity: 'warn',
      summary: 'Kopyalanamadı',
      detail: 'Tarayıcı panoya yazmaya izin vermedi.',
      life: 4000,
    })
  }
}
</script>

<template>
  <UygulamaPenceresi v-model:visible="gorunur" :genislik="28">
    <template #header>
      <div class="flex min-w-0 flex-1 items-center gap-2">
        <span class="min-w-0 flex-1">
          <span class="block text-base font-bold text-surface-900">Portföy özeti</span>
          <span class="block text-xs font-normal text-surface-500">{{ tarih(bugun()) }}</span>
        </span>
        <Button
          icon="pi pi-copy"
          text
          rounded
          aria-label="Metni kopyala"
          title="Metni kopyala"
          :disabled="!hesaplar.length"
          data-test="ozet-kopyala"
          @click="kopyala"
        />
      </div>
    </template>

    <p v-if="hata" class="text-sm text-red-600">Özet yüklenemedi. Tekrar deneyin.</p>
    <div v-else-if="!ozet" class="flex flex-col gap-3">
      <Skeleton height="2.5rem" border-radius="0.75rem" />
      <Skeleton height="6rem" border-radius="0.75rem" />
    </div>
    <BosDurum
      v-else-if="!hesaplar.length"
      kucuk
      baslik="Henüz varlık yok"
      aciklama="Hesaplarınıza işlem eklendiğinde burada görünür."
    />
    <div v-else class="flex flex-col gap-4" data-test="ozet-hesaplar">
      <section v-for="h in hesaplar" :key="h.id" class="flex flex-col" data-test="ozet-hesap">
        <div
          class="flex items-baseline justify-between gap-3 rounded-xl bg-primary-50 px-3 py-2"
          :style="
            h.renk
              ? { backgroundColor: `color-mix(in srgb, ${h.renk} 14%, transparent)` }
              : undefined
          "
        >
          <h3 class="min-w-0 truncate text-base font-bold text-surface-900">{{ h.ad }}</h3>
          <span
            class="shrink-0 font-bold text-surface-900 tabular-nums"
            data-test="ozet-hesap-toplam"
            >{{ tl(h.deger) }}</span
          >
        </div>
        <div
          v-for="p in h.satirlar"
          :key="p.varlik.id"
          class="grid grid-cols-[minmax(0,1fr)_auto_auto] items-baseline gap-3 border-b border-surface-100 py-1.5 text-sm tabular-nums"
          data-test="ozet-satir"
        >
          <span class="min-w-0">
            <span class="block font-semibold text-surface-900">{{ p.varlik.kod }}</span>
            <span class="block truncate text-xs text-surface-500">{{ p.varlik.ad }}</span>
          </span>
          <span class="text-right text-surface-600">{{ adetMetni(p) }}</span>
          <span class="min-w-24 text-right font-semibold text-surface-900">{{ tl(p.deger) }}</span>
        </div>
      </section>
      <div
        class="flex items-baseline justify-between gap-3 border-t-2 border-surface-900 pt-2 font-bold text-surface-900"
      >
        <span>Genel toplam</span>
        <span class="tabular-nums" data-test="ozet-genel">{{ tl(genel) }}</span>
      </div>
    </div>
  </UygulamaPenceresi>
</template>
