<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import BosDurum from '@/components/BosDurum.vue'
import AySecici from '@/components/aile/AySecici.vue'
import GelirFormu from '@/components/aile/GelirFormu.vue'
import GelirKaynagiFormu from '@/components/aile/GelirKaynagiFormu.vue'
import {
  aileApi,
  GELIR_DUZENLERI,
  GELIR_SIKLIKLARI,
  GELIR_TURLERI,
  type BeklenenGelir,
  type Gelir,
  type GelirGirdisi,
  type GelirKaynagi,
  type GelirOzeti,
} from '@/api/aile'
import { useSonIstek } from '@/composables/useSonIstek'
import { useAileStore } from '@/stores/aile'
import { bugun, tarih, tl } from '@/utils/bicim'
import { buAy, donemEtiketi } from '@/utils/donem'

/**
 * Gelirler: seçilen ayın gerçekleşen gelirleri kişi kişi (tür ve kaynakla), düzenli / düzensiz ayrımı; beklenen
 * (henüz alınmamış) düzenli gelirler ayrı ve toplama katılmadan; gelir kaynakları.
 */
const aile = useAileStore()
const donem = ref(buAy())
const gelirler = ref<Gelir[] | null>(null)
const ozet = ref<GelirOzeti | null>(null)
const kaynaklar = ref<GelirKaynagi[]>([])
const sonIstek = useSonIstek()

const gelirFormuAcik = ref(false)
const duzenlenen = ref<Gelir | null>(null)
const varsayilan = ref<Partial<GelirGirdisi> | undefined>()
const kaynakFormuAcik = ref(false)
const duzenlenenKaynak = ref<GelirKaynagi | null>(null)

async function yukle(): Promise<void> {
  const veri = await sonIstek(() =>
    Promise.all([aileApi.gelirler({ donem: donem.value }), aileApi.kaynaklar()]),
  )
  if (veri === undefined) return
  gelirler.value = veri[0].gelirler
  ozet.value = veri[0].ozet
  kaynaklar.value = veri[1]
}

onMounted(async () => {
  await aile.yukle()
  await yukle()
})
watch(donem, () => {
  // Önceki ayın toplamı yeni ayın adıyla görünmesin
  gelirler.value = null
  ozet.value = null
  void yukle()
})

const kisiler = computed(() =>
  (ozet.value?.kisiler ?? []).map((k) => ({
    ...k,
    gelirler: (gelirler.value ?? []).filter((g) => g.uye_id === k.uye.id),
    beklenen: (ozet.value?.beklenenler ?? []).filter((b) => b.kaynak.uye_id === k.uye.id),
  })),
)

function gelirEkle(on?: Partial<GelirGirdisi>): void {
  duzenlenen.value = null
  varsayilan.value = { donem: donem.value, ...on }
  gelirFormuAcik.value = true
}

function gelirDuzenle(g: Gelir): void {
  duzenlenen.value = g
  gelirFormuAcik.value = true
}

/** Beklenen gelir alındı: kaynağın beklenen tutarıyla, o dönem için form açılır */
function alindi(b: BeklenenGelir): void {
  gelirEkle({
    uye_id: b.kaynak.uye_id,
    gelir_kaynagi_id: b.kaynak.id,
    tur: b.kaynak.tur,
    duzen: b.kaynak.duzen,
    tutar: b.kaynak.beklenen_tutar,
    para_birimi: b.kaynak.para_birimi,
    donem: b.donem,
    // Beklenen gün geçtiyse o gün, gelmediyse bugün
    ...(b.tarih && b.tarih <= bugun() ? { tarih: b.tarih } : {}),
  })
}

function kaynakDuzenle(k: GelirKaynagi | null): void {
  duzenlenenKaynak.value = k
  kaynakFormuAcik.value = true
}

function kaynakAdi(id: number | null): string {
  return kaynaklar.value.find((k) => k.id === id)?.ad ?? ''
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <AySecici v-model="donem" :ileri-sinir="null" />
      <div class="flex flex-wrap gap-2">
        <Button label="Gelir ekle" icon="pi pi-plus" @click="gelirEkle()" />
        <Button
          label="Düzenli gelir tanımla"
          icon="pi pi-replay"
          severity="secondary"
          outlined
          @click="kaynakDuzenle(null)"
        />
      </div>
    </div>

    <!-- Hane toplamı -->
    <section v-if="ozet" class="kart grid grid-cols-2 gap-3 p-4 sm:grid-cols-4 sm:p-5">
      <div class="col-span-2 sm:col-span-1">
        <p class="text-xs text-surface-500">{{ donemEtiketi(donem) }} hane geliri</p>
        <p class="text-2xl font-bold text-surface-900 tabular-nums" data-test="hane-geliri">
          {{ tl(ozet.toplam) }}
        </p>
      </div>
      <div class="rounded-2xl bg-surface-50 p-3">
        <p class="text-xs text-surface-500">Düzenli</p>
        <p class="font-semibold tabular-nums">{{ tl(ozet.duzenli) }}</p>
      </div>
      <div class="rounded-2xl bg-surface-50 p-3">
        <p class="text-xs text-surface-500">Düzensiz + tek seferlik</p>
        <p class="font-semibold tabular-nums">{{ tl(ozet.duzensiz + ozet.tek_seferlik) }}</p>
      </div>
      <div class="col-span-2 rounded-2xl bg-amber-50 p-3 sm:col-span-1">
        <p class="text-xs text-amber-800">Beklenen (alınmadı)</p>
        <p class="font-semibold text-amber-900">
          {{ ozet.beklenenler.length }} gelir · toplama katılmaz
        </p>
      </div>
    </section>

    <div v-if="gelirler === null" class="flex flex-col gap-3">
      <Skeleton v-for="n in 3" :key="n" height="5rem" border-radius="1.5rem" />
    </div>
    <template v-else>
      <section
        v-for="k in kisiler"
        :key="k.uye.id"
        class="kart p-4 sm:p-5"
        :data-test="`kisi-${k.uye.id}`"
      >
        <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
          <h2 class="text-base font-bold text-surface-900">{{ k.uye.ad }}</h2>
          <p class="text-sm font-semibold text-surface-900 tabular-nums">{{ tl(k.toplam) }}</p>
        </div>
        <p v-if="k.turler.length" class="mb-2 text-xs text-surface-500">
          <span v-for="(t, i) in k.turler" :key="t.tur"
            >{{ i ? ' · ' : '' }}{{ t.etiket }} {{ tl(t.toplam) }}</span
          >
        </p>
        <ul class="divide-y divide-surface-100">
          <li v-for="g in k.gelirler" :key="g.id">
            <button
              type="button"
              class="flex w-full items-center gap-3 py-3 text-left"
              @click="gelirDuzenle(g)"
            >
              <span class="min-w-0 flex-1">
                <span class="block truncate font-semibold text-surface-900">
                  {{ g.aciklama || kaynakAdi(g.gelir_kaynagi_id) || GELIR_TURLERI[g.tur] }}
                </span>
                <span class="block text-xs text-surface-500">
                  {{ GELIR_TURLERI[g.tur] }} · {{ GELIR_DUZENLERI[g.duzen] }} · {{ tarih(g.tarih) }}
                  <template v-if="g.para_birimi !== 'TRY'">
                    · {{ g.tutar.toLocaleString('tr-TR') }} {{ g.para_birimi }}</template
                  >
                </span>
              </span>
              <span class="font-semibold whitespace-nowrap text-emerald-700 tabular-nums">{{
                tl(g.tl_tutar)
              }}</span>
            </button>
          </li>
          <li
            v-for="b in k.beklenen"
            :key="`b-${b.kaynak.id}`"
            class="flex items-center gap-3 py-3"
          >
            <span class="min-w-0 flex-1">
              <span class="block truncate font-semibold text-surface-600">{{ b.kaynak.ad }}</span>
              <span class="block text-xs text-amber-700">
                Beklenen{{ b.tarih ? ` · ${tarih(b.tarih)}` : '' }} · henüz alınmadı, toplama
                katılmaz
              </span>
            </span>
            <span class="text-sm whitespace-nowrap text-surface-400 tabular-nums">{{
              tl(b.kaynak.beklenen_tutar)
            }}</span>
            <Button label="Alındı" size="small" outlined @click="alindi(b)" />
          </li>
        </ul>
        <p v-if="!k.gelirler.length && !k.beklenen.length" class="py-3 text-sm text-surface-500">
          Bu ay gelir yok.
        </p>
      </section>
    </template>

    <!-- Kaynaklar -->
    <section class="kart p-4 sm:p-5">
      <h2 class="bolum-etiketi mb-2">Gelir kaynakları</h2>
      <BosDurum
        v-if="kaynaklar.length === 0"
        kucuk
        baslik="Kaynak yok"
        aciklama="Maaş, kira gibi düzenli gelirleri tanımlayın; her ay beklenen olarak görünür."
        ikon="pi pi-replay"
      />
      <ul v-else class="divide-y divide-surface-100">
        <li v-for="k in kaynaklar" :key="k.id">
          <button
            type="button"
            class="flex w-full items-center gap-3 py-3 text-left"
            @click="kaynakDuzenle(k)"
          >
            <span class="min-w-0 flex-1">
              <span
                class="block truncate font-semibold"
                :class="k.aktif ? 'text-surface-900' : 'text-surface-400'"
              >
                {{ k.ad }}
              </span>
              <span class="block text-xs text-surface-500">
                {{ aile.uyeAdi(k.uye_id) }} · {{ GELIR_TURLERI[k.tur] }} ·
                {{ k.siklik ? GELIR_SIKLIKLARI[k.siklik] : GELIR_DUZENLERI[k.duzen] }}
                <template v-if="!k.aktif"> · pasif</template>
              </span>
            </span>
            <span class="text-sm whitespace-nowrap text-surface-700 tabular-nums">{{
              tl(k.beklenen_tutar)
            }}</span>
          </button>
        </li>
      </ul>
    </section>

    <GelirFormu
      v-model:visible="gelirFormuAcik"
      :gelir="duzenlenen"
      :varsayilan="varsayilan"
      :kaynaklar="kaynaklar"
      @kaydedildi="yukle"
    />
    <GelirKaynagiFormu
      v-model:visible="kaynakFormuAcik"
      :kaynak="duzenlenenKaynak"
      @kaydedildi="yukle"
    />
  </div>
</template>
