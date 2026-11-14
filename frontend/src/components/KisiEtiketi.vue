<script setup lang="ts">
import KisiResmi from '@/components/KisiResmi.vue'

/**
 * Yazı satırı içinde kişi: küçük resim (ya da baş harf) + ad. Kişi yoksa hiçbir şey çizmez.
 */
defineProps<{
  kisi?: { ad: string; resim?: string | null; renk?: string | null } | null
}>()
</script>

<template>
  <span v-if="kisi" class="inline-flex max-w-full items-center gap-1 align-bottom">
    <KisiResmi :ad="kisi.ad" :resim="kisi.resim" :renk="kisi.renk" boyut="xs" />
    <span class="truncate">{{ kisi.ad }}</span>
  </span>
</template>
