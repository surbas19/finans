<script setup lang="ts">
import { ref } from 'vue'
import { useToast } from 'primevue/usetoast'
import { hataMesaji } from '@/api/http'
import { useGeriAl } from '@/composables/useGeriAl'

/**
 * Altta "Geri al" bildirimi (`useGeriAl`): telefonda alt çubuğun üstünde, masaüstünde altta ortada. Geri alma başarısız
 * olursa hata bildirilir.
 */
const { bildirim, kapat } = useGeriAl()
const toast = useToast()
const calisiyor = ref(false)

async function geriAl(): Promise<void> {
  const b = bildirim.value
  if (!b || calisiyor.value) return
  calisiyor.value = true
  try {
    await b.geriAl()
    kapat()
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Geri alınamadı', detail: hataMesaji(e), life: 5000 })
  } finally {
    calisiyor.value = false
  }
}
</script>

<template>
  <Transition
    enter-from-class="translate-y-4 opacity-0"
    leave-to-class="translate-y-4 opacity-0"
    enter-active-class="transition duration-200 motion-reduce:transition-none"
    leave-active-class="transition duration-200 motion-reduce:transition-none"
  >
    <div
      v-if="bildirim"
      class="fixed inset-x-4 bottom-[calc(5.5rem+env(safe-area-inset-bottom))] z-40 mx-auto flex max-w-md items-center gap-3 rounded-2xl bg-surface-900 px-4 py-3 text-sm text-surface-0 shadow-xl md:bottom-6"
      role="status"
      aria-live="polite"
      data-test="geri-al-bildirimi"
    >
      <i class="pi pi-check text-emerald-400" aria-hidden="true" />
      <span class="min-w-0 flex-1">{{ bildirim.mesaj }}</span>
      <button
        type="button"
        class="shrink-0 font-bold text-primary-300 disabled:opacity-50"
        :disabled="calisiyor"
        data-test="geri-al"
        @click="geriAl"
      >
        Geri al
      </button>
    </div>
  </Transition>
</template>
