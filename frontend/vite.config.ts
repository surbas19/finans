import { execSync } from 'node:child_process'
import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

// Arayüz ve API aynı kökten sunulur (canlıda Laravel derlenmiş sayfayı verir). Yerelde Vite /api'yi Laravel'e
// aktarır; böylece tarayıcı istekleri "same-origin" görür (CSRF köken denetimi, CLAUDE.md).
/**
 * Derleme sürümü: paketin commit'i. paket-hazirla.sh aynı commit'i sunucuya `deploy/SURUM` olarak yazar; açık uygulama
 * `/api/v1/surum` ile karşılaştırıp yeni sürümü kendisi yükler (src/utils/surumDenetimi.ts). Git yoksa boş: denetim kapalı.
 */
function derlemeSurumu(): string {
  try {
    return execSync('git rev-parse --short HEAD', { stdio: ['ignore', 'pipe', 'ignore'] })
      .toString()
      .trim()
  } catch {
    return ''
  }
}

export default defineConfig(() => ({
  plugins: [vue(), tailwindcss()],
  define: {
    __SURUM__: JSON.stringify(derlemeSurumu()),
  },
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
