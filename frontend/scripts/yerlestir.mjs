// Derlenmiş arayüzü Laravel'e yerleştirir: dist/assets → backend/public/assets, dist/index.html →
// backend/resources/spa/index.html (SpaController verir). Diğer dist dosyaları (favicon…) public/ köküne kopyalanır.
import { cpSync, existsSync, mkdirSync, readdirSync, rmSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'

const kok = fileURLToPath(new URL('..', import.meta.url))
const dist = join(kok, 'dist')
const arka = join(kok, '..', 'backend')

if (!existsSync(join(dist, 'index.html'))) {
  console.error('dist/index.html yok: önce "npm run build-only" çalıştırın.')
  process.exit(1)
}

rmSync(join(arka, 'public', 'assets'), { recursive: true, force: true })
cpSync(join(dist, 'assets'), join(arka, 'public', 'assets'), { recursive: true })
mkdirSync(join(arka, 'resources', 'spa'), { recursive: true })
cpSync(join(dist, 'index.html'), join(arka, 'resources', 'spa', 'index.html'))
for (const ad of readdirSync(dist)) {
  if (ad !== 'assets' && ad !== 'index.html') {
    cpSync(join(dist, ad), join(arka, 'public', ad), { recursive: true })
  }
}
console.log('Arayüz backend/public/assets ve backend/resources/spa/index.html olarak yerleştirildi.')
