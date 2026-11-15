<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'
import BosDurum from '@/components/BosDurum.vue'
import ElleEkstreDialog from '@/components/aile/ElleEkstreDialog.vue'
import EkstreYukleDialog from '@/components/aile/EkstreYukleDialog.vue'
import { aileApi, type AiCalisma, type Ekstre, type HesapEkstresi } from '@/api/aile'
import { useAileStore } from '@/stores/aile'
import { tarih } from '@/utils/bicim'

/**
 * Ekstre aktarım merkezi: kart ekstreleri (inceleme bekleyen / onaylı, yapay zekâ ile okunanlar) ve banka hesabı
 * ekstreleri (yalnızca önizleme), durumlarıyla; yükleme (tür seçimi, yapay zekâ onayı).
 */
const aile = useAileStore()
const ekstreler = ref<Ekstre[] | null>(null)
const hesaplar = ref<(HesapEkstresi & { ai_calisma: AiCalisma | null })[]>([])
const yukleAcik = ref(false)
const elleAcik = ref(false)
const yukleTuru = ref<'kart' | 'banka' | 'yapistir'>('kart')

async function yukle(): Promise<void> {
  await aile.yukle()
  const [e, h] = await Promise.all([aileApi.ekstreler(), aileApi.hesapEkstreleri()])
  ekstreler.value = e
  hesaplar.value = h
}
onMounted(yukle)

const aiAcik = computed(() => aile.hane?.yetenekler?.ai_acik ?? false)

function ac(tur: 'kart' | 'banka' | 'yapistir'): void {
  yukleTuru.value = tur
  yukleAcik.value = true
}

function hesapDurumu(h: HesapEkstresi & { ai_calisma: AiCalisma | null }): {
  etiket: string
  renk: 'info' | 'success' | 'danger' | 'secondary'
} {
  if (h.ai_calisma?.etkin) return { etiket: 'Analiz ediliyor', renk: 'info' }
  if (h.durum === 'onizleme') return { etiket: 'Önizleme', renk: 'success' }
  if (h.ai_calisma?.durum === 'hata' || h.ai_calisma?.durum === 'iptal')
    return { etiket: 'Okunamadı', renk: 'danger' }

  return { etiket: 'Bekliyor', renk: 'secondary' }
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="flex flex-wrap justify-end gap-2">
      <Button
        label="Kart ekstresi yükle"
        icon="pi pi-upload"
        :disabled="!aile.kartlar.length"
        @click="ac('kart')"
      />
      <!-- Dış yapay zekâ: sunucuda OpenAI anahtarı gerekmez -->
      <Button
        label="Dış yapay zekâ (JSON)"
        icon="pi pi-clipboard"
        severity="secondary"
        outlined
        :disabled="!aile.kartlar.length"
        data-test="dis-ai-ekstre"
        @click="ac('yapistir')"
      />
      <Button
        label="Tutarla ekstre gir"
        icon="pi pi-pencil"
        severity="secondary"
        outlined
        :disabled="!aile.kartlar.length"
        data-test="elle-ekstre"
        @click="elleAcik = true"
      />
      <Button
        v-if="aiAcik"
        label="Banka hesabı ekstresi"
        icon="pi pi-building-columns"
        severity="secondary"
        outlined
        @click="ac('banka')"
      />
    </div>

    <div v-if="ekstreler === null" class="flex flex-col gap-3">
      <Skeleton v-for="n in 3" :key="n" height="4.5rem" border-radius="1.5rem" />
    </div>
    <template v-else>
      <section class="kart p-4 sm:p-5" data-test="kart-ekstreleri">
        <h2 class="bolum-etiketi mb-2">Kart ekstreleri</h2>
        <BosDurum
          v-if="!ekstreler.length"
          kucuk
          baslik="Henüz ekstre yok"
          aciklama="Kredi kartı ekstresinin PDF'ini yükleyin; satırlar incelemenize açılır."
          ikon="pi pi-file-pdf"
        />
        <ul v-else class="divide-y divide-surface-100">
          <li v-for="e in ekstreler" :key="e.id">
            <RouterLink
              :to="{ name: 'aile-ekstre', params: { id: e.id } }"
              class="flex items-center gap-3 py-3"
            >
              <span class="min-w-0 flex-1">
                <span class="block truncate font-semibold text-surface-900"
                  >{{ aile.kartAdi(e.kart_id) }} · {{ e.donem_etiketi ?? 'Dönem okunamadı' }}</span
                >
                <span class="block truncate text-xs text-surface-500"
                  >{{ e.satir_sayisi ?? 0 }} satır<template v-if="e.denetim?.ai?.harici">
                    · harici JSON ile okundu</template
                  ><template v-else-if="e.banka_profili === 'ai'"> · yapay zekâ okudu</template
                  ><template v-if="e.olusturuldu"> · {{ tarih(e.olusturuldu) }}</template></span
                >
              </span>
              <Tag
                :value="e.durum === 'taslak' ? 'İnceleme bekliyor' : 'Onaylandı'"
                :severity="e.durum === 'taslak' ? 'warn' : 'success'"
              />
            </RouterLink>
          </li>
        </ul>
      </section>

      <section
        v-if="aiAcik || hesaplar.length"
        class="kart p-4 sm:p-5"
        data-test="hesap-ekstreleri"
      >
        <h2 class="bolum-etiketi mb-1">Banka hesabı ekstreleri</h2>
        <p class="mb-2 text-xs text-surface-500">
          Yalnızca önizleme: işlemler ve bakiye denetimi görünür, Bütçe'ye aktarılmaz.
        </p>
        <BosDurum
          v-if="!hesaplar.length"
          kucuk
          baslik="Önizleme yok"
          aciklama="Banka hesabı ekstresini yükleyin; yapay zekâ okur, bakiyeyi denetler."
          ikon="pi pi-building-columns"
        />
        <ul v-else class="divide-y divide-surface-100">
          <li v-for="h in hesaplar" :key="h.id">
            <RouterLink
              :to="{ name: 'aile-hesap-ekstresi', params: { id: h.id } }"
              class="flex items-center gap-3 py-3"
            >
              <span class="min-w-0 flex-1">
                <span class="block truncate font-semibold text-surface-900">{{
                  h.banka_adi ?? h.dosya_adi ?? 'Banka hesabı'
                }}</span>
                <span class="block truncate text-xs text-surface-500">
                  <template v-if="h.donem_baslangic || h.donem_bitis"
                    >{{ h.donem_baslangic ? tarih(h.donem_baslangic) : '?' }} –
                    {{ h.donem_bitis ? tarih(h.donem_bitis) : '?' }}</template
                  ><template v-else>{{ h.dosya_adi }}</template>
                </span>
              </span>
              <Tag :value="hesapDurumu(h).etiket" :severity="hesapDurumu(h).renk" />
            </RouterLink>
          </li>
        </ul>
      </section>
    </template>

    <EkstreYukleDialog v-model:visible="yukleAcik" :baslangic-turu="yukleTuru" />
    <ElleEkstreDialog v-model:visible="elleAcik" />
  </div>
</template>
