<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import Accordion from 'primevue/accordion'
import AccordionContent from 'primevue/accordioncontent'
import AccordionHeader from 'primevue/accordionheader'
import AccordionPanel from 'primevue/accordionpanel'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import BosDurum from '@/components/BosDurum.vue'
import AySecici from '@/components/aile/AySecici.vue'
import KartGrafigi from '@/components/grafik/KartGrafigi.vue'
import KaydirmaliPaneller from '@/components/KaydirmaliPaneller.vue'
import KartEtiketi from '@/components/aile/KartEtiketi.vue'
import ElleEkstreDialog from '@/components/aile/ElleEkstreDialog.vue'
import EkstreYukleDialog from '@/components/aile/EkstreYukleDialog.vue'
import KartFormu from '@/components/aile/KartFormu.vue'
import { aileApi, type Ekstre, type Kart } from '@/api/aile'
import { useAileStore } from '@/stores/aile'
import { bugun, tarih, tl } from '@/utils/bicim'
import { donemEtiketi, gecerliDonem } from '@/utils/donem'
import { kartDegradesi } from '@/utils/kartRengi'

/**
 * Bankalar ve kartları; incelemeyi bekleyen ekstreler en üstte. Kart sayfasında ekstreler, işlemler ve faturalar.
 */
const aile = useAileStore()
const ekstreler = ref<Ekstre[] | null>(null)
const kartFormuAcik = ref(false)
const yukleAcik = ref(false)
const elleAcik = ref(false)
const yukleTuru = ref<'kart' | 'yapistir'>('kart')
/** Kartın kendi düğmelerinden açılınca o kart önceden seçili gelir; üstteki genel düğmelerde null */
const islemKartId = ref<number | null>(null)
/** Kaydırıcıda görünen kart sırası */
const sira = ref(0)

function yukleAc(tur: 'kart' | 'yapistir', kartId: number | null = null): void {
  yukleTuru.value = tur
  islemKartId.value = kartId
  yukleAcik.value = true
}

function elleAc(kartId: number | null = null): void {
  islemKartId.value = kartId
  elleAcik.value = true
}

const bankaAdi = (k: Kart): string => aile.bankaAdi(k.banka_id)

async function yukle(): Promise<void> {
  await aile.yukle()
  ekstreler.value = await aileApi.ekstreler()
}
onMounted(yukle)

const bekleyenler = computed(() => (ekstreler.value ?? []).filter((e) => e.durum === 'taslak'))
/** Kartlar banka sırasıyla, aynı bankanın kartları yan yana */
const kartlar = computed(() =>
  [...aile.kartlar].sort((a, b) => a.banka_id - b.banka_id || a.id - b.id),
)

/** Son ödeme hatırlatıcıları: onaylı, ödenmemiş, son ödeme tarihi belli ekstreler (tarihe göre) */
const sonOdemeler = computed(() => {
  const bu = new Date(`${bugun()}T00:00:00`).getTime()

  return (ekstreler.value ?? [])
    .filter((e) => e.durum === 'onaylandi' && !e.odendi && e.son_odeme_tarihi)
    .map((e) => ({
      ekstre: e,
      gun: Math.round((new Date(`${e.son_odeme_tarihi}T00:00:00`).getTime() - bu) / 86_400_000),
    }))
    .sort((a, b) => a.gun - b.gun)
})

const gunMetni = (gun: number): string =>
  gun < 0 ? `${-gun} gün geçti` : gun === 0 ? 'bugün' : `${gun} gün kaldı`

/** Seçilebilecek en yeni ekstre dönemi (onaylı ekstrelerin ayı: son ödeme ayı); seçici bundan ileri gitmez */
const enYeni = computed(() => {
  const donemler = (ekstreler.value ?? [])
    .filter((e) => e.durum === 'onaylandi' && gecerliDonem(e.donem))
    .map((e) => e.donem)
    .sort()

  return donemler[donemler.length - 1] ?? null
})
/** Kartlardaki ekstre dönemi: seçilmediyse en yeni ekstre dönemi */
const seciliDonem = ref<string | null>(null)
const aktifDonem = computed(() => seciliDonem.value ?? enYeni.value)
const donemModeli = computed({
  get: () => aktifDonem.value ?? '',
  set: (v: string) => {
    seciliDonem.value = v
  },
})

/** Kartın seçili dönemdeki onaylı ekstresi (aynı dönemde birden çoksa en yenisi) */
function sonEkstre(kartId: number): Ekstre | undefined {
  return (ekstreler.value ?? [])
    .filter((e) => e.kart_id === kartId && e.durum === 'onaylandi' && e.donem === aktifDonem.value)
    .sort((a, b) => b.id - a.id)[0]
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <!-- Ekstre dönemi çarkı: kartlardaki ekstre borcu / son ödeme seçilen dönemin ekstresinden gelir -->
    <div v-if="enYeni && aile.kartlar.length" data-test="kart-donem-secici">
      <AySecici v-model="donemModeli" :ileri-sinir="enYeni">
        <template #ek>
          <button
            v-if="seciliDonem !== null && seciliDonem !== enYeni"
            type="button"
            class="ay-carki-ek"
            data-test="kart-donem-en-yeni"
            @click="seciliDonem = null"
          >
            En yeni
          </button>
        </template>
      </AySecici>
    </div>
    <div class="flex flex-wrap justify-end gap-2">
      <RouterLink
        :to="{ name: 'aile-ekstreler' }"
        class="mr-auto inline-flex items-center gap-1.5 rounded-full px-3 py-2 text-sm font-semibold text-primary-600 ring-1 ring-primary-200"
        data-test="ekstreler-baglanti"
        ><i class="pi pi-inbox text-xs" /> Ekstreler</RouterLink
      >
      <Button
        label="Kart ekle"
        icon="pi pi-plus"
        severity="secondary"
        outlined
        @click="kartFormuAcik = true"
      />
    </div>

    <section v-if="bekleyenler.length" class="kart border-amber-200 bg-amber-50/60 p-4">
      <h2 class="bolum-etiketi mb-2 !text-amber-800">İncelemeyi bekleyen ekstreler</h2>
      <ul class="flex flex-col gap-2">
        <li v-for="e in bekleyenler" :key="e.id">
          <RouterLink
            :to="{ name: 'aile-ekstre', params: { id: e.id } }"
            class="flex items-center justify-between gap-3 rounded-2xl bg-white p-3 ring-1 ring-amber-200"
          >
            <span class="min-w-0">
              <span class="flex items-center gap-1 truncate font-semibold"
                ><KartEtiketi :kart-id="e.kart_id" /> ·
                {{ e.donem_etiketi ?? 'Dönem okunamadı' }}</span
              >
              <span class="block text-xs text-surface-500"
                >{{ e.satir_sayisi }} satır · {{ e.dosya_adi }}</span
              >
            </span>
            <span class="text-sm font-semibold whitespace-nowrap text-primary-600"
              >İncele <i class="pi pi-arrow-right text-xs"
            /></span>
          </RouterLink>
        </li>
      </ul>
    </section>

    <div v-if="ekstreler === null" class="flex flex-col gap-3">
      <Skeleton v-for="n in 3" :key="n" height="5rem" border-radius="1.5rem" />
    </div>
    <BosDurum
      v-else-if="!aile.kartlar.length"
      baslik="Henüz kart yok"
      aciklama="Bankanızı ve kredi kartınızı adıyla ekleyin (numara istenmez). Sonra ekstre PDF'ini yükleyin."
      ikon="pi pi-credit-card"
    >
      <Button label="Kart ekle" icon="pi pi-plus" @click="kartFormuAcik = true" />
    </BosDurum>
    <template v-else>
      <KaydirmaliPaneller
        v-model:sira="sira"
        :ogeler="kartlar"
        :sutun="3"
        :anahtar="(k) => k.id"
        :etiket="(k) => k.ad"
        data-test="kart-kaydirici"
      >
        <template #panel="{ oge: k }">
          <div class="flex flex-col gap-2" data-test="kart-paneli">
            <!-- Kredi kartı görünümü: kartın rengi; dokununca kart sayfası -->
            <RouterLink
              :to="{ name: 'aile-kart', params: { id: k.id } }"
              class="flex h-52 flex-col rounded-3xl p-5 text-white shadow-md"
              :class="k.aktif ? '' : 'opacity-60 grayscale'"
              :style="{ background: kartDegradesi(k) }"
              data-test="kart-satiri"
            >
              <span class="flex items-start justify-between gap-2">
                <span class="truncate text-xs font-semibold tracking-wide uppercase opacity-90">{{
                  bankaAdi(k)
                }}</span>
                <span v-if="!k.aktif" class="rounded-full bg-white/25 px-2 py-0.5 text-[11px]"
                  >Pasif</span
                >
                <i v-else class="pi pi-credit-card text-lg opacity-90" />
              </span>
              <span class="mt-auto block truncate text-xl font-bold">{{ k.ad }}</span>
              <span class="mt-0.5 block truncate text-xs opacity-90">
                {{ aile.uyeAdi(k.sahip_uye_id) }}
                <template v-if="k.kesim_gunu"> · kesim {{ k.kesim_gunu }}</template>
                <template v-if="k.son_odeme_gunu"> · son ödeme {{ k.son_odeme_gunu }}</template>
              </span>
              <span class="mt-4 flex min-h-[2.75rem] items-end justify-between gap-3">
                <span v-if="sonEkstre(k.id)">
                  <span class="block text-[11px] opacity-80" data-test="kart-ekstre-etiketi"
                    >{{ donemEtiketi(aktifDonem) }} ekstre borcu</span
                  >
                  <span class="block text-lg font-semibold tabular-nums">{{
                    tl(sonEkstre(k.id)!.donem_borcu)
                  }}</span>
                </span>
                <span v-else class="text-xs opacity-80">{{
                  ekstreler?.length ? 'Bu dönem ekstre yok' : 'Henüz ekstre yok'
                }}</span>
                <span
                  v-if="sonEkstre(k.id)?.son_odeme_tarihi"
                  class="text-right text-[11px] opacity-90"
                  >son ödeme<br />{{ tarih(sonEkstre(k.id)!.son_odeme_tarihi) }}</span
                >
              </span>
            </RouterLink>
            <!-- Bu karta ekstre girişi -->
            <div class="grid grid-cols-3 gap-2">
              <Button
                label="PDF"
                aria-label="Bu karta PDF ekstre yükle"
                icon="pi pi-upload"
                size="small"
                severity="secondary"
                outlined
                data-test="kart-ekstre-yukle"
                @click="yukleAc('kart', k.id)"
              />
              <Button
                label="JSON"
                aria-label="Bu karta dış yapay zekâ (JSON) ile ekstre gir"
                icon="pi pi-clipboard"
                size="small"
                severity="secondary"
                outlined
                data-test="kart-ekstre-json"
                @click="yukleAc('yapistir', k.id)"
              />
              <Button
                label="Tutar"
                aria-label="Bu karta ekstreyi yalnızca tutarla gir"
                icon="pi pi-pencil"
                size="small"
                severity="secondary"
                outlined
                data-test="kart-ekstre-elle"
                @click="elleAc(k.id)"
              />
            </div>
          </div>
        </template>
      </KaydirmaliPaneller>

      <!-- Dönem dönem dönem sonu ödemeler -->
      <KartGrafigi />

      <!-- Son ödeme hatırlatıcıları: kartların altında akordiyon -->
      <section v-if="sonOdemeler.length" class="kart p-2" data-test="son-odeme-akordiyon">
        <Accordion
          :value="['son-odeme']"
          multiple
          class="[&_.p-accordioncontent-wrapper]:min-w-0 [&_.p-accordioncontent-wrapper]:overflow-hidden"
        >
          <AccordionPanel value="son-odeme">
            <AccordionHeader data-test="son-odeme-baslik"
              >Son ödeme hatırlatıcıları ({{ sonOdemeler.length }})</AccordionHeader
            >
            <AccordionContent>
              <ul class="flex flex-col gap-2 text-sm">
                <li v-for="s in sonOdemeler" :key="s.ekstre.id">
                  <RouterLink
                    :to="{ name: 'aile-ekstre', params: { id: s.ekstre.id } }"
                    class="flex items-center gap-3 rounded-2xl px-3 py-2.5 ring-1"
                    :class="
                      s.gun < 0
                        ? 'bg-red-50 text-red-900 ring-red-200'
                        : s.gun <= 3
                          ? 'bg-amber-50 text-amber-900 ring-amber-200'
                          : 'bg-sky-50 text-sky-900 ring-sky-200'
                    "
                    data-test="son-odeme-satiri"
                  >
                    <i class="pi pi-credit-card" />
                    <span class="min-w-0 flex-1">
                      <span class="block truncate font-semibold"
                        ><KartEtiketi :kart-id="s.ekstre.kart_id"
                      /></span>
                      <span class="block text-xs opacity-80"
                        >son ödeme {{ tarih(s.ekstre.son_odeme_tarihi) }} ·
                        {{ gunMetni(s.gun) }}</span
                      >
                    </span>
                    <span class="font-semibold whitespace-nowrap tabular-nums">{{
                      tl(s.ekstre.donem_borcu)
                    }}</span>
                  </RouterLink>
                </li>
              </ul>
            </AccordionContent>
          </AccordionPanel>
        </Accordion>
      </section>
    </template>

    <KartFormu v-model:visible="kartFormuAcik" @kaydedildi="yukle" />
    <EkstreYukleDialog
      v-model:visible="yukleAcik"
      :kart-id="islemKartId"
      :baslangic-turu="yukleTuru"
    />
    <ElleEkstreDialog v-model:visible="elleAcik" :kart-id="islemKartId" />
  </div>
</template>
