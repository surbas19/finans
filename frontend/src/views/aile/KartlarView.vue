<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import BosDurum from '@/components/BosDurum.vue'
import KaydirmaliPaneller from '@/components/KaydirmaliPaneller.vue'
import KartEtiketi from '@/components/aile/KartEtiketi.vue'
import ElleEkstreDialog from '@/components/aile/ElleEkstreDialog.vue'
import EkstreYukleDialog from '@/components/aile/EkstreYukleDialog.vue'
import KartFormu from '@/components/aile/KartFormu.vue'
import { aileApi, type Ekstre, type Kart } from '@/api/aile'
import { useAileStore } from '@/stores/aile'
import { tarih, tl } from '@/utils/bicim'
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

function sonEkstre(kartId: number): Ekstre | undefined {
  return (ekstreler.value ?? []).find((e) => e.kart_id === kartId && e.durum === 'onaylandi')
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="flex flex-wrap justify-end gap-2">
      <RouterLink
        :to="{ name: 'aile-ekstreler' }"
        class="mr-auto inline-flex items-center gap-1.5 rounded-full px-3 py-2 text-sm font-semibold text-primary-600 ring-1 ring-primary-200"
        data-test="ekstreler-baglanti"
        ><i class="pi pi-inbox text-xs" /> Ekstreler</RouterLink
      >
      <Button
        label="Ekstre yükle"
        icon="pi pi-upload"
        :disabled="!aile.kartlar.length && !aile.hane?.yetenekler?.ai_acik"
        @click="yukleAc('kart')"
      />
      <!-- Dış yapay zekâ (JSON yapıştır): OpenAI anahtarına bağlı değil -->
      <Button
        label="Dış yapay zekâ (JSON)"
        icon="pi pi-clipboard"
        severity="secondary"
        outlined
        :disabled="!aile.kartlar.length"
        data-test="dis-ai-ekstre"
        @click="yukleAc('yapistir')"
      />
      <Button
        label="Tutarla ekstre gir"
        icon="pi pi-pencil"
        severity="secondary"
        outlined
        :disabled="!aile.kartlar.length"
        data-test="elle-ekstre"
        @click="elleAc()"
      />
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
              class="block rounded-3xl p-5 text-white shadow-md"
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
              <span class="mt-8 block truncate text-xl font-bold">{{ k.ad }}</span>
              <span class="mt-0.5 block truncate text-xs opacity-90">
                {{ aile.uyeAdi(k.sahip_uye_id) }}
                <template v-if="k.kesim_gunu"> · kesim {{ k.kesim_gunu }}</template>
                <template v-if="k.son_odeme_gunu"> · son ödeme {{ k.son_odeme_gunu }}</template>
              </span>
              <span class="mt-4 flex items-end justify-between gap-3">
                <span v-if="sonEkstre(k.id)">
                  <span class="block text-[11px] opacity-80">Son ekstre borcu</span>
                  <span class="block text-lg font-semibold tabular-nums">{{
                    tl(sonEkstre(k.id)!.donem_borcu)
                  }}</span>
                </span>
                <span v-else class="text-xs opacity-80">Henüz ekstre yok</span>
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
                label="PDF yükle"
                icon="pi pi-upload"
                size="small"
                severity="secondary"
                outlined
                data-test="kart-ekstre-yukle"
                @click="yukleAc('kart', k.id)"
              />
              <Button
                label="JSON ile"
                icon="pi pi-clipboard"
                size="small"
                severity="secondary"
                outlined
                data-test="kart-ekstre-json"
                @click="yukleAc('yapistir', k.id)"
              />
              <Button
                label="Tutarla"
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
