<script setup lang="ts">
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'
</script>

<template>
  <RouterView />
  <Toast position="top-center" />
  <!-- Genişlik verilmezse telefonda ekranın iki kenarına yapışır -->
  <ConfirmDialog class="w-[min(26rem,calc(100vw-2rem))]" />
</template>
