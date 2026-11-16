<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import { useToast } from 'primevue/usetoast'
import RenkSecici from '@/components/aile/RenkSecici.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import { aileApi, type KartIslemi } from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { useAileStore } from '@/stores/aile'
import { KART_RENKLERI, sonrakiKartRengi } from '@/utils/kartRengi'
import { kategoriNoktasi, kategoriSeridi } from '@/utils/kategoriRengi'

/**
 * Satıra basılı tutunca açılan kategori seçici: kategoriler renkleriyle, "Kategorisiz yap" ve yeni kategori ekleme.
 * Seçim işleme ve açıklaması aynı olan bütün satırlara uygulanır (sunucu; kural da kaydedilir).
 */
const props = defineProps<{ islem: KartIslemi | null }>()
const gorunur = defineModel<boolean>('visible', { default: false })
const emit = defineEmits<{ degisti: [] }>()

const aile = useAileStore()
const toast = useToast()
const gonderiliyor = ref(false)
const yeniAcik = ref(false)
const yeniAd = ref('')
const yeniRenk = ref<string | null>(null)

const secili = computed(() => props.islem?.kategori_id ?? null)
/** "Sık kullanılan" 4 kategori ve geri kalanı (ikisi de en çok kullanılandan başlar) */
const sik = computed(() => aile.sikKategoriler)
/** Geri kalanlar kullanım sırasıyla; otomatik atanan fatura kategorisi en sonda */
const digerleri = computed(() => {
  const kalan = aile.kategorilerKullanima.filter((k) => !sik.value.includes(k))

  return [...kalan.filter((k) => !k.fatura), ...kalan.filter((k) => k.fatura)]
})

watch(gorunur, (a) => {
  if (!a) return
  yeniAcik.value = false
  yeniAd.value = ''
  yeniRenk.value = sonrakiKartRengi(aile.kategoriler.map((k) => k.renk))
})

async function ata(kategoriId: number | null): Promise<void> {
  if (!props.islem || gonderiliyor.value) return
  gonderiliyor.value = true
  try {
    const sonuc = await aileApi.kartIslemiKategori(props.islem.id, kategoriId)
    toast.add({ severity: 'success', summary: sonuc.message, life: 3500 })
    gorunur.value = false
    // Kullanım sayıları değişti: "sık kullanılan" sırası yenilenir
    void aile.kategorileriYenile().catch(() => {})
    emit('degisti')
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Yapılamadı', detail: hataMesaji(e), life: 5000 })
  } finally {
    gonderiliyor.value = false
  }
}

async function yeniEkle(): Promise<void> {
  const ad = yeniAd.value.trim()
  if (ad.length < 2 || gonderiliyor.value) return
  gonderiliyor.value = true
  try {
    const kategori = await aileApi.kategoriEkle(ad, yeniRenk.value)
    await aile.yukle(true)
    gonderiliyor.value = false
    await ata(kategori.id)
  } catch (e) {
    gonderiliyor.value = false
    toast.add({ severity: 'error', summary: 'Eklenemedi', detail: hataMesaji(e), life: 5000 })
  }
}
</script>

<template>
  <UygulamaPenceresi v-model:visible="gorunur" baslik="Kategori seç" :genislik="26">
    <p
      v-if="islem"
      class="mb-3 truncate rounded-2xl bg-surface-50 px-3 py-2 text-sm font-semibold"
      data-test="kategori-islem"
    >
      {{ islem.aciklama }}
    </p>
    <p class="mb-2 text-xs text-surface-500">
      Aynı açıklamalı bütün işlemler ve sonraki ekstreler bu kategoriye girer.
    </p>
    <div v-if="sik.length" class="mb-3" data-test="kategori-sik">
      <p class="bolum-etiketi mb-1.5">Sık kullanılan</p>
      <div class="grid grid-cols-2 gap-2">
        <button
          v-for="k in sik"
          :key="k.id"
          type="button"
          class="relative flex items-center gap-2 overflow-hidden rounded-2xl px-3 py-3.5 text-left ring-1 transition-all active:scale-95"
          :class="secili === k.id ? 'bg-surface-50 ring-surface-400' : 'ring-surface-200'"
          :disabled="gonderiliyor"
          data-test="kategori-secenegi"
          @click="ata(k.id)"
        >
          <span
            class="pointer-events-none absolute inset-y-0 left-0 w-14"
            :style="{ background: kategoriSeridi(k) }"
            aria-hidden="true"
          />
          <span
            class="relative size-2.5 shrink-0 rounded-full"
            :style="{ background: kategoriNoktasi(k) }"
          />
          <span class="relative min-w-0 flex-1 truncate font-semibold">{{ k.ad }}</span>
          <i v-if="secili === k.id" class="pi pi-check relative text-primary-600" />
        </button>
      </div>
    </div>
    <p v-if="digerleri.length" class="bolum-etiketi mb-1.5">
      {{ sik.length ? 'Diğerleri' : 'Kategoriler' }}
    </p>
    <ul class="flex flex-col gap-1.5" data-test="kategori-listesi">
      <li v-for="k in digerleri" :key="k.id">
        <button
          type="button"
          class="relative flex w-full items-center gap-3 overflow-hidden rounded-2xl px-3 py-3 text-left ring-1 transition-all active:scale-[0.98]"
          :class="secili === k.id ? 'bg-surface-50 ring-surface-400' : 'ring-surface-200'"
          :disabled="gonderiliyor"
          data-test="kategori-secenegi"
          @click="ata(k.id)"
        >
          <span
            class="pointer-events-none absolute inset-y-0 left-0 w-16"
            :style="{ background: kategoriSeridi(k) }"
            aria-hidden="true"
          />
          <span
            class="relative size-2.5 shrink-0 rounded-full"
            :style="{ background: kategoriNoktasi(k) }"
          />
          <span class="relative min-w-0 flex-1 truncate font-semibold">{{ k.ad }}</span>
          <i v-if="secili === k.id" class="pi pi-check relative text-primary-600" />
        </button>
      </li>
    </ul>
    <Button
      v-if="secili !== null"
      label="Kategorisiz yap"
      icon="pi pi-times"
      severity="secondary"
      text
      class="mt-2 w-full"
      :disabled="gonderiliyor"
      data-test="kategorisiz-yap"
      @click="ata(null)"
    />

    <div class="mt-3 border-t border-surface-100 pt-3">
      <Button
        v-if="!yeniAcik"
        label="Yeni kategori"
        icon="pi pi-plus"
        severity="secondary"
        outlined
        class="w-full"
        data-test="yeni-kategori-ac"
        @click="yeniAcik = true"
      />
      <form v-else class="flex flex-col gap-3" @submit.prevent="yeniEkle">
        <InputText
          v-model="yeniAd"
          placeholder="Kategori adı"
          maxlength="40"
          aria-label="Yeni kategori adı"
          data-test="yeni-kategori-ad"
        />
        <RenkSecici v-model="yeniRenk" :renkler="KART_RENKLERI" />
        <Button
          type="submit"
          label="Ekle ve bu işleme ata"
          icon="pi pi-check"
          :disabled="yeniAd.trim().length < 2 || gonderiliyor"
          :loading="gonderiliyor"
          data-test="yeni-kategori-ekle"
        />
      </form>
    </div>
  </UygulamaPenceresi>
</template>
