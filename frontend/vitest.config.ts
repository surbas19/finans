import { fileURLToPath } from 'node:url'
import { mergeConfig, defineConfig, configDefaults } from 'vitest/config'
import viteConfig from './vite.config.ts'

// vite.config ortam değişkenine göre kurulduğu için işlevdir; testte "test" kipiyle çağrılır
export default defineConfig((env) =>
  mergeConfig(
    viteConfig({ ...env, mode: 'test' }),
    defineConfig({
      test: {
        environment: 'jsdom',
        exclude: [...configDefaults.exclude, 'e2e/**'],
        root: fileURLToPath(new URL('./', import.meta.url)),
      },
    }),
  ),
)
