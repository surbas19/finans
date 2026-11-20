<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import BosDurum from '@/components/BosDurum.vue'
import { aileApi, type SilinenKaydi } from '@/api/aile'
import { tarihSaat, tl } from '@/utils/bicim'

/**
 * Silinenler: silinen her kaydın arşivi (salt okunur). Satırların üstü çizilidir; her satırda kaydı kimin ne zaman
 * yaptığı ve kimin ne zaman sildiği görünür. Satıra dokununca saklanan alanlar ve bağlı kayıtlar (ekstre satırları,
 * hesap işlemleri, borç ödemeleri) açılır. Geri yükleme yoktur.
 */
const kayitlar = ref<SilinenKaydi[]>([])
const toplam = ref(0)
const sayfa = ref(1)
const sonraki = ref(false)
const turler = ref<Record<string, string>>({})
const sayilar = ref<Record<string, number>>({})
const secili = ref<string | null>(null)
const yuklendi = ref(false)
const yukleniyor = ref(false)
const acik = ref<number | null>(null)
const ayrinti = ref<SilinenKaydi | null>(null)

async function yukle(ilk = true): Promise<void> {
  yukleniyor.value = true
  try {
    const yanit = await aileApi.silinenler(secili.value ?? undefined, ilk ? 1 : sayfa.value + 1)
    kayitlar.value = ilk ? yanit.kayitlar : [...kayitlar.value, ...yanit.kayitlar]
    toplam.value = yanit.toplam
    sayfa.value = yanit.sayfa
    sonraki.value = yanit.sonraki
    turler.value = yanit.turler
    sayilar.value = yanit.tur_sayilari
  } finally {
    yukleniyor.value = false
    yuklendi.value = true
  }
}
onMounted(() => yukle())

const cipler = computed(() => [
  { tur: null, ad: 'Tümü', sayi: Object.values(sayilar.value).reduce((a, b) => a + b, 0) },
  ...Object.entries(turler.value)
    .filter(([tur]) => (sayilar.value[tur] ?? 0) > 0 || secili.value === tur)
    .map(([tur, ad]) => ({ tur, ad, sayi: sayilar.value[tur] ?? 0 })),
])

async function sec(tur: string | null): Promise<void> {
  secili.value = tur
  acik.value = null
  await yukle()
}

async function ac(k: SilinenKaydi): Promise<void> {
  if (acik.value === k.id) {
    acik.value = null

    return
  }
  acik.value = k.id
  ayrinti.value = null
  ayrinti.value = await aileApi.silinenAyrinti(k.id)
}

/** Ayrıntıda gösterilen alanlar: boş olmayan, kimlik / zaman damgası olmayanlar */
const GIZLI = new Set(['id', 'hane_id', 'user_id', 'created_at', 'updated_at', 'olusturan_id'])
function alanlar(k: SilinenKaydi): [string, string][] {
  return Object.entries(k.icerik ?? {})
    .filter(([ad, d]) => !GIZLI.has(ad) && d !== null && d !== '')
    .map(([ad, d]) => [ad.split('_').join(' '), String(d)])
}
function altSatir(a: Record<string, unknown>): string {
  return Object.values(a)
    .filter((d) => d !== null && d !== '')
    .join(' · ')
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <p class="text-sm text-surface-600">
      Silinen her kayıt saklanır; kaydı kimin ne zaman yaptığı ve kimin ne zaman sildiği görünür. Bu
      liste yalnızca görüntülenir.
    </p>

    <div
      v-if="cipler.length > 1"
      class="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1"
      role="group"
      aria-label="Tür süzgeci"
      data-test="silinen-ciplar"
    >
      <button
        v-for="c in cipler"
        :key="c.tur ?? 'hepsi'"
        type="button"
        class="shrink-0 rounded-full px-3 py-1.5 text-xs font-semibold ring-1 transition-all active:scale-95"
        :class="
          secili === c.tur
            ? 'bg-primary-50 text-primary-800 ring-primary-400'
            : 'bg-surface-0 text-surface-700 ring-surface-200'
        "
        :aria-pressed="secili === c.tur"
        data-test="silinen-cip"
        @click="sec(c.tur)"
      >
        {{ c.ad }} <span class="text-surface-500">{{ c.sayi }}</span>
      </button>
    </div>

    <Skeleton v-if="!yuklendi" height="12rem" border-radius="1.5rem" />
    <section v-else class="kart p-4 sm:p-5" data-test="silinen-liste">
      <BosDurum
        v-if="!kayitlar.length"
        kucuk
        baslik="Silinen kayıt yok"
        aciklama="Bir kaydı sildiğinizde burada saklanır."
        ikon="pi pi-trash"
      />
      <ul v-else class="divide-y divide-surface-100">
        <li v-for="k in kayitlar" :key="k.id" data-test="silinen-satir">
          <button type="button" class="flex w-full items-start gap-3 py-3 text-left" @click="ac(k)">
            <span class="min-w-0 flex-1">
              <span class="block text-xs font-semibold text-surface-500">{{ k.tur_etiketi }}</span>
              <span
                class="block truncate font-semibold text-surface-500 line-through decoration-surface-400"
                data-test="silinen-baslik"
                >{{ k.baslik }}</span
              >
              <span class="mt-0.5 block text-xs text-surface-500">
                Yapan: {{ k.olusturan_ad ?? '—' }}
                <template v-if="k.olusturuldu_at"> · {{ tarihSaat(k.olusturuldu_at) }}</template>
              </span>
              <span class="block text-xs text-surface-500" data-test="silinen-silen">
                Silen: {{ k.silen_ad }} · {{ tarihSaat(k.silindi_at) }}
                <template v-if="k.neden"> · {{ k.neden }}</template>
              </span>
            </span>
            <span
              v-if="k.tutar !== null"
              class="font-semibold whitespace-nowrap text-surface-500 tabular-nums line-through decoration-surface-400"
              >{{ tl(k.tutar) }}</span
            >
          </button>
          <div
            v-if="acik === k.id"
            class="mb-3 rounded-2xl bg-surface-50 p-3 text-xs text-surface-600"
            data-test="silinen-ayrinti"
          >
            <Skeleton v-if="!ayrinti" height="3rem" />
            <template v-else>
              <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                <template v-for="[ad, d] in alanlar(ayrinti)" :key="ad">
                  <dt class="font-semibold capitalize">{{ ad }}</dt>
                  <dd class="min-w-0 break-words line-through decoration-surface-300">{{ d }}</dd>
                </template>
              </dl>
              <div v-if="ayrinti.alt?.length" class="mt-2">
                <p class="font-semibold">Bağlı kayıtlar ({{ ayrinti.alt.length }})</p>
                <ul class="mt-1 max-h-48 overflow-y-auto">
                  <li
                    v-for="(a, i) in ayrinti.alt"
                    :key="i"
                    class="line-through decoration-surface-300"
                  >
                    {{ altSatir(a) }}
                  </li>
                </ul>
              </div>
            </template>
          </div>
        </li>
      </ul>
      <div v-if="sonraki" class="mt-3 flex justify-center">
        <Button
          label="Daha fazla yükle"
          severity="secondary"
          outlined
          size="small"
          :loading="yukleniyor"
          data-test="silinen-daha"
          @click="yukle(false)"
        />
      </div>
      <p v-if="kayitlar.length" class="mt-2 text-center text-xs text-surface-500">
        {{ toplam }} kayıt
      </p>
    </section>
  </div>
</template>
