<script setup lang="ts">
defineProps<{
  etiket: string
  for?: string
  hata?: string
  ipucu?: string
  zorunlu?: boolean
}>()
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <label :for="$props.for" class="text-sm font-medium text-surface-700">
      {{ etiket }}<span v-if="zorunlu" class="text-red-500"> *</span>
    </label>
    <slot />
    <small v-if="hata" class="text-red-600">{{ hata }}</small>
    <small v-else-if="ipucu" class="text-surface-500">{{ ipucu }}</small>
  </div>
</template>
