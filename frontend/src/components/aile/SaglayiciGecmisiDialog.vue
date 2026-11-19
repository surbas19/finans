<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import FaturaIkonu from '@/components/aile/FaturaIkonu.vue'
import KartEtiketi from '@/components/aile/KartEtiketi.vue'
import { aileApi, type Fatura, type SaglayiciGecmisi } from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { useDarEkran } from '@/composables/useDarEkran'
import { useAileStore } from '@/stores/aile'
import { tl } from '@/utils/bicim'
import { donemEtiketi } from '@/utils/donem'
import { durumRozeti, gunAy, turRengi } from '@/utils/fatura'

/**
 * Sağlayıcı geçmişi (Faturalar → fatura detayı → "Geçmiş"; telefonda tam ekran): fatura başına ortalama, en yüksek,
 * son 12 ay toplamı; son 12 ayın çubukları (faturası olmayan ay boş kalır, sıfır çizilmez); altında bütün faturalar
 * yeniden eskiye. Faturaya dokununca `sec` (detay açılır). Hesap sunucuda (`FaturaOzeti::saglayici`).
 */
const props = defineProps<{ saglayiciId: number | null }>()
const gorunur = defineModel<boolean>('visible', { default: false })
const emit = defineEmits<{ sec: [f: Fatura] }>()
const aile = useAileStore()
const dar = useDarEkran()

const veri = ref<SaglayiciGecmisi | null>(null)
const hata = ref<string | null>(null)
let istekNo = 0

watch(
  () => [gorunur.value, props.saglayiciId] as const,
  async ([acik, id]) => {
    if (!acik || !id) return
    const no = ++istekNo
    veri.value = null
    hata.value = null
    try {
      const sonuc = await aileApi.saglayiciGecmisi(id)
      if (no === istekNo) veri.value = sonuc
    } catch (e) {
      if (no === istekNo) hata.value = hataMesaji(e)
    }
  },
  { immediate: true },
)

const renk = computed(() => turRengi(veri.value?.saglayici.fatura_turu_id ?? 0))
const enCok = computed(() => Math.max(...(veri.value?.seri.map((s) => s.tutar ?? 0) ?? [0]), 1))
const kisaAy = (d: string): string => donemEtiketi(d).slice(0, 3)

function sec(f: Fatura): void {
  gorunur.value = false
  emit('sec', f)
}
</script>

<template>
  <Dialog
    v-model:visible="gorunur"
    modal
    :draggable="false"
    :dismissable-mask="!dar"
    header="Sağlayıcı geçmişi"
    :style="
      dar
        ? { width: '100vw', height: '100dvh', maxHeight: '100dvh', margin: 0, borderRadius: 0 }
        : { width: 'min(34rem, calc(100vw - 2rem))', height: 'min(46rem, calc(100dvh - 4rem))' }
    "
    :content-style="{ display: 'flex', flexDirection: 'column', padding: 0, minHeight: 0 }"
    data-test="saglayici-gecmisi"
  >
    <template #header>
      <div v-if="veri" class="flex min-w-0 items-center gap-3">
        <FaturaIkonu :tur-id="veri.saglayici.fatura_turu_id" />
        <span class="min-w-0">
          <span class="block truncate font-bold text-surface-900">{{ veri.saglayici.ad }}</span>
          <span class="block truncate text-xs text-surface-500"
            >{{ aile.tur(veri.saglayici.fatura_turu_id)?.ad ?? 'Fatura' }} ·
            {{ veri.adet }} fatura</span
          >
        </span>
      </div>
      <span v-else class="font-bold">Sağlayıcı geçmişi</span>
    </template>

    <div class="flex flex-col gap-3 px-4 pb-3">
      <Message v-if="hata" severity="error" :closable="false">{{ hata }}</Message>
      <template v-else-if="!veri">
        <Skeleton height="3.5rem" border-radius="1rem" />
        <Skeleton height="5rem" border-radius="1rem" />
      </template>
      <template v-else>
        <div class="grid grid-cols-3 gap-2" data-test="saglayici-ozet">
          <div class="rounded-2xl bg-surface-50 p-2.5">
            <span class="block text-xs text-surface-500">Ortalama</span>
            <span class="block text-sm font-bold tabular-nums">{{ tl(veri.ortalama) }}</span>
          </div>
          <div class="rounded-2xl bg-surface-50 p-2.5">
            <span class="block text-xs text-surface-500">En yüksek</span>
            <span class="block text-sm font-bold tabular-nums">{{
              tl(veri.en_yuksek?.tutar)
            }}</span>
            <span v-if="veri.en_yuksek?.etiket" class="block truncate text-xs text-surface-500">{{
              veri.en_yuksek.etiket
            }}</span>
          </div>
          <div class="rounded-2xl bg-surface-50 p-2.5">
            <span class="block text-xs text-surface-500">Son 12 ay</span>
            <span class="block text-sm font-bold tabular-nums">{{ tl(veri.son_12_ay) }}</span>
          </div>
        </div>
        <div
          class="flex h-24 items-end gap-1"
          role="img"
          :aria-label="`Son 12 ayın faturaları, ${veri.saglayici.ad}`"
          data-test="saglayici-cubuklar"
        >
          <div
            v-for="(s, i) in veri.seri"
            :key="s.donem"
            class="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1"
          >
            <span
              v-if="s.tutar !== null"
              class="block w-full rounded-t-md"
              :style="{
                height: `${Math.max(4, (s.tutar / enCok) * 70)}px`,
                background:
                  i === veri.seri.length - 1 ? `var(--p-${renk}-500)` : `var(--p-${renk}-200)`,
              }"
              :title="`${donemEtiketi(s.donem)}: ${tl(s.tutar)}`"
            />
            <span
              v-else
              class="block h-1 w-full rounded-full bg-surface-100"
              :title="`${donemEtiketi(s.donem)}: fatura yok`"
            />
            <span class="text-xs text-surface-400">{{ kisaAy(s.donem).charAt(0) }}</span>
          </div>
        </div>
      </template>
    </div>

    <div v-if="veri" class="min-h-0 flex-1 overflow-y-auto pb-[env(safe-area-inset-bottom)]">
      <p v-if="veri.faturalar.length === 0" class="p-6 text-center text-sm text-surface-500">
        Fatura yok.
      </p>
      <ul class="divide-y divide-surface-100 border-t border-surface-100">
        <li v-for="f in veri.faturalar" :key="f.id">
          <button
            type="button"
            class="flex w-full items-center justify-between gap-3 px-4 py-2.5 text-left hover:bg-surface-50"
            data-test="saglayici-fatura"
            @click="sec(f)"
          >
            <span class="min-w-0">
              <span class="block text-sm font-semibold text-surface-900">{{
                f.hizmet_donemi_etiketi ?? 'Dönemi bilinmiyor'
              }}</span>
              <span class="flex flex-wrap items-center gap-x-1.5 text-xs text-surface-500">
                <template v-if="f.odeme_tarihi">{{ gunAy(f.odeme_tarihi) }}</template>
                <template v-else>son ödeme {{ gunAy(f.son_odeme_tarihi) }}</template>
                <span class="text-surface-300">·</span>
                <KartEtiketi v-if="f.kart_islemi" :kart-id="f.kart_islemi.kart_id" />
                <span v-else>{{ f.durum === 'odendi' ? 'kartsız' : 'ödenmedi' }}</span>
              </span>
            </span>
            <span class="flex shrink-0 flex-col items-end gap-0.5">
              <span class="text-sm font-bold tabular-nums">{{ tl(f.tutar) }}</span>
              <span
                class="rounded-full px-2 py-0.5 text-xs font-semibold"
                :class="durumRozeti(f).renk"
                >{{ durumRozeti(f).metin }}</span
              >
            </span>
          </button>
        </li>
      </ul>
    </div>
  </Dialog>
</template>
