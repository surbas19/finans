<script setup lang="ts">
import { computed, ref } from 'vue'
import Accordion from 'primevue/accordion'
import AccordionContent from 'primevue/accordioncontent'
import AccordionHeader from 'primevue/accordionheader'
import AccordionPanel from 'primevue/accordionpanel'
import Button from 'primevue/button'
import { useToast } from 'primevue/usetoast'
import KartEtiketi from '@/components/aile/KartEtiketi.vue'
import { aileApi, type Ekstre } from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { useGeriAl } from '@/composables/useGeriAl'
import { useAileStore } from '@/stores/aile'
import { bugun, tarih, tl } from '@/utils/bicim'
import { toplamTl } from '@/utils/fatura'

/**
 * Kart borç hatırlatıcısı (Kartlar sayfası): onaylı, ödenmemiş, son ödeme tarihi belli ekstreler.
 * - Sıra: hanenin seçtiği kart sırası (`kartlar.hatirlatici_sira`); sırası olmayan kart sonda, tarihe göre. "Düzenle"
 *   kipinde tutamaktan sürüklenir (parmak / fare; klavyede ↑ ↓), bırakınca kaydedilir.
 * - "Borç öde": ekstre ödendi işaretlenir, satır düşer, 5 sn "Geri al".
 * - "Toplama ekle": seçilenlerin dönem borcu ve asgari toplamı alttaki yapışkan çubukta (`ekstreler.toplamda`).
 * Güncellenen ekstre `degisti` ile sayfaya bildirilir (liste orada tutulur).
 */
const props = defineProps<{ ekstreler: Ekstre[] }>()
const emit = defineEmits<{ degisti: [e: Ekstre] }>()

const aile = useAileStore()
const toast = useToast()
const { goster } = useGeriAl()
const calisan = ref<number | null>(null)

const gunu = (e: Ekstre): number =>
  Math.round(
    (new Date(`${e.son_odeme_tarihi}T00:00:00`).getTime() -
      new Date(`${bugun()}T00:00:00`).getTime()) /
      86_400_000,
  )
const kartSirasi = (kartId: number): number =>
  aile.kart(kartId)?.hatirlatici_sira ?? Number.MAX_SAFE_INTEGER

const satirlar = computed(() =>
  props.ekstreler
    .filter((e) => e.durum === 'onaylandi' && !e.odendi && e.son_odeme_tarihi)
    .map((e) => ({ ekstre: e, gun: gunu(e) }))
    .sort(
      (a, b) =>
        kartSirasi(a.ekstre.kart_id) - kartSirasi(b.ekstre.kart_id) ||
        a.gun - b.gun ||
        a.ekstre.id - b.ekstre.id,
    ),
)

const gunMetni = (gun: number): string =>
  gun < 0 ? `${-gun} gün geçti` : gun === 0 ? 'bugün' : `${gun} gün kaldı`
const renk = (gun: number): string =>
  gun < 0
    ? 'bg-red-50 text-red-900 ring-red-200'
    : gun <= 3
      ? 'bg-amber-50 text-amber-900 ring-amber-200'
      : 'bg-sky-50 text-sky-900 ring-sky-200'

async function guncelle(
  e: Ekstre,
  veri: { odendi?: boolean; toplamda?: boolean },
): Promise<Ekstre> {
  const { ekstre } = await aileApi.ekstreGuncelle(e.id, veri)
  emit('degisti', ekstre)

  return ekstre
}

async function ode(e: Ekstre): Promise<void> {
  calisan.value = e.id
  try {
    await guncelle(e, { odendi: true })
    goster(`${aile.kartAdi(e.kart_id)} borcu ödendi`, async () => {
      await guncelle(e, { odendi: false })
    })
  } catch (h) {
    toast.add({ severity: 'error', summary: 'Kaydedilemedi', detail: hataMesaji(h), life: 5000 })
  } finally {
    calisan.value = null
  }
}

async function toplamaEkle(e: Ekstre): Promise<void> {
  try {
    await guncelle(e, { toplamda: !e.toplamda })
  } catch (h) {
    toast.add({ severity: 'error', summary: 'Kaydedilemedi', detail: hataMesaji(h), life: 5000 })
  }
}

/** Toplama eklenenler: dönem borcu ve asgari ödeme toplamı (kuruşla); asgarisi bilinmeyen varsa belirtilir */
const secilenler = computed(() => satirlar.value.filter((s) => s.ekstre.toplamda))
const toplam = computed(() => ({
  borc: toplamTl(secilenler.value.map((s) => s.ekstre.donem_borcu ?? 0)),
  asgari: toplamTl(secilenler.value.map((s) => s.ekstre.asgari_odeme ?? 0)),
  asgariEksik: secilenler.value.some((s) => s.ekstre.asgari_odeme === null),
}))
async function temizle(): Promise<void> {
  try {
    await Promise.all(secilenler.value.map((s) => guncelle(s.ekstre, { toplamda: false })))
  } catch (h) {
    toast.add({ severity: 'error', summary: 'Kaydedilemedi', detail: hataMesaji(h), life: 5000 })
  }
}

/* ---------- Düzenle: sürükle-bırak sıra ---------- */
const duzenle = ref(false)
/** Düzenlerken ekranda görünen sıra (ekstre kimlikleri) */
const yerel = ref<number[]>([])
const suruklenen = ref<number | null>(null)
const ogeler = ref<HTMLElement[]>([])

const gorunen = computed(() => {
  if (!duzenle.value) return satirlar.value
  const harita = new Map(satirlar.value.map((s) => [s.ekstre.id, s]))

  return yerel.value.map((id) => harita.get(id)).filter((s) => s !== undefined)
})

function duzenleAc(): void {
  yerel.value = satirlar.value.map((s) => s.ekstre.id)
  duzenle.value = true
}

function tasi(id: number, yeniYer: number): void {
  const liste = yerel.value.filter((x) => x !== id)
  liste.splice(Math.max(0, Math.min(yeniYer, liste.length)), 0, id)
  yerel.value = liste
}

function tutamakBas(olay: PointerEvent, id: number): void {
  suruklenen.value = id
  ;(olay.currentTarget as HTMLElement | null)?.setPointerCapture?.(olay.pointerId)
}
function tutamakKaydir(olay: PointerEvent): void {
  if (suruklenen.value === null) return
  // Parmağın altındaki yer: ortası parmağın altında kalan ilk öğenin yeri
  const yer = ogeler.value.findIndex((el) => {
    const k = el.getBoundingClientRect()
    return olay.clientY < k.top + k.height / 2
  })
  const hedef = yer < 0 ? yerel.value.length - 1 : yer
  if (yerel.value.indexOf(suruklenen.value) !== hedef) tasi(suruklenen.value, hedef)
}
function tutamakBirak(): void {
  if (suruklenen.value === null) return
  suruklenen.value = null
  void kaydet()
}
function klavye(olay: KeyboardEvent, id: number): void {
  const yer = yerel.value.indexOf(id)
  if (olay.key === 'ArrowUp' && yer > 0) tasi(id, yer - 1)
  else if (olay.key === 'ArrowDown' && yer < yerel.value.length - 1) tasi(id, yer + 1)
  else return
  olay.preventDefault()
  void kaydet()
}

/**
 * Sıra kart başınadır: ekrandaki sıradan kartlar (tekrarsız), ardından hatırlatıcısı olmayan kartlar eski sıralarıyla
 * (bu ay borcu olmayan kartın yeri kaybolmasın).
 */
async function kaydet(): Promise<void> {
  const harita = new Map(satirlar.value.map((s) => [s.ekstre.id, s.ekstre.kart_id]))
  const ekrandakiler = [
    ...new Set(yerel.value.map((id) => harita.get(id)).filter((k) => k !== undefined)),
  ]
  const digerleri = [...aile.kartlar]
    .filter(
      (k) =>
        !ekrandakiler.includes(k.id) &&
        k.hatirlatici_sira !== null &&
        k.hatirlatici_sira !== undefined,
    )
    .sort((a, b) => (a.hatirlatici_sira ?? 0) - (b.hatirlatici_sira ?? 0))
    .map((k) => k.id)
  const sira = [...ekrandakiler, ...digerleri]
  // Ekranda hemen: mağazadaki sıra güncellenir
  aile.kartlar.forEach((k) => {
    const i = sira.indexOf(k.id)
    k.hatirlatici_sira = i < 0 ? null : i
  })
  try {
    await aileApi.kartHatirlaticiSirasi(sira)
  } catch (h) {
    toast.add({
      severity: 'error',
      summary: 'Sıra kaydedilemedi',
      detail: hataMesaji(h),
      life: 5000,
    })
  }
}
</script>

<template>
  <div v-if="satirlar.length" class="flex flex-col gap-3">
    <section class="kart p-2" data-test="son-odeme-akordiyon">
      <Accordion
        :value="['son-odeme']"
        multiple
        class="[&_.p-accordioncontent-wrapper]:min-w-0 [&_.p-accordioncontent-wrapper]:overflow-hidden"
      >
        <AccordionPanel value="son-odeme">
          <AccordionHeader data-test="son-odeme-baslik"
            >Son ödeme hatırlatıcıları ({{ satirlar.length }})</AccordionHeader
          >
          <AccordionContent>
            <div class="mb-2 flex items-center justify-between gap-2">
              <p class="text-xs text-surface-500">
                <template v-if="duzenle">Tutamaktan tutup sürükleyin; sıra kaydedilir.</template>
              </p>
              <Button
                :label="duzenle ? 'Bitti' : 'Düzenle'"
                :icon="duzenle ? 'pi pi-check' : 'pi pi-sort'"
                size="small"
                :severity="duzenle ? undefined : 'secondary'"
                :text="!duzenle"
                data-test="son-odeme-duzenle"
                @click="duzenle ? (duzenle = false) : duzenleAc()"
              />
            </div>
            <ul class="flex flex-col gap-2 text-sm">
              <li
                v-for="s in gorunen"
                :key="s.ekstre.id"
                ref="ogeler"
                class="rounded-2xl ring-1 transition-shadow"
                :class="[
                  renk(s.gun),
                  suruklenen === s.ekstre.id ? 'relative z-10 shadow-lg ring-2' : '',
                ]"
                data-test="son-odeme-satiri"
              >
                <div class="flex items-center gap-2 px-3 py-2.5">
                  <button
                    v-if="duzenle"
                    type="button"
                    class="-ml-1 flex size-8 shrink-0 cursor-grab touch-none items-center justify-center rounded-lg opacity-70 active:cursor-grabbing"
                    :aria-label="`${aile.kartAdi(s.ekstre.kart_id)} sırasını değiştir (yukarı / aşağı ok)`"
                    data-test="son-odeme-tutamak"
                    @pointerdown="tutamakBas($event, s.ekstre.id)"
                    @pointermove="tutamakKaydir"
                    @pointerup="tutamakBirak"
                    @pointercancel="tutamakBirak"
                    @keydown="klavye($event, s.ekstre.id)"
                  >
                    <i class="pi pi-bars" />
                  </button>
                  <RouterLink
                    :to="{ name: 'aile-ekstre', params: { id: s.ekstre.id } }"
                    class="flex min-w-0 flex-1 items-center gap-3"
                  >
                    <span class="min-w-0 flex-1">
                      <span class="block truncate font-semibold"
                        ><KartEtiketi :kart-id="s.ekstre.kart_id"
                      /></span>
                      <span class="block text-xs opacity-80"
                        >son ödeme {{ tarih(s.ekstre.son_odeme_tarihi) }} · {{ gunMetni(s.gun)
                        }}<template v-if="s.ekstre.asgari_odeme !== null">
                          · asgari {{ tl(s.ekstre.asgari_odeme) }}</template
                        ></span
                      >
                    </span>
                    <span class="font-semibold whitespace-nowrap tabular-nums">{{
                      tl(s.ekstre.donem_borcu)
                    }}</span>
                  </RouterLink>
                </div>
                <div
                  v-if="!duzenle"
                  class="flex items-center justify-end gap-2 border-t border-current/15 px-3 py-2"
                >
                  <button
                    type="button"
                    class="rounded-full px-3 py-1 text-xs font-bold ring-1 transition-colors"
                    :class="
                      s.ekstre.toplamda
                        ? 'bg-primary-600 text-white ring-primary-600'
                        : 'ring-current/40'
                    "
                    :aria-pressed="!!s.ekstre.toplamda"
                    data-test="son-odeme-toplam"
                    @click="toplamaEkle(s.ekstre)"
                  >
                    {{ s.ekstre.toplamda ? '✓ Toplamda' : '+ Toplama ekle' }}
                  </button>
                  <Button
                    label="Borç öde"
                    icon="pi pi-check"
                    size="small"
                    :loading="calisan === s.ekstre.id"
                    data-test="son-odeme-ode"
                    @click="ode(s.ekstre)"
                  />
                </div>
              </li>
            </ul>
          </AccordionContent>
        </AccordionPanel>
      </Accordion>
    </section>

    <!-- Seçilenlerin toplamı: hatırlatıcılar ekrandayken altta yapışkan -->
    <div
      v-if="secilenler.length"
      class="sticky bottom-[calc(5.5rem+env(safe-area-inset-bottom))] z-20 flex items-center gap-3 rounded-2xl bg-surface-900 px-4 py-3 text-surface-0 shadow-xl md:bottom-4"
      data-test="son-odeme-toplami"
    >
      <span class="min-w-0 flex-1">
        <span class="block text-xs opacity-75">Seçilen {{ secilenler.length }} kart</span>
        <span class="block text-lg font-bold tabular-nums">{{ tl(toplam.borc) }}</span>
      </span>
      <span class="text-right text-xs">
        <span class="block opacity-75">asgari{{ toplam.asgariEksik ? ' (bilinenler)' : '' }}</span>
        <span class="block font-bold tabular-nums">{{ tl(toplam.asgari) }}</span>
      </span>
      <button
        type="button"
        class="shrink-0 text-sm font-bold text-primary-300"
        data-test="son-odeme-temizle"
        @click="temizle"
      >
        Temizle
      </button>
    </div>
  </div>
</template>
