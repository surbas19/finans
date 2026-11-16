<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * Sayfa bir ekran boyu aşağı kayınca görünen, sayfa başına götüren kayan düğme (tüm sayfalarda, AnaDuzen'de). Telefonda
 * alt sekme çubuğunun üstünde durur. Hareket azaltma tercihinde animasyonsuz kayar.
 */
const gorunur = ref(false)

function kaydi(): void {
  gorunur.value = window.scrollY > window.innerHeight
}

function basaDon(): void {
  const azalt = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false
  window.scrollTo({ top: 0, behavior: azalt ? 'auto' : 'smooth' })
}

onMounted(() => {
  window.addEventListener('scroll', kaydi, { passive: true })
  kaydi()
})
onBeforeUnmount(() => window.removeEventListener('scroll', kaydi))
</script>

<template>
  <Transition
    enter-active-class="transition duration-200"
    enter-from-class="translate-y-3 opacity-0"
    leave-active-class="transition duration-150"
    leave-to-class="translate-y-3 opacity-0"
  >
    <button
      v-if="gorunur"
      type="button"
      class="fixed right-4 bottom-[calc(5.5rem+env(safe-area-inset-bottom))] z-30 flex size-11 items-center justify-center rounded-full bg-primary-600 text-white shadow-lg shadow-primary-900/25 active:scale-95 md:bottom-6"
      aria-label="Sayfa başına dön"
      title="Sayfa başına dön"
      data-test="basa-don"
      @click="basaDon"
    >
      <i class="pi pi-arrow-up" />
    </button>
  </Transition>
</template>
