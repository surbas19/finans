<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import BosDurum from '@/components/BosDurum.vue'
import KisiEtiketi from '@/components/KisiEtiketi.vue'
import AySecici from '@/components/aile/AySecici.vue'
import ElleEkstreDialog from '@/components/aile/ElleEkstreDialog.vue'
import GiderFormu from '@/components/aile/GiderFormu.vue'
import GiderKaynagiFormu from '@/components/aile/GiderKaynagiFormu.vue'
import {
  aileApi,
  GELIR_SIKLIKLARI,
  type AylikOzet,
  type BeklenenGider,
  type DuzenliGider,
  type DuzenliGiderGirdisi,
  type GiderKaynagi,
} from '@/api/aile'
import { useSonIstek } from '@/composables/useSonIstek'
import { useAileStore } from '@/stores/aile'
import { bugun, tarih, tl } from '@/utils/bicim'
import { buAy, donemEtiketi } from '@/utils/donem'

/**
 * Giderler: seçilen ayın gideri tek yerde. Kredi kartları (ekstre harcamaları, kart başına toplam ve son ödeme),
 * düzenli ödemeler (eğitim, bakıcı… beklenenler toplama katılmadan) ve kartsız tek seferlik giderler.
 * Kartla ödenen gider kart ekstresine girer; aynı ödeme ayrıca girilmez.
 */
const aile = useAileStore()
const donem = ref(buAy())
const ozet = ref<AylikOzet | null>(null)
const kaynaklar = ref<GiderKaynagi[]>([])
const sonIstek = useSonIstek()

const giderFormuAcik = ref(false)
const duzenlenen = ref<DuzenliGider | null>(null)
const varsayilan = ref<Partial<DuzenliGiderGirdisi> | undefined>()
const kaynakFormuAcik = ref(false)
const duzenlenenKaynak = ref<GiderKaynagi | null>(null)
const elleAcik = ref(false)

async function yukle(): Promise<void> {
  const veri = await sonIstek(() =>
    Promise.all([aileApi.ozet(donem.value), aileApi.giderKaynaklari()]),
  )
  if (veri === undefined) return
  ozet.value = veri[0]
  kaynaklar.value = veri[1]
}

onMounted(async () => {
  await aile.yukle()
  await yukle()
})
watch(donem, () => {
  ozet.value = null
  void yukle()
})

/** Kart gideri = kesimi bu aya düşen onaylı ekstrenin dönem borcu (sunucu hesaplar) */
const kartlar = computed(() => ozet.value?.gider.kartlar ?? [])

function giderEkle(on?: Partial<DuzenliGiderGirdisi>): void {
  duzenlenen.value = null
  varsayilan.value = { donem: donem.value, ...on }
  giderFormuAcik.value = true
}

function giderDuzenle(g: DuzenliGider): void {
  duzenlenen.value = g
  giderFormuAcik.value = true
}

/** Beklenen ödeme yapıldı: kaynağın beklenen tutarıyla, o dönem için form açılır */
function odendi(b: BeklenenGider): void {
  giderEkle({
    gider_kaynagi_id: b.kaynak.id,
    kategori_id: b.kaynak.kategori_id,
    aciklama: b.kaynak.ad,
    tutar: b.kaynak.beklenen_tutar,
    donem: b.donem,
    // Ödeme günü geçtiyse o gün, gelmediyse bugün
    ...(b.tarih && b.tarih <= bugun() ? { tarih: b.tarih } : {}),
  })
}

function kaynakDuzenle(k: GiderKaynagi | null): void {
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
        <Button label="Gider ekle" icon="pi pi-plus" @click="giderEkle()" />
        <Button
          label="Düzenli ödeme tanımla"
          icon="pi pi-replay"
          severity="secondary"
          outlined
          @click="kaynakDuzenle(null)"
        />
      </div>
    </div>

    <Skeleton v-if="!ozet" height="10rem" border-radius="1.5rem" />
    <template v-else>
      <section class="kart grid grid-cols-3 gap-2 p-4 sm:p-5">
        <div class="col-span-3 sm:col-span-1">
          <p class="text-xs text-surface-500">{{ donemEtiketi(donem) }} gideri</p>
          <p class="text-2xl font-bold text-surface-900 tabular-nums" data-test="gider-toplam">
            {{ tl(ozet.gider.toplam) }}
          </p>
        </div>
        <div class="rounded-2xl bg-surface-50 p-3">
          <p class="text-xs text-surface-500">Kartlar</p>
          <p class="font-semibold tabular-nums">
            {{ tl(ozet.gider.kart_harcamasi) }}
          </p>
        </div>
        <div class="rounded-2xl bg-surface-50 p-3">
          <p class="text-xs text-surface-500">Düzenli / diğer</p>
          <p class="font-semibold tabular-nums" data-test="gider-duzenli">
            {{ tl(ozet.gider.duzenli.toplam + ozet.gider.kartsiz_faturalar) }}
          </p>
        </div>
      </section>

      <!-- Kredi kartları -->
      <section class="kart p-4 sm:p-5" data-test="gider-kartlar">
        <div class="mb-2 flex items-baseline justify-between gap-2">
          <h2 class="bolum-etiketi">Kredi kartları</h2>
          <div class="flex gap-3 text-sm font-semibold text-primary-600">
            <button type="button" @click="elleAcik = true">Tutarla ekstre gir</button>
            <RouterLink :to="{ name: 'aile-ekstreler' }">Ekstreler</RouterLink>
          </div>
        </div>
        <BosDurum
          v-if="!kartlar.length"
          kucuk
          baslik="Bu ay kart harcaması yok"
          aciklama="Ekstre PDF'i yükleyin ya da ekstreyi yalnızca tutarla girin."
          ikon="pi pi-credit-card"
        />
        <ul v-else class="divide-y divide-surface-100">
          <li v-for="k in kartlar" :key="k.kart_id" class="flex items-center gap-3 py-3">
            <span class="min-w-0 flex-1">
              <RouterLink
                :to="{ name: 'aile-kart', params: { id: k.kart_id } }"
                class="block truncate font-semibold text-surface-900"
                >{{ k.ad }}</RouterLink
              >
              <span class="block text-xs text-surface-500">
                <KisiEtiketi :kisi="aile.uye(k.sahip_uye_id)" />
                <template v-if="k.ekstre">
                  · ekstre
                  <template v-if="k.ekstre.son_odeme_tarihi">
                    · son ödeme {{ tarih(k.ekstre.son_odeme_tarihi) }}</template
                  >
                </template>
              </span>
            </span>
            <span class="font-semibold tabular-nums">{{ tl(k.toplam) }}</span>
          </li>
        </ul>
        <p class="mt-2 text-xs text-surface-500">
          Kart gideri ekstre borcunun tamamıdır (kesim ayına yazılır); ekstresi gelmemiş elle
          girilen harcamalar ayrıca eklenir.
        </p>
      </section>

      <!-- Düzenli ödemeler -->
      <section class="kart p-4 sm:p-5" data-test="gider-duzenli-liste">
        <h2 class="bolum-etiketi mb-2">Düzenli ödemeler ve diğer giderler</h2>
        <ul class="divide-y divide-surface-100">
          <li v-for="g in ozet.gider.duzenli.kalemler" :key="g.id">
            <button
              type="button"
              class="flex w-full items-center gap-3 py-3 text-left"
              @click="giderDuzenle(g)"
            >
              <span class="min-w-0 flex-1">
                <span class="block truncate font-semibold text-surface-900">{{ g.aciklama }}</span>
                <span class="block text-xs text-surface-500">
                  {{ aile.kategori(g.kategori_id)?.ad ?? 'Kategorisiz' }} ·
                  {{ tarih(g.tarih) }}
                  <template v-if="g.gider_kaynagi_id">
                    · {{ kaynakAdi(g.gider_kaynagi_id) || 'düzenli' }}</template
                  >
                </span>
              </span>
              <span class="font-semibold whitespace-nowrap tabular-nums">{{ tl(g.tutar) }}</span>
            </button>
          </li>
          <li
            v-for="b in ozet.gider.beklenenler"
            :key="`b-${b.kaynak.id}`"
            class="flex items-center gap-3 py-3"
          >
            <span class="min-w-0 flex-1">
              <span class="block truncate font-semibold text-surface-600">{{ b.kaynak.ad }}</span>
              <span class="block text-xs text-amber-700">
                Beklenen{{ b.tarih ? ` · ${tarih(b.tarih)}` : '' }} · henüz ödenmedi, toplama
                katılmaz
              </span>
            </span>
            <span class="text-sm whitespace-nowrap text-surface-400 tabular-nums">{{
              tl(b.kaynak.beklenen_tutar)
            }}</span>
            <Button label="Ödendi" size="small" outlined @click="odendi(b)" />
          </li>
        </ul>
        <p
          v-if="!ozet.gider.duzenli.kalemler.length && !ozet.gider.beklenenler.length"
          class="py-3 text-sm text-surface-500"
        >
          Bu ay düzenli ödeme ya da tek seferlik gider yok.
        </p>
        <p v-if="ozet.gider.kartsiz_faturalar > 0" class="mt-2 text-xs text-surface-500">
          Kartsız ödenen faturalar {{ tl(ozet.gider.kartsiz_faturalar) }} de giderde (Faturalar).
        </p>
      </section>

      <!-- Tanımlı düzenli ödemeler -->
      <section class="kart p-4 sm:p-5">
        <h2 class="bolum-etiketi mb-2">Düzenli ödeme tanımları</h2>
        <BosDurum
          v-if="kaynaklar.length === 0"
          kucuk
          baslik="Tanım yok"
          aciklama="Eğitim, bakıcı, aidat gibi düzenli ödemeleri tanımlayın; her ay beklenen olarak görünür."
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
                  >{{ k.ad }}</span
                >
                <span class="block text-xs text-surface-500">
                  {{ GELIR_SIKLIKLARI[k.siklik] }}
                  <template v-if="k.uye_id"> · <KisiEtiketi :kisi="aile.uye(k.uye_id)" /></template>
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
    </template>

    <GiderFormu
      v-model:visible="giderFormuAcik"
      :gider="duzenlenen"
      :varsayilan="varsayilan"
      :kaynaklar="kaynaklar"
      @kaydedildi="yukle"
    />
    <GiderKaynagiFormu
      v-model:visible="kaynakFormuAcik"
      :kaynak="duzenlenenKaynak"
      @kaydedildi="yukle"
    />
    <ElleEkstreDialog v-model:visible="elleAcik" />
  </div>
</template>
