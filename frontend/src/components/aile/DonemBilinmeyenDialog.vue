<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import { useToast } from 'primevue/usetoast'
import FaturaIkonu from '@/components/aile/FaturaIkonu.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import { aileApi, type Fatura } from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { tl } from '@/utils/bicim'
import { donemEtiketi } from '@/utils/donem'
import { faturaGirdisi, gunAy, oneriDonem } from '@/utils/fatura'

/**
 * Dönemi (hizmet ayı) bilinmeyen faturalar: Faturalar sayfasındaki uyarı şeridinden açılır. Her satırda önerilen ay
 * (ödeme / son ödeme tarihinin ayı; `oneriDonem`) tek dokunuşla uygulanır, "Önerileri uygula" hepsini sırayla yazar
 * (aynı sağlayıcının o ayda faturası varsa sunucu reddeder, o satır kalır). Satıra dokununca detay açılır (`sec`);
 * başka ay oradan "Düzenle" ile seçilir.
 */
const gorunur = defineModel<boolean>('visible', { default: false })
const emit = defineEmits<{ degisti: []; sec: [f: Fatura] }>()
const toast = useToast()

const faturalar = ref<Fatura[] | null>(null)
const calisiyor = ref(false)

async function yukle(): Promise<void> {
  try {
    faturalar.value = (await aileApi.faturalar({ bilinmeyen: true })).faturalar
  } catch (e) {
    faturalar.value = []
    toast.add({ severity: 'error', summary: 'Yüklenemedi', detail: hataMesaji(e), life: 5000 })
  }
}

watch(gorunur, (acik) => {
  if (!acik) return
  faturalar.value = null
  void yukle()
})

const onerililer = computed(() => (faturalar.value ?? []).filter((f) => oneriDonem(f) !== null))

/** Önerilen ayları sırayla yazar; yazılamayanı (ör. o ayda aynı sağlayıcının faturası var) bildirir */
async function uygula(liste: Fatura[]): Promise<void> {
  calisiyor.value = true
  let basarili = 0
  const hatalar: string[] = []
  for (const f of liste) {
    try {
      await aileApi.faturaGuncelle(f.id, faturaGirdisi(f, { hizmet_donemi: oneriDonem(f) }))
      basarili++
    } catch (e) {
      hatalar.push(hataMesaji(e))
    }
  }
  calisiyor.value = false
  if (basarili) {
    toast.add({ severity: 'success', summary: `${basarili} faturanın ayı yazıldı`, life: 3500 })
    emit('degisti')
  }
  if (hatalar.length) {
    toast.add({ severity: 'warn', summary: 'Bazıları yazılamadı', detail: hatalar[0], life: 6000 })
  }
  await yukle()
  if (faturalar.value?.length === 0) gorunur.value = false
}

function sec(f: Fatura): void {
  gorunur.value = false
  emit('sec', f)
}
</script>

<template>
  <UygulamaPenceresi
    v-model:visible="gorunur"
    :baslik="`Dönemi bilinmeyen faturalar${faturalar ? ` (${faturalar.length})` : ''}`"
    :genislik="30"
  >
    <div class="flex flex-col gap-3" data-test="donem-bilinmeyen">
      <p class="text-sm text-surface-500">
        Hizmet ayı bilinmiyor. Ayı yazın, o ayın faturaları arasına girsin. Önerilen ay, ödeme
        tarihinin ayıdır.
      </p>
      <div v-if="faturalar === null" class="flex flex-col gap-2">
        <Skeleton v-for="n in 2" :key="n" height="3.5rem" border-radius="1rem" />
      </div>
      <p v-else-if="!faturalar.length" class="py-4 text-center text-sm text-surface-500">
        Dönemi bilinmeyen fatura kalmadı.
      </p>
      <ul v-else class="divide-y divide-surface-100 rounded-2xl border border-surface-200">
        <li v-for="f in faturalar" :key="f.id" class="flex items-center gap-3 px-3 py-2.5">
          <button
            type="button"
            class="flex min-w-0 flex-1 items-center gap-3 text-left"
            @click="sec(f)"
          >
            <FaturaIkonu :tur-id="f.fatura_turu_id" />
            <span class="min-w-0">
              <span class="block truncate text-sm font-semibold text-surface-900">{{
                f.saglayici_ad
              }}</span>
              <span class="block text-xs text-surface-500"
                >{{ tl(f.tutar) }} ·
                <template v-if="f.odeme_tarihi">ödendi {{ gunAy(f.odeme_tarihi) }}</template>
                <template v-else-if="f.son_odeme_tarihi"
                  >son ödeme {{ gunAy(f.son_odeme_tarihi) }}</template
                >
                <template v-else>tarih yok</template></span
              >
            </span>
          </button>
          <Button
            v-if="oneriDonem(f)"
            :label="donemEtiketi(oneriDonem(f))"
            icon="pi pi-check"
            size="small"
            outlined
            :disabled="calisiyor"
            :aria-label="`${f.saglayici_ad} faturasını ${donemEtiketi(oneriDonem(f))} yap`"
            data-test="oneri-uygula"
            @click="uygula([f])"
          />
          <span v-else class="text-xs text-surface-500">Detaydan seçin</span>
        </li>
      </ul>
      <Button
        v-if="onerililer.length > 1"
        :label="`Önerileri uygula (${onerililer.length})`"
        :loading="calisiyor"
        data-test="onerileri-uygula"
        @click="uygula(onerililer)"
      />
    </div>
  </UygulamaPenceresi>
</template>
