<script setup lang="ts">
import { computed } from 'vue'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import type { ZekatOdemesi } from '@/api/aile'
import { finansApi, islemEtiketi, type Islem } from '@/api/finans'
import { hataMesaji } from '@/api/http'
import IslemNo from '@/components/IslemNo.vue'
import VarlikAdi from '@/components/VarlikAdi.vue'
import { useIslemSil } from '@/composables/useIslemSil'
import { fiyat, miktar, tarih, tarihSaat, tl, yuzde } from '@/utils/bicim'
import { useToast } from 'primevue/usetoast'

/**
 * İşlem geçmişi: tarih, tür, varlık, miktar × fiyat, tutar.
 * Telefonda her satır kart gibidir: dokununca düzenleme açılır (silme düzenleme penceresinde). Masaüstünde tarih
 * sütunu ve satır sonunda düzenle / sil düğmeleri.
 */
const props = defineProps<{
  islemler: Islem[]
  /** Satırda hesap adı gösterilsin (tüm işlemler sayfası) */
  hesapGoster?: boolean
  /** Verilen zekât ödemeleri: aynı listede tarihe göre karışır, "Zekât" rozetiyle */
  zekatOdemeleri?: ZekatOdemesi[]
}>()
const emit = defineEmits<{
  duzenle: [islem: Islem]
  silindi: []
  zekatSil: [odeme: ZekatOdemesi]
}>()

type Satir =
  | { tur: 'islem'; anahtar: string; tarih: string; islem: Islem }
  | { tur: 'zekat'; anahtar: string; tarih: string; odeme: ZekatOdemesi }

/** İşlemler ve zekât ödemeleri en yeni üstte; aynı günde önce işlemler (sıralama kararlı) */
const satirlar = computed<Satir[]>(() =>
  [
    ...props.islemler.map((islem): Satir => ({
      tur: 'islem',
      anahtar: `i${islem.id}`,
      tarih: islem.tarih,
      islem,
    })),
    ...(props.zekatOdemeleri ?? []).map((odeme): Satir => ({
      tur: 'zekat',
      anahtar: `z${odeme.id}`,
      tarih: odeme.tarih,
      odeme,
    })),
  ].sort((a, b) => b.tarih.localeCompare(a.tarih)),
)

const { sil } = useIslemSil()
const toast = useToast()

/** Alım-satım tarihinin etiketi (kaydın girildiği "İşlem" zamanından ayrı) */
const TARIH_ETIKETI = {
  alis: 'Alım',
  satis: 'Satım',
  mevcut: 'Elde olan',
  deger: 'Güncel tutar',
} as const

/** İşlemi genel toplama / zekâta dahil eder ya da çıkarır (aktarım çiftinde eşi de değişir); sonra liste yenilenir */
async function dahilDegistir(i: Islem): Promise<void> {
  try {
    const r = await finansApi.islemDahil(i.id, i.dahil === false)
    toast.add({ severity: 'success', summary: r.message, life: 3000 })
    emit('silindi')
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Yapılamadı', detail: hataMesaji(e), life: 6000 })
  }
}

const TUR_RENGI = {
  alis: 'success',
  satis: 'danger',
  mevcut: 'secondary',
  deger: 'info',
} as const

/** Miktar × fiyat; nakitte yok (tutar yeterli), bireysel emeklilikte kâr yüzdesi */
function ayrinti(i: Islem): string | null {
  const tur = i.varlik?.tur
  if (tur === 'nakit') return null
  if (tur === 'bes') return i.kar_yuzde != null ? `${yuzde(i.kar_yuzde)} kâr` : 'kâr yüzdesi yok'
  const birim = `${miktar(i.miktar)} ${i.varlik?.birim ?? ''}`.trim()

  return i.birim_fiyat !== null
    ? `${birim} × ${fiyat(i.birim_fiyat)}`
    : `${birim} · fiyat bilinmiyor`
}

/** Satırın alt yazısı: (telefonda tarih) · ayrıntı · komisyon · hesap */
function ayrintiMetni(i: Islem, tarihli: boolean): string {
  return [
    tarihli ? tarih(i.tarih) : null,
    ayrinti(i),
    i.komisyon > 0 ? `komisyon ${tl(i.komisyon)}` : null,
    props.hesapGoster && i.hesap_ad ? i.hesap_ad : null,
  ]
    .filter(Boolean)
    .join(' · ')
}
</script>

<template>
  <ul class="divide-y divide-surface-100">
    <li
      v-for="s in satirlar"
      :key="s.anahtar"
      :class="s.tur === 'islem' && s.islem.dahil === false ? 'opacity-55' : ''"
      :data-dahil="s.tur === 'islem' ? (s.islem.dahil === false ? 'hayir' : 'evet') : undefined"
    >
      <template v-if="s.tur === 'islem'">
        <!-- Telefon -->
        <div class="flex items-center sm:hidden">
          <button
            type="button"
            class="flex min-h-14 min-w-0 flex-1 items-center gap-3 py-3 text-left transition-colors active:bg-surface-50"
            :aria-label="`${tarih(s.islem.tarih)} ${islemEtiketi(s.islem.tur, s.islem.varlik?.tur)} ${s.islem.varlik ? `${s.islem.varlik.kod} ${s.islem.varlik.ad}` : ''}: düzenle`"
            data-test="islem-karti"
            @click="
              s.islem.aktarim_kodu ? sil(s.islem, () => emit('silindi')) : emit('duzenle', s.islem)
            "
          >
            <span class="min-w-0 flex-1">
              <span class="flex items-center gap-2">
                <Tag
                  :value="islemEtiketi(s.islem.tur, s.islem.varlik?.tur)"
                  :severity="TUR_RENGI[s.islem.tur]"
                  class="shrink-0"
                />
                <Tag
                  v-if="s.islem.dahil === false"
                  value="Hariç"
                  severity="secondary"
                  class="shrink-0"
                  data-test="haric-etiketi"
                />
                <Tag
                  v-if="s.islem.aktarim_kodu"
                  value="Aktarım"
                  severity="info"
                  class="shrink-0"
                  data-test="aktarim-rozeti"
                />
                <VarlikAdi
                  v-if="s.islem.varlik"
                  :kod="s.islem.varlik.kod"
                  :ad="s.islem.varlik.ad"
                />
              </span>
              <span class="mt-1 line-clamp-2 text-xs text-surface-500 tabular-nums">
                {{ ayrintiMetni(s.islem, true) }}
              </span>
              <span v-if="s.islem.not" class="mt-0.5 block truncate text-xs text-surface-400">{{
                s.islem.not
              }}</span>
              <span class="mt-0.5 flex flex-wrap items-center gap-x-2 text-[11px] text-surface-400">
                <IslemNo :no="s.islem.no" />
                <span>{{ TARIH_ETIKETI[s.islem.tur] }}: {{ tarih(s.islem.tarih) }}</span>
                <span v-if="s.islem.islem_zamani" data-test="islem-zamani"
                  >İşlem: {{ tarihSaat(s.islem.islem_zamani) }}</span
                >
              </span>
            </span>
            <span class="flex shrink-0 items-center gap-1.5">
              <span class="font-semibold whitespace-nowrap text-surface-900 tabular-nums">
                {{ tl(s.islem.tutar) }}
              </span>
              <s.islem class="pi pi-angle-right text-sm text-surface-400" />
            </span>
          </button>
          <button
            type="button"
            class="ml-1 flex w-9 shrink-0 flex-col items-center gap-0.5 rounded-xl py-1.5 text-surface-500 hover:bg-surface-100"
            :aria-label="s.islem.dahil === false ? 'Genel toplama ekle' : 'Genel toplamdan çıkar'"
            data-test="islem-dahil"
            @click="dahilDegistir(s.islem)"
          >
            <i
              :class="s.islem.dahil === false ? 'pi pi-eye-slash' : 'pi pi-eye text-primary-600'"
            />
          </button>
        </div>

        <!-- Masaüstü -->
        <div class="hidden items-center gap-3 py-3 sm:flex">
          <div class="w-20 shrink-0 text-xs text-surface-500 tabular-nums">
            {{ tarih(s.islem.tarih) }}
          </div>
          <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
              <Tag
                :value="islemEtiketi(s.islem.tur, s.islem.varlik?.tur)"
                :severity="TUR_RENGI[s.islem.tur]"
                class="shrink-0"
              />
              <Tag
                v-if="s.islem.dahil === false"
                value="Hariç"
                severity="secondary"
                class="shrink-0"
                data-test="haric-etiketi"
              />
              <Tag
                v-if="s.islem.aktarim_kodu"
                value="Aktarım"
                severity="info"
                class="shrink-0"
                data-test="aktarim-rozeti"
              />
              <VarlikAdi v-if="s.islem.varlik" :kod="s.islem.varlik.kod" :ad="s.islem.varlik.ad" />
            </div>
            <div class="mt-0.5 truncate text-xs text-surface-500">
              {{ ayrintiMetni(s.islem, false) }}
            </div>
            <div v-if="s.islem.not" class="mt-0.5 truncate text-xs text-surface-400">
              {{ s.islem.not }}
            </div>
            <div class="mt-0.5 flex flex-wrap items-center gap-x-2 text-[11px] text-surface-400">
              <IslemNo :no="s.islem.no" />
              <span>{{ TARIH_ETIKETI[s.islem.tur] }}: {{ tarih(s.islem.tarih) }}</span>
              <span v-if="s.islem.islem_zamani" data-test="islem-zamani"
                >İşlem: {{ tarihSaat(s.islem.islem_zamani) }}</span
              >
            </div>
          </div>
          <div class="shrink-0 text-right font-semibold text-surface-900 tabular-nums">
            {{ tl(s.islem.tutar) }}
          </div>
          <div class="flex shrink-0">
            <Button
              :icon="s.islem.dahil === false ? 'pi pi-eye-slash' : 'pi pi-eye'"
              text
              rounded
              size="small"
              severity="secondary"
              :aria-label="s.islem.dahil === false ? 'Genel toplama ekle' : 'Genel toplamdan çıkar'"
              data-test="islem-dahil"
              @click="dahilDegistir(s.islem)"
            />
            <Button
              v-if="!s.islem.aktarim_kodu"
              icon="pi pi-pencil"
              text
              rounded
              size="small"
              aria-label="Düzenle"
              @click="emit('duzenle', s.islem)"
            />
            <Button
              icon="pi pi-trash"
              text
              rounded
              size="small"
              severity="danger"
              aria-label="Sil"
              @click="sil(s.islem, () => emit('silindi'))"
            />
          </div>
        </div>
      </template>

      <!-- Verilen zekât: telefonda dokununca silme onayı, masaüstünde silme düğmesi -->
      <div v-else class="flex min-h-14 items-center gap-3 py-3" data-test="zekat-satiri">
        <button
          type="button"
          class="flex min-w-0 flex-1 items-center gap-3 text-left sm:cursor-default"
          :aria-label="`${tarih(s.odeme.tarih)} verilen zekât ${tl(s.odeme.tutar)}: sil`"
          @click="emit('zekatSil', s.odeme)"
        >
          <span class="w-20 shrink-0 text-xs text-surface-500 tabular-nums max-sm:hidden">{{
            tarih(s.odeme.tarih)
          }}</span>
          <span class="min-w-0 flex-1">
            <span class="flex items-center gap-2">
              <Tag value="Zekât" severity="warn" class="shrink-0" />
              <span class="truncate font-semibold text-surface-900">{{
                s.odeme.alici || 'Verilen zekât'
              }}</span>
            </span>
            <span class="mt-1 block truncate text-xs text-surface-500 tabular-nums">
              <span class="sm:hidden">{{ tarih(s.odeme.tarih) }}</span>
              <template v-if="s.odeme.not">
                <span class="sm:hidden"> · </span>{{ s.odeme.not }}
              </template>
            </span>
          </span>
          <span class="shrink-0 font-semibold whitespace-nowrap text-surface-900 tabular-nums">
            {{ tl(s.odeme.tutar) }}
          </span>
        </button>
        <Button
          class="max-sm:hidden"
          icon="pi pi-trash"
          text
          rounded
          size="small"
          severity="danger"
          aria-label="Zekât ödemesini sil"
          @click="emit('zekatSil', s.odeme)"
        />
      </div>
    </li>
  </ul>
</template>
