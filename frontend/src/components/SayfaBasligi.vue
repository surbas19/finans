<script setup lang="ts">
/**
 * Sayfanın üst satırı: kısa açıklama (veya başlık) solda, eylem butonları sağda; altında isteğe bağlı filtre satırı.
 * Sayfa adı zaten üst çubukta (route.meta.baslik) yazdığı için `baslik` çoğu sayfada gerekmez. Başlık yerine başka
 * bir şey (ör. kayıt seçici) gerekiyorsa `#baslik` yuvası kullanılır; o zaman sol bölüm genişler.
 *
 *   <SayfaBasligi aciklama="Okuldaki dersler">
 *     <template #eylemler><Button label="Ders ekle" /></template>
 *     <template #filtreler><AramaKutusu v-model="ara" /></template>
 *   </SayfaBasligi>
 */
defineProps<{
  baslik?: string
  aciklama?: string
}>()
</script>

<template>
  <header class="mb-4 flex flex-col gap-3">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="min-w-0" :class="{ 'flex-1': $slots.baslik }">
        <slot name="baslik">
          <h2 v-if="baslik" class="text-lg font-semibold text-surface-900">{{ baslik }}</h2>
        </slot>
        <p v-if="aciklama" class="text-sm text-surface-500">{{ aciklama }}</p>
      </div>
      <!-- max-w-full: düğmeler satıra sığmazsa (telefon) alt satıra iner, sayfayı yana taşırmaz -->
      <div v-if="$slots.eylemler" class="flex max-w-full shrink-0 flex-wrap gap-2">
        <slot name="eylemler" />
      </div>
    </div>
    <div v-if="$slots.filtreler" class="flex flex-col gap-2 sm:flex-row sm:items-center">
      <slot name="filtreler" />
    </div>
  </header>
</template>
