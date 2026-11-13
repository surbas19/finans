<script setup lang="ts">
import { computed, ref } from 'vue'
import Button from 'primevue/button'
import { useToast } from 'primevue/usetoast'
import { useUygulamaKurulumu } from '@/composables/useUygulamaKurulumu'

/**
 * "Ana ekrana ekle" yönlendirmesi. Android'de istem hazırsa tek düğme; iPhone'da Paylaş → Ana Ekrana Ekle adımları;
 * öbür telefon tarayıcılarında menü yönergesi. Uygulama zaten ana ekrandan açıldıysa gösterilmez.
 * `ipucu`: Özet'te telefonda bir kez çıkan, kapatılabilen kısa sürüm (masaüstünde hiç görünmez).
 */
const props = withDefaults(defineProps<{ ipucu?: boolean }>(), { ipucu: false })

const IPUCU_ANAHTARI = 'finans.kurulumIpucuKapali'
const { kurulu, kurulabilir, ios, mobil, kur } = useUygulamaKurulumu()
const toast = useToast()

function ipucuKapaliMi(): boolean {
  try {
    return localStorage.getItem(IPUCU_ANAHTARI) === '1'
  } catch {
    return false
  }
}
const kapali = ref(props.ipucu && ipucuKapaliMi())

function kapat(): void {
  kapali.value = true
  try {
    localStorage.setItem(IPUCU_ANAHTARI, '1')
  } catch {
    // Gizli sekme / engellenmiş depolama: yalnızca bu açılışta kapanır.
  }
}

const gorunur = computed(() => {
  if (kurulu.value || kapali.value) return false
  if (props.ipucu) return mobil

  return mobil || kurulabilir.value
})

async function ekle(): Promise<void> {
  if (await kur()) toast.add({ severity: 'success', summary: 'Ana ekrana eklendi', life: 3000 })
}
</script>

<template>
  <section v-if="gorunur" class="kart relative flex flex-col gap-3 p-4">
    <div class="flex items-start gap-3" :class="{ 'pr-8': ipucu }">
      <img src="/icon-192.png" alt="" class="h-10 w-10 shrink-0 rounded-xl" />
      <div class="min-w-0 text-sm">
        <p class="font-semibold text-surface-900">Finans'ı ana ekrana ekleyin</p>
        <p class="text-surface-500">Tek dokunuşla, adres çubuğu olmadan uygulama gibi açılır.</p>
      </div>
    </div>

    <div v-if="kurulabilir">
      <Button label="Ana ekrana ekle" icon="pi pi-download" size="small" @click="ekle" />
    </div>
    <ol v-else-if="ios" class="flex list-decimal flex-col gap-1 pl-5 text-sm text-surface-600">
      <li>
        Alttaki <span class="font-semibold">Paylaş</span>
        <i class="pi pi-upload mx-1 text-primary-600" aria-label="Paylaş simgesi" /> düğmesine
        dokunun.
      </li>
      <li>Listede <span class="font-semibold">Ana Ekrana Ekle</span>'yi seçin.</li>
      <li>Sağ üstteki <span class="font-semibold">Ekle</span>'ye dokunun.</li>
    </ol>
    <p v-else class="text-sm text-surface-600">
      Tarayıcı menüsünden (<i class="pi pi-ellipsis-v text-xs" aria-label="menü" />)
      <span class="font-semibold">Uygulamayı yükle</span> ya da
      <span class="font-semibold">Ana ekrana ekle</span>'yi seçin.
    </p>

    <Button
      v-if="ipucu"
      icon="pi pi-times"
      text
      rounded
      size="small"
      severity="secondary"
      class="!absolute top-2 right-2"
      aria-label="Kapat"
      @click="kapat"
    />
  </section>
</template>
