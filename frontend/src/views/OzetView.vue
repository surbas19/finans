<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import BosDurum from '@/components/BosDurum.vue'
import DagilimKarti from '@/components/DagilimKarti.vue'
import DegisimEtiketi from '@/components/DegisimEtiketi.vue'
import IslemFormu from '@/components/IslemFormu.vue'
import PerformansGrafigi from '@/components/PerformansGrafigi.vue'
import PozisyonListesi from '@/components/PozisyonListesi.vue'
import { finansApi, HESAP_TURLERI, type HesapOzeti, type Ozet } from '@/api/finans'
import { useSonIstek } from '@/composables/useSonIstek'
import { neZaman, tl } from '@/utils/bicim'

/**
 * Ana sayfa: toplam değer, kâr / zarar, bugünkü değişim; performans grafiği; hesap kartları; dağılım; varlıklar.
 * Üstteki süzgeçle tek hesaba bakılır (varlıklar o hesaptakiler olur).
 */
const ozet = ref<Ozet | null>(null)
/** Süzgeç için bütün hesaplar (hesap seçiliyken özet yalnızca o hesabı döndürür) */
const tumHesaplar = ref<HesapOzeti[]>([])
const secilenHesap = ref<number | null>(null)
const islemAcik = ref(false)
/** İşlem kaydedilince performans grafiği yenilenir */
const yenile = ref(0)
const sonIstek = useSonIstek()

async function yukle(): Promise<void> {
  const veri = await sonIstek(() => finansApi.ozet(secilenHesap.value))
  if (veri === undefined) return
  ozet.value = veri
  if (secilenHesap.value === null) tumHesaplar.value = veri.hesaplar
}

function sec(id: number | null): void {
  secilenHesap.value = id
  void yukle()
}

onMounted(yukle)

const pozisyonlar = computed(
  () => (secilenHesap.value === null ? ozet.value?.varliklar : ozet.value?.pozisyonlar) ?? [],
)

function islemKaydedildi(): void {
  yenile.value++
  void yukle()
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <!-- Hesap süzgeci -->
    <div v-if="tumHesaplar.length > 1" class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1">
      <button
        type="button"
        class="shrink-0 rounded-full px-3.5 py-1.5 text-sm font-semibold transition-colors"
        :class="
          secilenHesap === null
            ? 'bg-primary-600 text-white'
            : 'bg-white text-surface-600 ring-1 ring-surface-200'
        "
        @click="sec(null)"
      >
        Tümü
      </button>
      <button
        v-for="h in tumHesaplar"
        :key="h.id"
        type="button"
        class="shrink-0 rounded-full px-3.5 py-1.5 text-sm font-semibold transition-colors"
        :class="
          secilenHesap === h.id
            ? 'bg-primary-600 text-white'
            : 'bg-white text-surface-600 ring-1 ring-surface-200'
        "
        @click="sec(h.id)"
      >
        {{ h.ad }}
      </button>
    </div>

    <!-- Toplam -->
    <section class="kart p-5 sm:p-6">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p class="bolum-etiketi">Toplam değer</p>
          <Skeleton v-if="!ozet" width="12rem" height="2.5rem" class="mt-2" />
          <p
            v-else
            class="sayi-giris mt-1 text-3xl font-bold tracking-tight text-surface-900 tabular-nums sm:text-4xl"
          >
            {{ tl(ozet.toplam.deger) }}
          </p>
          <p v-if="ozet" class="mt-1 flex flex-wrap items-center gap-x-2 text-sm text-surface-500">
            Bugün <DegisimEtiketi :tutar="ozet.toplam.gunluk_degisim" />
          </p>
        </div>
        <Button label="İşlem ekle" icon="pi pi-plus" @click="islemAcik = true" />
      </div>
      <div v-if="ozet" class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3">
        <div class="rounded-2xl bg-surface-50 p-3">
          <p class="text-xs text-surface-500">Kâr / zarar</p>
          <DegisimEtiketi :tutar="ozet.toplam.kz" :yuzde="ozet.toplam.kz_yuzde" />
        </div>
        <div class="rounded-2xl bg-surface-50 p-3">
          <p class="text-xs text-surface-500">Maliyet</p>
          <p class="text-sm font-semibold text-surface-900 tabular-nums">
            {{ tl(ozet.toplam.maliyet) }}
          </p>
        </div>
        <div class="rounded-2xl bg-surface-50 p-3">
          <p class="text-xs text-surface-500">Gerçekleşen (satışlar)</p>
          <DegisimEtiketi :tutar="ozet.toplam.gerceklesen_kz" />
        </div>
      </div>
      <div v-if="ozet" class="mt-3 flex flex-col gap-2">
        <Message v-if="ozet.toplam.maliyeti_eksik" severity="info" size="small">
          Maliyeti bilinmeyen varlıklar var: kâr / zarar yalnızca maliyeti bilinen kısım için
          hesaplanır.
        </Message>
        <Message v-if="ozet.toplam.fiyatsiz > 0" severity="warn" size="small">
          {{ ozet.toplam.fiyatsiz }} varlığın fiyatı yok; toplam değere katılmadı.
        </Message>
        <p class="text-xs text-surface-400">
          Fiyatlar {{ neZaman(ozet.son_guncelleme) }} güncellendi · BIST ~15 dk gecikmeli
        </p>
      </div>
    </section>

    <!-- Performans -->
    <section v-if="ozet && ozet.hesaplar.length > 0" class="kart p-4 sm:p-5">
      <h2 class="bolum-etiketi mb-2">
        {{ secilenHesap === null ? 'Performans (bütün hesaplar)' : 'Performans' }}
      </h2>
      <PerformansGrafigi :hesap-id="secilenHesap" :yenile="yenile" />
    </section>

    <!-- Hesaplar -->
    <section v-if="ozet && secilenHesap === null && ozet.hesaplar.length > 0">
      <div class="mb-2 flex items-center justify-between">
        <h2 class="bolum-etiketi">Hesaplar</h2>
        <RouterLink :to="{ name: 'hesaplar' }" class="text-sm font-semibold text-primary-600"
          >Tümü</RouterLink
        >
      </div>
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <RouterLink
          v-for="h in ozet.hesaplar"
          :key="h.id"
          :to="{ name: 'hesap', params: { id: h.id } }"
          class="kart etkilesimli-kart flex items-center gap-3 p-4"
        >
          <span
            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl text-white"
            :style="{ background: `var(--p-${h.renk ?? 'teal'}-500)` }"
          >
            <i :class="HESAP_TURLERI[h.tur].ikon" />
          </span>
          <span class="min-w-0 flex-1">
            <span class="block truncate font-semibold text-surface-900">{{ h.ad }}</span>
            <span class="block text-sm font-semibold text-surface-700 tabular-nums">{{
              tl(h.deger)
            }}</span>
          </span>
          <DegisimEtiketi :tutar="h.kz" :yuzde="h.kz_yuzde" kucuk class="flex-col !items-end" />
        </RouterLink>
      </div>
    </section>

    <div class="grid gap-4 lg:grid-cols-5">
      <!-- Varlıklar -->
      <section class="kart p-4 sm:p-5 lg:col-span-3">
        <h2 class="bolum-etiketi mb-2">
          {{ secilenHesap === null ? 'Varlıklarım (bütün hesaplar)' : 'Bu hesaptaki varlıklar' }}
        </h2>
        <div v-if="!ozet" class="flex flex-col gap-3">
          <Skeleton v-for="n in 4" :key="n" height="3rem" />
        </div>
        <BosDurum
          v-else-if="pozisyonlar.length === 0"
          kucuk
          baslik="Henüz varlık yok"
          aciklama="Önce bir hesap ekleyin, sonra alış ya da elde olan varlığınızı girin."
          ikon="pi pi-wallet"
        >
          <Button
            v-if="ozet.hesaplar.length === 0"
            label="Hesap ekle"
            icon="pi pi-plus"
            size="small"
            @click="$router.push({ name: 'hesaplar' })"
          />
          <Button
            v-else
            label="İşlem ekle"
            icon="pi pi-plus"
            size="small"
            @click="islemAcik = true"
          />
        </BosDurum>
        <PozisyonListesi v-else :pozisyonlar="pozisyonlar" />
      </section>

      <!-- Dağılım -->
      <DagilimKarti
        v-if="ozet && ozet.dagilim.length > 0"
        :dagilim="ozet.dagilim"
        :toplam-deger="ozet.toplam.deger"
        class="lg:col-span-2"
      />
    </div>

    <IslemFormu
      v-model:visible="islemAcik"
      :varsayilan="secilenHesap ? { hesap_id: secilenHesap } : undefined"
      @kaydedildi="islemKaydedildi"
    />
  </div>
</template>
