<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import { useToast } from 'primevue/usetoast'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import { aileApi, type KartIslemi, type Kategori, type KategoriOnerisi } from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { useKategoriAta } from '@/composables/useKategoriAta'
import { useAileStore } from '@/stores/aile'
import { tl } from '@/utils/bicim'
import { kartRengi, sonrakiKartRengi } from '@/utils/kartRengi'

/**
 * Kategori seçici (İşlemler'de satıra basılı tutunca): ikonlu ızgara (3 sütun; ikon, ad, kullanım sayısı; en çok
 * kullanılan önce, otomatik atanan fatura kategorisi sonda). Üstte arama (Türkçe harf duyarsız); eşleşen yoksa
 * "X oluştur ve ata" (renk kullanılmayan palet renginden). Sunucunun önerisi (kural ya da ilk kelimesi aynı satırlar)
 * üstte tek dokunuşla. Kapsam: aynı açıklamalı hepsi (kural da kaydedilir) ya da yalnız bu işlem. Atanınca pencere
 * kapanır, altta "Geri al" (useKategoriAta).
 */
const props = defineProps<{ islem: KartIslemi | null }>()
const gorunur = defineModel<boolean>('visible', { default: false })
const emit = defineEmits<{ degisti: [] }>()

const aile = useAileStore()
const toast = useToast()
const { ata: kategoriAta } = useKategoriAta()
const gonderiliyor = ref(false)
const arama = ref('')
const kapsam = ref<'hepsi' | 'tek'>('hepsi')
const bilgi = ref<KategoriOnerisi | null>(null)
const aramaKutusu = ref<{ $el: HTMLInputElement } | null>(null)

const secili = computed(() => props.islem?.kategori_id ?? null)

/** Arama için: küçük harf, Türkçe harfler işaretsiz (saglik → Sağlık) */
const sade = (m: string): string =>
  m
    .toLocaleLowerCase('tr-TR')
    .replace(
      /[çğıöşüâîû]/g,
      (h) => ({ ç: 'c', ğ: 'g', ı: 'i', ö: 'o', ş: 's', ü: 'u', â: 'a', î: 'i', û: 'u' })[h] ?? h,
    )
    .trim()

/** Kullanım sırasıyla; fatura kategorisi (otomatik atanır) en sonda; aramaya göre süzülür */
const kategoriler = computed(() => {
  const liste = [
    ...aile.kategorilerKullanima.filter((k) => !k.fatura),
    ...aile.kategorilerKullanima.filter((k) => k.fatura),
  ]
  const a = sade(arama.value)

  return a ? liste.filter((k) => sade(k.ad).includes(a)) : liste
})
/** Yazılan ad hiçbir kategoriyle birebir aynı değilse oluşturma satırı */
const yeniAd = computed(() => {
  const ad = arama.value.trim().replace(/\s+/g, ' ')
  if (ad.length < 2) return null

  return aile.kategoriler.some((k) => sade(k.ad) === sade(ad)) ? null : ad
})
const oneri = computed(() => {
  const o = bilgi.value?.oneri
  const k = o ? aile.kategori(o.kategori_id) : undefined
  if (!o || !k || arama.value.trim()) return null

  return {
    kategori: k,
    neden:
      o.gerekce === 'kural'
        ? 'Kayıtlı kurala uyuyor'
        : `Benzer ${o.adet} işlem ${k.ad} kategorisinde`,
  }
})
const ayniSayi = computed(() => bilgi.value?.ayni_aciklamali ?? 1)

watch(
  gorunur,
  async (acik) => {
    if (!acik) return
    arama.value = ''
    kapsam.value = 'hepsi'
    bilgi.value = null
    const id = props.islem?.id
    if (!id) return
    try {
      const sonuc = await aileApi.kartIslemiKategoriOnerisi(id)
      if (props.islem?.id === id) bilgi.value = sonuc
    } catch {
      // Öneri ve sayı yalnız yardımcı: alınamazsa seçici yine çalışır
    }
  },
  { immediate: true },
)

const ikon = (k: Kategori): string => k.ikon ?? 'pi pi-tag'
const zemin = (k: Kategori): string => `var(--p-${kartRengi(k)}-500)`

async function ata(kategoriId: number | null): Promise<void> {
  if (!props.islem || gonderiliyor.value) return
  gonderiliyor.value = true
  try {
    const tamam = await kategoriAta(props.islem, kategoriId, () => emit('degisti'), kapsam.value)
    if (tamam) gorunur.value = false
  } finally {
    gonderiliyor.value = false
  }
}

async function olustur(): Promise<void> {
  const ad = yeniAd.value
  if (!ad || gonderiliyor.value) return
  gonderiliyor.value = true
  try {
    const kategori = await aileApi.kategoriEkle(
      ad,
      sonrakiKartRengi(aile.kategoriler.map((k) => k.renk)),
    )
    await aile.yukle(true)
    gonderiliyor.value = false
    await ata(kategori.id)
  } catch (e) {
    gonderiliyor.value = false
    toast.add({ severity: 'error', summary: 'Eklenemedi', detail: hataMesaji(e), life: 5000 })
  }
}

/** Arama kutusunda Enter: tek sonuç varsa onu, yoksa oluşturma satırını uygular */
function enter(): void {
  if (kategoriler.value.length === 1) void ata(kategoriler.value[0]!.id)
  else if (kategoriler.value.length === 0 && yeniAd.value) void olustur()
}

function yeniyeOdaklan(): void {
  aramaKutusu.value?.$el.focus()
}
</script>

<template>
  <UygulamaPenceresi v-model:visible="gorunur" baslik="Kategori seç" :genislik="28">
    <div class="flex flex-col gap-3">
      <p
        v-if="islem"
        class="flex items-center justify-between gap-2 rounded-2xl bg-surface-50 px-3 py-2 text-sm font-semibold"
        data-test="kategori-islem"
      >
        <span class="min-w-0 truncate">{{ islem.aciklama }}</span>
        <span class="shrink-0 text-surface-500 tabular-nums">{{ tl(islem.tutar) }}</span>
      </p>

      <span class="relative block">
        <i
          class="pi pi-search pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-surface-400"
          aria-hidden="true"
        />
        <InputText
          id="kategori-ara"
          ref="aramaKutusu"
          v-model="arama"
          placeholder="Kategori ara ya da yeni yaz"
          aria-label="Kategori ara ya da yeni kategori adı"
          maxlength="40"
          class="w-full !pl-9"
          data-test="kategori-ara"
          @keydown.enter.prevent="enter"
        />
      </span>

      <div
        v-if="oneri"
        class="flex items-center gap-3 rounded-2xl bg-primary-50 p-2.5 ring-1 ring-primary-200"
        data-test="kategori-oneri"
      >
        <span
          class="flex size-9 shrink-0 items-center justify-center rounded-full text-white"
          :style="{ background: zemin(oneri.kategori) }"
          aria-hidden="true"
          ><i :class="ikon(oneri.kategori)" class="text-sm"
        /></span>
        <span class="min-w-0 flex-1">
          <span class="block truncate text-sm font-bold">Önerilen: {{ oneri.kategori.ad }}</span>
          <span class="block truncate text-xs text-primary-700">{{ oneri.neden }}</span>
        </span>
        <Button
          label="Ata"
          size="small"
          :disabled="gonderiliyor"
          data-test="kategori-oneri-ata"
          @click="ata(oneri.kategori.id)"
        />
      </div>

      <button
        v-if="yeniAd"
        type="button"
        class="flex items-center gap-3 rounded-2xl border border-dashed border-primary-300 p-2.5 text-left text-primary-700 active:scale-[0.98]"
        :disabled="gonderiliyor"
        data-test="kategori-olustur"
        @click="olustur"
      >
        <span
          class="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary-100"
          aria-hidden="true"
          ><i class="pi pi-plus text-sm"
        /></span>
        <span class="min-w-0 flex-1">
          <span class="block truncate text-sm font-bold">"{{ yeniAd }}" oluştur ve ata</span>
          <span class="block text-xs text-surface-500">Rengi kendiliğinden seçilir</span>
        </span>
      </button>

      <div class="grid grid-cols-3 gap-2" data-test="kategori-izgarasi">
        <button
          v-for="k in kategoriler"
          :key="k.id"
          type="button"
          class="relative flex flex-col items-center gap-1 rounded-2xl px-1 pt-2.5 pb-2 text-center ring-1 transition-all active:scale-95"
          :class="
            secili === k.id
              ? 'bg-primary-50 ring-2 ring-primary-500'
              : 'bg-surface-0 ring-surface-200'
          "
          :disabled="gonderiliyor"
          :aria-pressed="secili === k.id"
          data-test="kategori-secenegi"
          @click="ata(k.id)"
        >
          <i
            v-if="secili === k.id"
            class="pi pi-check absolute top-1.5 right-2 text-xs text-primary-600"
            aria-hidden="true"
          />
          <span
            class="flex size-9 items-center justify-center rounded-full text-white"
            :style="{ background: zemin(k) }"
            aria-hidden="true"
            ><i :class="ikon(k)" class="text-sm"
          /></span>
          <span class="w-full truncate text-xs font-bold text-surface-900">{{ k.ad }}</span>
          <span class="text-xs text-surface-400 tabular-nums">{{
            k.fatura ? 'otomatik' : (k.kullanim ?? 0)
          }}</span>
        </button>
        <button
          v-if="!arama.trim()"
          type="button"
          class="flex flex-col items-center gap-1 rounded-2xl px-1 pt-2.5 pb-2 text-center text-primary-700 ring-1 ring-primary-200 ring-dashed active:scale-95"
          data-test="yeni-kategori-ac"
          @click="yeniyeOdaklan"
        >
          <span
            class="flex size-9 items-center justify-center rounded-full bg-primary-50"
            aria-hidden="true"
            ><i class="pi pi-plus text-sm"
          /></span>
          <span class="text-xs font-bold">Yeni</span>
          <span class="text-xs text-surface-400">adını yazın</span>
        </button>
      </div>
      <p
        v-if="arama.trim() && !kategoriler.length && !yeniAd"
        class="text-center text-sm text-surface-500"
      >
        Eşleşen kategori yok.
      </p>

      <div v-if="ayniSayi > 1" class="flex flex-col gap-1" data-test="kategori-kapsam">
        <div
          class="flex gap-1 rounded-xl bg-surface-100 p-1 text-sm font-semibold"
          role="radiogroup"
        >
          <button
            v-for="s in [
              { d: 'hepsi', e: `Hepsi (${ayniSayi} işlem)` },
              { d: 'tek', e: 'Yalnız bu işlem' },
            ] as const"
            :key="s.d"
            type="button"
            role="radio"
            class="flex-1 rounded-lg px-2 py-1.5"
            :class="kapsam === s.d ? 'bg-surface-0 text-surface-900 shadow-sm' : 'text-surface-500'"
            :aria-checked="kapsam === s.d"
            :data-test="`kapsam-${s.d}`"
            @click="kapsam = s.d"
          >
            {{ s.e }}
          </button>
        </div>
        <p class="text-xs text-surface-500">
          <template v-if="kapsam === 'hepsi'"
            >Aynı açıklamalı {{ ayniSayi }} işlem ve sonraki ekstreler bu kategoriye
            girer.</template
          >
          <template v-else>Yalnız bu satır değişir; sonraki ekstrelerin kuralı değişmez.</template>
        </p>
      </div>
      <p v-else class="text-xs text-surface-500">
        Sonraki ekstrelerde aynı açıklama bu kategoriye girer.
      </p>

      <Button
        v-if="secili !== null"
        label="Kategorisiz yap"
        icon="pi pi-times"
        severity="secondary"
        text
        class="w-full"
        :disabled="gonderiliyor"
        data-test="kategorisiz-yap"
        @click="ata(null)"
      />
    </div>
  </UygulamaPenceresi>
</template>
