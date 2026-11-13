import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

// Arayüz ve API aynı kökten sunulur (canlıda Laravel derlenmiş sayfayı verir). Yerelde Vite /api'yi Laravel'e
// aktarır; böylece tarayıcı istekleri "same-origin" görür (CSRF köken denetimi, CLAUDE.md).
export default defineConfig(() => ({
  plugins: [vue(), tailwindcss()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    host: 'localhost',
    port: 5174,
    strictPort: true,
    proxy: {
      '/api': { target: 'http://localhost:8001', changeOrigin: true },
    },
  },
  build: {
    outDir: 'dist',
    assetsDir: 'assets',
  },
}))
