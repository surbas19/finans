<script setup lang="ts">
import { computed, ref } from 'vue'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import DatePicker from 'primevue/datepicker'
import Select from 'primevue/select'
import ToggleSwitch from 'primevue/toggleswitch'
import { useToast } from 'primevue/usetoast'
import { aileApi, GUVEN_ETIKETLERI, KART_ISLEM_TURLERI, type KartIslemi } from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { useAileStore } from '@/stores/aile'
import { tarih, tl } from '@/utils/bicim'
import { donemdenTarih, donemEtiketi, tarihtenDonem } from '@/utils/donem'

/**
 * Ekstre incelemesinde bir satır: PDF'deki ham metin ve sayfası, okunan alanlar, okunamayan / uyarı / tekrar
 * işaretleri, dahil / dışarıda, fatura adayı (güven, gerekçe, sağlayıcı, hizmet dönemi). Değişiklik hemen kaydedilir.
 */
const props = defineProps<{ satir: KartIslemi; ekstreId: number; duzenlenebilir: boolean }>()
const emit = defineEmits<{ degisti: [satir: KartIslemi]; duzelt: [satir: KartIslemi] }>()
const aile = useAileStore()
const toast = useToast()
const kaydediliyor = ref(false)
/** Önerisi olmayan satırda kullanıcı faturayı kendisi işaretlemek isterse */
const faturaAcik = ref(false)

const dahil = computed(() => props.satir.durum !== 'atlandi')
const oneri = computed(() => props.satir.fatura_onerisi)
const okunamayan = computed(() => props.satir.okuma?.alanlar ?? [])
const saglayicilar = computed(() => {
  const adaylar = oneri.value?.adaylar ?? []

  return [...aile.saglayicilar].sort(
    (a, b) => Number(adaylar.includes(b.id)) - Number(adaylar.includes(a.id)),
  )
})
const donemSecimi = computed<Date | null>(() =>
  oneri.value?.hizmet_donemi ? donemdenTarih(oneri.value.hizmet_donemi) : null,
)

async function gonder(veri: Record<string, unknown>): Promise<void> {
  kaydediliyor.value = true
  try {
    const sonuc = await aileApi.ekstreSatiri(props.ekstreId, props.satir.id, veri)
    emit('degisti', sonuc.islem)
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Kaydedilemedi', detail: hataMesaji(e), life: 5000 })
  } finally {
    kaydediliyor.value = false
  }
}

function fatura(degisiklik: Record<string, unknown>): void {
  void gonder({ fatura: degisiklik })
}

function donemSec(d: Date | Date[] | (Date | null)[] | null | undefined): void {
  fatura({ hizmet_donemi: d instanceof Date ? tarihtenDonem(d) : null })
}
</script>

<template>
  <li
    class="flex flex-col gap-2 rounded-2xl p-3 ring-1 transition-colors"
    :class="dahil ? 'bg-white ring-surface-200' : 'bg-surface-50 ring-surface-100'"
    data-test="ekstre-satiri"
  >
    <div class="flex items-start gap-3">
      <Checkbox
        v-if="duzenlenebilir"
        :model-value="dahil"
        binary
        :input-id="`satir-${satir.id}`"
        :aria-label="dahil ? 'Dışarıda bırak' : 'Dahil et'"
        :disabled="kaydediliyor"
        class="mt-0.5"
        @update:model-value="(d: boolean) => gonder({ dahil: d })"
      />
      <div class="min-w-0 flex-1" :class="{ 'opacity-60': !dahil }">
        <div class="flex items-start justify-between gap-2">
          <label :for="`satir-${satir.id}`" class="min-w-0">
            <span class="block font-semibold break-words text-surface-900">{{
              satir.aciklama
            }}</span>
            <span class="block text-xs text-surface-500">
              <span :class="{ 'font-semibold text-red-600': okunamayan.includes('islem_tarihi') }">
                {{ satir.islem_tarihi ? tarih(satir.islem_tarihi) : 'tarih okunamadı' }}
              </span>
              · {{ KART_ISLEM_TURLERI[satir.tur].etiket }}
              <template v-if="satir.taksit_sayisi">
                · taksit {{ satir.taksit_no }}/{{ satir.taksit_sayisi }}</template
              >
              <template v-if="aile.kategori(satir.kategori_id)">
                · {{ aile.kategori(satir.kategori_id)!.ad }}</template
              >
            </span>
          </label>
          <span
            class="font-semibold whitespace-nowrap tabular-nums"
            :class="
              okunamayan.includes('tutar')
                ? 'text-red-600'
                : KART_ISLEM_TURLERI[satir.tur].gider < 0
                  ? 'text-emerald-700'
                  : 'text-surface-900'
            "
          >
            {{ satir.tutar !== null ? tl(satir.tutar) : 'okunamadı' }}
          </span>
        </div>
        <!-- PDF'deki satır: karşılaştırma için -->
        <p
          v-if="satir.ham_metin"
          class="mt-1 truncate font-mono text-[11px] text-surface-400"
          :title="satir.ham_metin"
        >
          {{ satir.ham_metin }}
        </p>
      </div>
    </div>

    <div class="flex flex-wrap items-center gap-1.5 pl-0 text-xs sm:pl-8">
      <span
        v-if="satir.tekrar"
        class="rounded-full bg-amber-100 px-2 py-0.5 font-semibold text-amber-800"
      >
        Zaten kayıtlı: {{ satir.tekrar.yer }}
      </span>
      <span
        v-if="okunamayan.length"
        class="rounded-full bg-red-100 px-2 py-0.5 font-semibold text-red-700"
      >
        Okunamadı: {{ okunamayan.map((a) => (a === 'tutar' ? 'tutar' : 'tarih')).join(', ') }}
      </span>
      <span
        v-for="u in satir.okuma?.uyarilar ?? []"
        :key="u"
        class="rounded-full bg-sky-50 px-2 py-0.5 text-sky-800"
        >{{ u }}</span
      >
      <span class="ml-auto flex items-center gap-1">
        <a
          v-if="satir.sayfa"
          :href="aileApi.ekstreDosyaAdresi(ekstreId, satir.sayfa)"
          target="_blank"
          rel="noopener"
          class="rounded-full px-2 py-1 font-semibold text-primary-600 hover:bg-primary-50"
          >PDF s.{{ satir.sayfa }}</a
        >
        <Button
          v-if="duzenlenebilir"
          label="Düzelt"
          icon="pi pi-pencil"
          size="small"
          text
          @click="emit('duzelt', satir)"
        />
      </span>
    </div>

    <!-- Fatura adayı -->
    <div
      v-if="oneri || faturaAcik"
      class="flex flex-col gap-2 rounded-xl bg-surface-50 p-2.5 sm:ml-8"
      data-test="fatura-onerisi"
    >
      <div class="flex flex-wrap items-center gap-2 text-xs">
        <i class="pi pi-bolt text-amber-500" />
        <span class="font-semibold text-surface-700">Fatura adayı</span>
        <span
          v-if="oneri"
          class="rounded-full px-2 py-0.5 font-semibold"
          :class="GUVEN_ETIKETLERI[oneri.guven].renk"
          >{{ GUVEN_ETIKETLERI[oneri.guven].etiket }}</span
        >
        <span v-if="oneri?.otomatik" class="text-emerald-700">otomatik kayıt</span>
        <label
          v-if="duzenlenebilir"
          class="ml-auto flex items-center gap-1.5 font-semibold text-surface-700"
        >
          <ToggleSwitch
            :model-value="oneri?.secildi ?? false"
            :disabled="kaydediliyor || !oneri?.saglayici_id"
            @update:model-value="(d: boolean) => fatura({ secildi: d })"
          />
          Faturaya bağla
        </label>
      </div>
      <ul v-if="oneri?.gerekce?.length" class="list-disc pl-5 text-xs text-surface-600">
        <li v-for="g in oneri.gerekce" :key="g">{{ g }}</li>
      </ul>
      <div v-if="duzenlenebilir" class="grid grid-cols-1 gap-2 sm:grid-cols-2">
        <Select
          :model-value="oneri?.saglayici_id ?? null"
          :options="saglayicilar"
          option-label="ad"
          option-value="id"
          placeholder="Sağlayıcı seçin"
          size="small"
          aria-label="Sağlayıcı"
          :disabled="kaydediliyor"
          @update:model-value="
            (id: number | null) => fatura({ saglayici_id: id, secildi: id !== null })
          "
        />
        <DatePicker
          :model-value="donemSecimi"
          view="month"
          date-format="MM yy"
          placeholder="Hizmet dönemi bilinmiyor"
          size="small"
          aria-label="Hizmet dönemi"
          show-button-bar
          :disabled="kaydediliyor"
          @update:model-value="donemSec"
        />
      </div>
      <p v-else class="text-xs text-surface-600">
        {{ aile.saglayici(oneri?.saglayici_id)?.ad ?? 'Sağlayıcı yok' }} ·
        {{ donemEtiketi(oneri?.hizmet_donemi) }}
      </p>
      <p v-if="oneri && oneri.hizmet_donemi && duzenlenebilir" class="text-[11px] text-surface-500">
        Dönem
        {{
          oneri.donem_kaynagi === 'aciklama' ? 'ekstre açıklamasından okundu' : 'sizin seçiminiz'
        }}.
      </p>
    </div>
    <button
      v-else-if="duzenlenebilir && satir.tur === 'harcama' && aile.saglayicilar.length"
      type="button"
      class="self-start text-xs font-semibold text-primary-600 sm:ml-8"
      @click="faturaAcik = true"
    >
      Bu bir fatura ödemesi mi?
    </button>
  </li>
</template>
