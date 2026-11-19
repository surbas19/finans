<script setup lang="ts">
import { computed, ref } from 'vue'
import Button from 'primevue/button'
import { useToast } from 'primevue/usetoast'
import FaturaIkonu from '@/components/aile/FaturaIkonu.vue'
import KartEtiketi from '@/components/aile/KartEtiketi.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import { aileApi, GUVEN_ETIKETLERI, type Fatura } from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { useAileStore } from '@/stores/aile'
import { bugun, tarih, tl } from '@/utils/bicim'
import { durumRozeti, faturaGirdisi } from '@/utils/fatura'

/**
 * Fatura detayı (telefonda alttan açılan pencere): tutar ve durum, hizmet dönemi (kaynağıyla), son ödeme, ödeme,
 * bağlı kart satırı (güveniyle; "Bağı çöz") ya da "Kart satırına bağla"; "Ödedim" (bugün ödendi), "Geçmiş"
 * (sağlayıcının bütün faturaları), "Düzenle" (silme formda).
 */
const props = defineProps<{ fatura: Fatura | null }>()
const gorunur = defineModel<boolean>('visible', { default: false })
const emit = defineEmits<{
  duzenle: [f: Fatura]
  bagla: [f: Fatura]
  gecmis: [saglayiciId: number]
  degisti: []
}>()

const aile = useAileStore()
const toast = useToast()
const calisiyor = ref(false)

const rozet = computed(() => (props.fatura ? durumRozeti(props.fatura) : null))
const KAYNAK = {
  aciklama: 'ekstre açıklamasından',
  kesim: 'ekstrenin ayından',
  kullanici: 'siz seçtiniz',
}

async function islem(is: () => Promise<unknown>, mesaj: string): Promise<void> {
  calisiyor.value = true
  try {
    await is()
    toast.add({ severity: 'success', summary: mesaj, life: 3500 })
    gorunur.value = false
    emit('degisti')
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Yapılamadı', detail: hataMesaji(e), life: 5000 })
  } finally {
    calisiyor.value = false
  }
}

function odendi(): void {
  const f = props.fatura
  if (!f) return
  void islem(
    () =>
      aileApi.faturaGuncelle(f.id, faturaGirdisi(f, { durum: 'odendi', odeme_tarihi: bugun() })),
    `${f.saglayici_ad ?? 'Fatura'} ödendi olarak işaretlendi`,
  )
}

function coz(): void {
  const f = props.fatura
  if (!f) return
  void islem(() => aileApi.faturaCoz(f.id), 'Bağ çözüldü; fatura ödenmemiş görünüyor')
}

function gec(olay: 'duzenle' | 'bagla'): void {
  const f = props.fatura
  if (!f) return
  gorunur.value = false
  if (olay === 'duzenle') emit('duzenle', f)
  else emit('bagla', f)
}
</script>

<template>
  <UygulamaPenceresi v-model:visible="gorunur" :genislik="28">
    <template #header>
      <span v-if="fatura" class="flex min-w-0 items-center gap-3">
        <FaturaIkonu :tur-id="fatura.fatura_turu_id" />
        <span class="min-w-0">
          <span class="block truncate font-bold text-surface-900">{{ fatura.saglayici_ad }}</span>
          <span class="block text-xs text-surface-500">{{
            aile.tur(fatura.fatura_turu_id)?.ad ?? 'Fatura'
          }}</span>
        </span>
      </span>
    </template>
    <div v-if="fatura" class="flex flex-col gap-3" data-test="fatura-detayi">
      <div class="flex items-center justify-between">
        <span class="text-2xl font-bold tabular-nums">{{ tl(fatura.tutar) }}</span>
        <span
          v-if="rozet"
          class="rounded-full px-2.5 py-0.5 text-xs font-bold"
          :class="rozet.renk"
          >{{ rozet.metin }}</span
        >
      </div>
      <dl class="divide-y divide-surface-100 text-sm">
        <div class="flex justify-between gap-3 py-2">
          <dt class="text-surface-500">Hizmet dönemi</dt>
          <dd class="text-right font-semibold">
            {{ fatura.hizmet_donemi_etiketi ?? 'Bilinmiyor' }}
            <span v-if="fatura.donem_kaynagi" class="block text-xs font-normal text-surface-500">{{
              KAYNAK[fatura.donem_kaynagi]
            }}</span>
          </dd>
        </div>
        <div class="flex justify-between gap-3 py-2">
          <dt class="text-surface-500">Son ödeme</dt>
          <dd class="font-semibold">{{ tarih(fatura.son_odeme_tarihi) }}</dd>
        </div>
        <div v-if="fatura.odeme_tarihi" class="flex justify-between gap-3 py-2">
          <dt class="text-surface-500">Ödendi</dt>
          <dd class="font-semibold">{{ tarih(fatura.odeme_tarihi) }}</dd>
        </div>
        <div v-if="fatura.not" class="flex justify-between gap-3 py-2">
          <dt class="text-surface-500">Not</dt>
          <dd class="min-w-0 text-right break-words">{{ fatura.not }}</dd>
        </div>
      </dl>

      <div
        v-if="fatura.kart_islemi"
        class="flex flex-col gap-1.5 rounded-2xl border border-surface-200 bg-surface-50 p-3 text-sm"
        data-test="detay-kart"
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <KartEtiketi :kart-id="fatura.kart_islemi.kart_id" />
          <span
            v-if="fatura.eslesme?.guven"
            class="rounded-full px-2 py-0.5 text-xs font-semibold"
            :class="GUVEN_ETIKETLERI[fatura.eslesme.guven].renk"
            >{{
              fatura.eslesme.otomatik
                ? 'Otomatik eşleşti'
                : GUVEN_ETIKETLERI[fatura.eslesme.guven].etiket
            }}</span
          >
        </div>
        <span class="text-surface-600"
          >{{ tarih(fatura.kart_islemi.islem_tarihi) }} · {{ fatura.kart_islemi.aciklama }} ·
          <b class="tabular-nums">{{ tl(fatura.kart_islemi.tutar) }}</b></span
        >
        <Button
          label="Bağı çöz"
          size="small"
          text
          severity="danger"
          class="self-end"
          :loading="calisiyor"
          data-test="detay-coz"
          @click="coz"
        />
      </div>
      <Button
        v-else
        label="Kart satırına bağla"
        icon="pi pi-link"
        size="small"
        outlined
        data-test="detay-bagla"
        @click="gec('bagla')"
      />

      <div class="flex flex-wrap gap-2">
        <Button
          v-if="fatura.durum === 'bekliyor'"
          label="Ödedim"
          icon="pi pi-check"
          size="small"
          class="flex-1"
          :loading="calisiyor"
          data-test="detay-odendi"
          @click="odendi"
        />
        <Button
          label="Geçmiş"
          icon="pi pi-chart-bar"
          size="small"
          severity="secondary"
          outlined
          class="flex-1"
          data-test="detay-gecmis"
          @click="emit('gecmis', fatura.saglayici_id)"
        />
        <Button
          label="Düzenle"
          icon="pi pi-pencil"
          size="small"
          severity="secondary"
          outlined
          class="flex-1"
          data-test="detay-duzenle"
          @click="gec('duzenle')"
        />
      </div>
    </div>
  </UygulamaPenceresi>
</template>
