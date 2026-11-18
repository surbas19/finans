/**
 * Koyu tema CSS'ini üretir: src/assets/koyu-tema.css (elle düzenlenmez; bu betikle yeniden üretilir).
 *
 *   node scripts/koyu-tema-uret.mjs
 *
 * Yöntem: uygulama renkleri iki paletten gelir: PrimeVue Aura temel renkleri (`--p-teal-600`; primary ve surface
 * bunlara `var()` ile bağlı) ve Tailwind renkleri (`--color-red-50`). Koyu temada (`html.koyu`) basamaklar ters çevrilir:
 * açık zeminler (50–300) koyu, koyu yazılar (700–950) açık olur. 500–600 aynı kalır: düğme zemini (`bg-primary-600`
 * + beyaz yazı) ve grafik renkleri okunur kalır. Yüzey (surface) ayrı eşlenir: kart (0) sayfa zemininden (50) bir ton açık.
 */
import { readFileSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const kok = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const aura = (
  await import(
    pathToFileURL(resolve(kok, 'node_modules/@primeuix/themes/dist/aura/base/index.mjs')).href
  )
).default
const tailwind = readFileSync(resolve(kok, 'node_modules/tailwindcss/theme.css'), 'utf8')

/** Renk basamağı → koyu temada kullanılacak basamak (500 ve 600 değişmez) */
const RENK = {
  50: 950,
  100: 900,
  200: 800,
  300: 700,
  400: 500,
  700: 400,
  800: 300,
  900: 200,
  950: 100,
}
/** Yüzey basamağı → slate basamağı (0: kart, 50: sayfa zemini) */
const YUZEY = {
  0: 900,
  50: 950,
  100: 800,
  200: 700,
  300: 600,
  400: 500,
  500: 400,
  600: 300,
  700: 200,
  800: 100,
  900: 50,
  950: 50,
}

const satirlar = []

// PrimeVue temel renkleri
for (const [ad, palet] of Object.entries(aura.primitive)) {
  if (ad === 'borderRadius' || typeof palet !== 'object') continue
  for (const [basamak, hedef] of Object.entries(RENK)) {
    if (palet[hedef]) satirlar.push(`  --p-${ad}-${basamak}: ${palet[hedef]};`)
  }
}
// Yüzey (surface): açık temada 0 = beyaz, diğerleri slate
for (const [basamak, hedef] of Object.entries(YUZEY)) {
  satirlar.push(`  --p-surface-${basamak}: ${aura.primitive.slate[hedef]};`)
}

// Tailwind renkleri (--color-<ad>-<basamak>: oklch(...))
const tw = new Map()
for (const m of tailwind.matchAll(/--color-([a-z]+)-(\d+):\s*([^;]+);/g)) {
  tw.set(`${m[1]}-${m[2]}`, m[3].trim())
}
const twAdlari = [...new Set([...tw.keys()].map((k) => k.split('-')[0]))]
for (const ad of twAdlari) {
  for (const [basamak, hedef] of Object.entries(RENK)) {
    const deger = tw.get(`${ad}-${hedef}`)
    if (deger && tw.has(`${ad}-${basamak}`)) satirlar.push(`  --color-${ad}-${basamak}: ${deger};`)
  }
}

const css = `/* Üretildi: scripts/koyu-tema-uret.mjs — elle düzenlemeyin. Koyu tema: html.koyu (useTema). */
html.koyu {
  color-scheme: dark;
${satirlar.join('\n')}
}
`
writeFileSync(resolve(kok, 'src/assets/koyu-tema.css'), css)
console.log(`koyu-tema.css: ${satirlar.length} değişken`)
