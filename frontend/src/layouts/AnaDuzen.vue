<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import { useAuthStore } from '@/stores/auth'

/**
 * Üstte uygulama adı ve kişi; masaüstünde üst menü, telefonda alt sekme çubuğu (mobile-first).
 */
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const menu = computed(() => [
  { ad: 'ozet', etiket: 'Özet', ikon: 'pi pi-chart-pie' },
  { ad: 'hesaplar', etiket: 'Hesaplar', ikon: 'pi pi-wallet' },
  { ad: 'izleme', etiket: 'İzleme', ikon: 'pi pi-eye' },
  { ad: 'islemler', etiket: 'İşlemler', ikon: 'pi pi-list' },
  ...(auth.yonetici ? [{ ad: 'yonetim', etiket: 'Yönetim', ikon: 'pi pi-cog' }] : []),
])

/** Alt rotalar (hesap detayı, varlık detayı) da ilgili sekmeyi seçili gösterir */
function seciliMi(ad: string): boolean {
  const simdiki = String(route.name ?? '')
  if (ad === 'hesaplar') return simdiki === 'hesaplar' || simdiki === 'hesap'
  if (ad === 'izleme') return simdiki === 'izleme' || simdiki === 'varlik'

  return simdiki === ad
}

async function cikis(): Promise<void> {
  await auth.cikis()
  await router.push({ name: 'giris' })
}
</script>

<template>
  <div class="flex min-h-dvh flex-col">
    <header
      class="sticky top-0 z-20 border-b border-surface-200/70 bg-white/85 pt-[env(safe-area-inset-top)] backdrop-blur supports-[backdrop-filter]:bg-white/70"
    >
      <div class="mx-auto flex h-14 max-w-5xl items-center gap-3 px-4">
        <RouterLink
          :to="{ name: 'ozet' }"
          class="flex items-center gap-2 font-bold text-surface-900"
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
              seciliMi(m.ad)
                ? 'bg-primary-50 text-primary-700'
                : 'text-surface-600 hover:bg-surface-100'
            "
          >
            <i :class="m.ikon" class="mr-1.5 text-xs" />{{ m.etiket }}
          </RouterLink>
        </nav>
        <div class="ml-auto flex items-center gap-1">
          <RouterLink
            :to="{ name: 'profil' }"
            class="hidden max-w-40 truncate rounded-full px-3 py-1.5 text-sm text-surface-600 hover:bg-surface-100 sm:block"
          >
            <i class="pi pi-user mr-1.5 text-xs" />{{ auth.kullanici?.ad_soyad }}
          </RouterLink>
          <Button
            icon="pi pi-user"
            text
            rounded
            class="sm:!hidden"
            aria-label="Profil"
            @click="router.push({ name: 'profil' })"
          />
          <Button
            icon="pi pi-sign-out"
            text
            rounded
            aria-label="Çıkış"
            title="Çıkış"
            @click="cikis"
          />
        </div>
      </div>
    </header>

    <main class="mx-auto w-full max-w-5xl flex-1 px-4 pt-4 pb-24 md:pb-10">
      <RouterView v-slot="{ Component, route: r }">
        <component :is="Component" :key="r.fullPath" class="sayfa-giris" />
      </RouterView>
    </main>

    <!-- Telefon: alt sekme çubuğu -->
    <nav
      class="fixed inset-x-0 bottom-0 z-20 border-t border-surface-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur md:hidden"
      aria-label="Ana menü"
    >
      <div class="mx-auto flex max-w-lg">
        <RouterLink
          v-for="m in menu"
          :key="m.ad"
          :to="{ name: m.ad }"
          class="flex flex-1 flex-col items-center gap-0.5 py-2 text-[11px] font-semibold"
          :class="seciliMi(m.ad) ? 'text-primary-600' : 'text-surface-500'"
        >
          <i :class="m.ikon" class="text-lg" />
          {{ m.etiket }}
        </RouterLink>
      </div>
    </nav>
  </div>
</template>
