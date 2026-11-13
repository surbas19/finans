<script setup lang="ts">
/**
 * Liste boşken veya bir şey henüz ayarlanmamışken gösterilen kutu.
 * Eylem butonu varsa varsayılan slot'a konur.
 */
withDefaults(
  defineProps<{
    baslik: string
    aciklama?: string
    ikon?: string
    /** Kart içinde veya dar alanda daha az boşlukla gösterir. */
    kucuk?: boolean
  }>(),
  { aciklama: undefined, ikon: 'pi pi-inbox', kucuk: false },
)
</script>

<template>
  <section
    class="flex flex-col items-center kart text-center"
    :class="kucuk ? 'gap-2 p-5' : 'gap-3 p-8'"
  >
    <span
      class="flex items-center justify-center rounded-2xl bg-surface-100 text-surface-500"
      :class="kucuk ? 'h-10 w-10 text-lg' : 'h-14 w-14 text-2xl'"
    >
      <i :class="ikon" />
    </span>
    <h2 class="font-semibold text-surface-900" :class="kucuk ? 'text-sm' : 'text-base'">
      {{ baslik }}
    </h2>
    <p v-if="aciklama" class="max-w-md text-sm text-surface-500">{{ aciklama }}</p>
    <div v-if="$slots.default" class="mt-1 flex flex-wrap justify-center gap-2">
      <slot />
    </div>
  </section>
</template>
