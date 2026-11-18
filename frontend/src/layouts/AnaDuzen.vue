<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AltSekmeler from '@/components/AltSekmeler.vue'
import HizliIslemler from '@/components/HizliIslemler.vue'
import KisiResmi from '@/components/KisiResmi.vue'
import SayfaBasinaDon from '@/components/SayfaBasinaDon.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import { useOzelBaslik } from '@/composables/useSayfaBasligi'
import type { Bolum } from '@/router'
import { useAuthStore } from '@/stores/auth'

/**
 * Ana düzen (mobile-first).
 * - Üst çubuk: telefonda bölümün adı; detay sayfasında ‹ geri + sayfanın adı (`useSayfaBasligi`). Masaüstünde logo,
 *   bölüm menüsü, ⚡ hızlı işlem ve kişi.
 * - Telefonda alt çubuk: Portföy · Bütçe · ( + hızlı işlem ) · İzleme · Menü (Profil, Yönetim, Çıkış).
 * - Portföy bölümünde (Özet, Hesaplar, İşlemler) üstte alt sekmeler; Bütçe'ninkiler AileDuzeni'nde.
 */
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const ozelBaslik = useOzelBaslik()

const BOLUM_ADI: Record<Bolum, string> = {
  portfoy: 'Portföy',
  butce: 'Bütçe',
  izleme: 'İzleme',
  profil: 'Profil',
  yonetim: 'Yönetim',
}

const menu = computed(() => [
  { bolum: 'portfoy' as const, ad: 'ozet', etiket: 'Portföy', ikon: 'pi pi-chart-pie' },
  { bolum: 'butce' as const, ad: 'aile', etiket: 'Bütçe', ikon: 'pi pi-calculator' },
  { bolum: 'izleme' as const, ad: 'izleme', etiket: 'İzleme', ikon: 'pi pi-eye' },
  ...(auth.yonetici
    ? [{ bolum: 'yonetim' as const, ad: 'yonetim', etiket: 'Yönetim', ikon: 'pi pi-cog' }]
    : []),
])

const bolum = computed(() => route.meta.bolum)
const detay = computed(() => !!route.meta.geri)
/** Telefondaki üst çubuk başlığı: detayda sayfanın adı, değilse bölümün adı */
const baslik = computed(() =>
  detay.value
    ? (ozelBaslik.value ?? route.meta.baslik ?? '')
    : bolum.value
      ? BOLUM_ADI[bolum.value]
      : (route.meta.baslik ?? 'Finans'),
)
/** Masaüstünde detay sayfasının üstündeki "‹ Hesaplar" bağlantısının adı */
const geriAdi = computed(() =>
  route.meta.geri ? (router.resolve({ name: route.meta.geri }).meta.baslik ?? 'Geri') : '',
)

function geriGit(): void {
  // Uygulama içinden gelindiyse aynı yere (kaydırma ve süzgeçler korunur); doğrudan açıldıysa üst sayfaya
  if (window.history.state?.back) router.back()
  else if (route.meta.geri) void router.push({ name: route.meta.geri })
}

const PORTFOY_SEKMELERI = [
  { ad: 'ozet', etiket: 'Özet' },
  { ad: 'hesaplar', etiket: 'Hesaplar' },
  { ad: 'islemler', etiket: 'İşlemler' },
] as const
const portfoySekmeleri = computed(() => bolum.value === 'portfoy' && !detay.value)

/** Hızlı işlemden kayıt yapılınca artar: açık sayfa yeniden kurulup güncel veriyi yükler */
const yenileme = ref(0)
const hizli = ref<InstanceType<typeof HizliIslemler> | null>(null)
const menuAcik = ref(false)

async function cikis(): Promise<void> {
  menuAcik.value = false
  await auth.cikis()
  await router.push({ name: 'giris' })
}

function menudenGit(ad: string): void {
  menuAcik.value = false
  void router.push({ name: ad })
}
</script>

<template>
  <div class="flex min-h-dvh flex-col">
    <header
      class="sticky top-0 z-20 border-b border-surface-200/70 bg-surface-0/85 pt-[env(safe-area-inset-top)] backdrop-blur supports-[backdrop-filter]:bg-surface-0/70"
    >
      <div class="mx-auto flex h-14 max-w-5xl items-center gap-2 px-4">
        <!-- Telefon: geri + sayfa adı ya da bölüm adı -->
        <div class="flex min-w-0 flex-1 items-center gap-1 md:hidden">
          <button
            v-if="detay"
            type="button"
            class="-ml-2 flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-primary-700 hover:bg-surface-100"
            aria-label="Geri"
            data-test="ust-geri"
            @click="geriGit"
          >
            <i class="pi pi-chevron-left text-lg" />
          </button>
          <h1
            class="truncate font-bold text-surface-900"
            :class="detay ? 'text-base' : 'text-xl tracking-tight'"
            data-test="ust-baslik"
          >
            {{ baslik }}
          </h1>
        </div>

        <!-- Masaüstü: logo ve bölüm menüsü -->
        <RouterLink
          :to="{ name: 'ozet' }"
          class="hidden items-center gap-2 font-bold text-surface-900 md:flex"
        >
          <img src="/favicon.svg" alt="" class="h-7 w-7" />
          <span>Finans</span>
        </RouterLink>
        <nav class="ml-4 hidden items-center gap-1 md:flex" aria-label="Ana menü">
          <RouterLink
            v-for="m in menu"
            :key="m.ad"
            :to="{ name: m.ad }"
            class="rounded-full px-3 py-1.5 text-sm font-semibold transition-colors"
            :class="
              bolum === m.bolum
                ? 'bg-primary-50 text-primary-700'
                : 'text-surface-600 hover:bg-surface-100'
            "
            :aria-current="bolum === m.bolum ? 'page' : undefined"
          >
            <i :class="m.ikon" class="mr-1.5 text-xs" />{{ m.etiket }}
          </RouterLink>
        </nav>

        <div class="ml-auto flex items-center gap-1">
          <button
            type="button"
            class="hidden h-9 items-center gap-1.5 rounded-full bg-primary-600 px-3.5 text-sm font-semibold text-white transition-colors hover:bg-primary-700 md:flex"
            aria-haspopup="true"
            data-test="ust-hizli"
            @click="hizli?.ac($event)"
          >
            <i class="pi pi-bolt text-xs" /> Hızlı işlem
          </button>
          <RouterLink
            :to="{ name: 'profil' }"
            class="flex items-center gap-2 rounded-full p-1 text-sm text-surface-600 hover:bg-surface-100 md:py-1 md:pr-3 md:pl-1"
            aria-label="Profil"
            data-test="ust-profil"
          >
            <KisiResmi :ad="auth.kullanici?.ad_soyad ?? ''" :resim="auth.kullanici?.resim" />
            <span class="hidden max-w-40 truncate md:inline">{{ auth.kullanici?.ad_soyad }}</span>
          </RouterLink>
        </div>
      </div>
    </header>

    <!--
      overflow-x-clip: içerik ekrandan genişse telefon tarayıcısı sayfayı genişletir, sabit alt çubuk ekranın altından
      kayar. Clip kaydırma kutusu oluşturmaz (yapışkan katmanlar bozulmaz). Alt boşluk çentik payını da sayar.
    -->
    <main
      class="mx-auto w-full max-w-5xl flex-1 overflow-x-clip px-4 pt-4 pb-[calc(6.5rem+env(safe-area-inset-bottom))] md:pb-10"
    >
      <AltSekmeler
        v-if="portfoySekmeleri"
        :sekmeler="PORTFOY_SEKMELERI"
        :secili-mi="(ad) => route.name === ad"
        etiket="Portföy bölümleri"
        class="mb-4"
        data-test="portfoy-sekmeleri"
      />
      <!-- Masaüstünde detay sayfasının üstünde geri bağlantısı (telefonda üst çubukta) -->
      <button
        v-if="detay"
        type="button"
        class="mb-3 hidden items-center gap-1 text-sm font-semibold text-primary-700 hover:text-primary-800 md:inline-flex"
        @click="geriGit"
      >
        <i class="pi pi-chevron-left text-xs" /> {{ geriAdi }}
      </button>
      <!-- Yol (parametreler dahil) değişince sayfa yeniden kurulur; sorgu değişince kurulmaz (Özet'in ?hesap=) -->
      <RouterView v-slot="{ Component, route: r }">
        <component :is="Component" :key="`${r.path}:${yenileme}`" class="sayfa-giris" />
      </RouterView>
    </main>

    <SayfaBasinaDon />
    <HizliIslemler v-if="auth.kullanici" ref="hizli" dugmesiz @kaydedildi="yenileme++" />

    <!-- Telefon: alt sekme çubuğu; ortada hızlı işlem düğmesi -->
    <nav
      class="fixed inset-x-0 bottom-0 z-20 border-t border-surface-200 bg-surface-0/95 pb-[env(safe-area-inset-bottom)] backdrop-blur md:hidden"
      aria-label="Ana menü"
      data-test="alt-menu"
    >
      <div class="mx-auto flex max-w-lg items-end">
        <RouterLink
          v-for="m in menu.slice(0, 2)"
          :key="m.ad"
          :to="{ name: m.ad }"
          class="flex flex-1 flex-col items-center gap-0.5 pt-2 pb-1.5 text-xs font-semibold"
          :class="bolum === m.bolum ? 'text-primary-600' : 'text-surface-500'"
          :aria-current="bolum === m.bolum ? 'page' : undefined"
        >
          <i :class="m.ikon" class="text-xl" />
          {{ m.etiket }}
        </RouterLink>
        <div class="flex flex-1 justify-center">
          <button
            type="button"
            class="-mt-5 mb-1.5 flex h-14 w-14 items-center justify-center rounded-full border-4 border-surface-0 bg-primary-600 text-white shadow-lg shadow-primary-600/30 transition-transform active:scale-95"
            aria-label="Hızlı işlemler"
            aria-haspopup="true"
            data-test="alt-hizli"
            @click="hizli?.ac($event)"
          >
            <i class="pi pi-plus text-xl" />
          </button>
        </div>
        <RouterLink
          :to="{ name: 'izleme' }"
          class="flex flex-1 flex-col items-center gap-0.5 pt-2 pb-1.5 text-xs font-semibold"
          :class="bolum === 'izleme' ? 'text-primary-600' : 'text-surface-500'"
          :aria-current="bolum === 'izleme' ? 'page' : undefined"
        >
          <i class="pi pi-eye text-xl" />
          İzleme
        </RouterLink>
        <button
          type="button"
          class="flex flex-1 flex-col items-center gap-0.5 pt-2 pb-1.5 text-xs font-semibold"
          :class="
            bolum === 'profil' || bolum === 'yonetim' ? 'text-primary-600' : 'text-surface-500'
          "
          aria-haspopup="dialog"
          data-test="alt-menu-ac"
          @click="menuAcik = true"
        >
          <i class="pi pi-bars text-xl" />
          Menü
        </button>
      </div>
    </nav>

    <UygulamaPenceresi v-model:visible="menuAcik" baslik="Menü" :genislik="22">
      <div class="flex flex-col gap-1 pb-2" data-test="menu-penceresi">
        <button
          type="button"
          class="flex min-h-14 items-center gap-3 rounded-2xl px-2 text-left hover:bg-surface-100"
          @click="menudenGit('profil')"
        >
          <KisiResmi :ad="auth.kullanici?.ad_soyad ?? ''" :resim="auth.kullanici?.resim" />
          <span class="min-w-0">
            <span class="block truncate font-semibold text-surface-900">{{
              auth.kullanici?.ad_soyad
            }}</span>
            <span class="block text-xs text-surface-500">Profil, şifre, görünüm</span>
          </span>
        </button>
        <button
          v-if="auth.yonetici"
          type="button"
          class="flex min-h-12 items-center gap-3 rounded-2xl px-2 text-left font-semibold text-surface-800 hover:bg-surface-100"
          @click="menudenGit('yonetim')"
        >
          <span class="flex size-9 items-center justify-center rounded-xl bg-surface-100"
            ><i class="pi pi-cog"
          /></span>
          Yönetim
        </button>
        <button
          type="button"
          class="flex min-h-12 items-center gap-3 rounded-2xl px-2 text-left font-semibold text-red-600 hover:bg-red-50"
          data-test="menu-cikis"
          @click="cikis"
        >
          <span class="flex size-9 items-center justify-center rounded-xl bg-red-50"
            ><i class="pi pi-sign-out"
          /></span>
          Çıkış
        </button>
      </div>
    </UygulamaPenceresi>
  </div>
</template>
