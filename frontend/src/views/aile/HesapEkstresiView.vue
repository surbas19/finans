<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import { aileApi, type AiCalisma, type HesapEkstresi, type StandartEkstre } from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { useAnalizTakibi } from '@/composables/useAnalizTakibi'
import { tarih, tl } from '@/utils/bicim'

/**
 * Banka hesabı ekstresi önizlemesi (Bütçe'ye aktarılmaz): yapay zekâ analizinin ilerlemesi, bakiye denetimi
 * (açılış / kapanış, yürüyen bakiye), işlemler (giren / çıkan, bakiye, alınmayan ve incelenecek satırlar), sayfa
 * denetimi, maskeli JSON. PDF analizden sonra silinir.
 */
const props = defineProps<{ id: number }>()
const router = useRouter()
const confirm = useConfirm()
const toast = useToast()

const hesap = ref<HesapEkstresi | null>(null)
const cikti = ref<StandartEkstre | null>(null)
const bulunamadi = ref(false)
const jsonAcik = ref(false)

async function getir(): Promise<AiCalisma | null> {
  const veri = await aileApi.hesapEkstresi(props.id)
  hesap.value = veri.hesap_ekstresi
  cikti.value = veri.ai_cikti

  return veri.calisma
}

const { calisma, izle } = useAnalizTakibi(getir, () => undefined)

onMounted(async () => {
  try {
    izle(await getir())
  } catch {
    bulunamadi.value = true
  }
})

const ASAMA: Record<string, string> = {
  sirada: 'Sırada',
  gonderildi: 'PDF gönderiliyor',
  okunuyor: 'Yapay zekâ okuyor',
  dogrulaniyor: 'Doğrulanıyor',
}
const bakiye = computed(() => hesap.value?.denetim?.bakiye ?? null)
const hataliSiralar = computed(
  () => new Map((bakiye.value?.satir_hatalari ?? []).map((h) => [h.sira, h])),
)
const satirlar = computed(() =>
  (cikti.value?.transactions ?? []).map((t, sira) => ({
    ...t,
    sira,
    denetim: hesap.value?.denetim?.satirlar?.[sira] ?? null,
    bakiyeHatasi: hataliSiralar.value.get(sira) ?? null,
  })),
)

function sil(): void {
  confirm.require({
    header: 'Önizlemeyi sil',
    message: 'Bu banka hesabı ekstresinin önizlemesi silinsin mi?',
    acceptProps: { label: 'Sil', severity: 'danger' },
    rejectProps: { label: 'Vazgeç', severity: 'secondary', text: true },
    accept: async () => {
      try {
        await aileApi.hesapEkstresiSil(props.id)
        toast.add({ severity: 'success', summary: 'Silindi', life: 3000 })
        await router.replace({ name: 'aile-ekstreler' })
      } catch (e) {
        toast.add({ severity: 'error', summary: 'Silinemedi', detail: hataMesaji(e), life: 5000 })
      }
    },
  })
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <Message v-if="bulunamadi" severity="error">Ekstre bulunamadı.</Message>
    <Skeleton v-else-if="!hesap" height="10rem" border-radius="1.5rem" />
    <template v-else>
      <section class="kart p-4 sm:p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <h2 class="truncate text-lg font-bold text-surface-900">
              {{ hesap.banka_adi ?? 'Banka hesabı ekstresi' }}
            </h2>
            <p class="text-xs text-surface-500">
              <template v-if="hesap.donem_baslangic || hesap.donem_bitis"
                >{{ hesap.donem_baslangic ? tarih(hesap.donem_baslangic) : '?' }} –
                {{ hesap.donem_bitis ? tarih(hesap.donem_bitis) : '?' }} · </template
              >{{ hesap.dosya_adi }}
            </p>
          </div>
          <Tag value="Önizleme · Bütçe'ye aktarılmaz" severity="info" />
        </div>

        <div
          v-if="calisma?.etkin"
          class="mt-3 flex items-center gap-2 rounded-2xl bg-sky-50 p-3 text-xs text-sky-900"
          data-test="ai-ilerleme"
          role="status"
        >
          <i class="pi pi-spin pi-spinner" />
          {{ ASAMA[calisma.asama ?? 'sirada'] ?? 'Analiz ediliyor' }}… Sayfadan çıkabilirsiniz;
          analiz arka planda sürer.
        </div>
        <Message
          v-else-if="calisma && ['hata', 'iptal'].includes(calisma.durum)"
          severity="error"
          size="small"
          class="mt-3"
          >{{ calisma.mesaj }} Önizlemeyi silip PDF'i yeniden yükleyebilirsiniz.</Message
        >

        <!-- Bakiye denetimi -->
        <div
          v-if="bakiye"
          data-test="bakiye"
          class="mt-3 rounded-2xl p-3 text-xs"
          :class="
            bakiye.durum === 'tutuyor'
              ? 'bg-emerald-50 text-emerald-800'
              : bakiye.durum === 'tutmuyor'
                ? 'bg-red-50 text-red-800'
                : 'bg-surface-50 text-surface-600'
          "
        >
          <p class="font-semibold">
            Açılış {{ hesap.acilis_bakiyesi !== null ? tl(hesap.acilis_bakiyesi) : '—' }} · Kapanış
            {{ hesap.kapanis_bakiyesi !== null ? tl(hesap.kapanis_bakiyesi) : '—' }} ·
            {{
              bakiye.durum === 'tutuyor'
                ? 'bakiye tutuyor'
                : bakiye.durum === 'tutmuyor'
                  ? 'bakiye tutmuyor'
                  : 'bakiye kontrol edilemedi'
            }}
          </p>
          <ul class="mt-1 list-disc pl-4">
            <li v-for="k in bakiye.kontroller" :key="k.ad">
              {{ k.etiket }}:
              <template v-if="k.durum === 'tutmuyor' && k.fark !== undefined"
                >ekstre {{ k.beklenen }}, satırlar {{ k.bulunan }} (fark {{ k.fark }})</template
              >
              <template v-else-if="k.durum === 'tutmuyor'"
                >{{ k.hatali_satir }} satırda tutmuyor</template
              >
              <template v-else-if="k.durum === 'kontrol_edilemedi'">{{ k.neden }}</template>
              <template v-else>tutuyor</template>
            </li>
          </ul>
        </div>
        <p
          v-for="u in hesap.denetim?.uyarilar ?? []"
          :key="u"
          class="mt-2 text-xs text-surface-600"
        >
          {{ u }}
        </p>
      </section>

      <section v-if="satirlar.length" class="kart p-4 sm:p-5" data-test="hesap-islemleri">
        <h2 class="bolum-etiketi mb-2">{{ satirlar.length }} işlem</h2>
        <ul class="divide-y divide-surface-100">
          <li
            v-for="s in satirlar"
            :key="s.sira"
            class="flex flex-col gap-1 py-2.5"
            :class="{ 'opacity-60': s.denetim?.durum === 'red' }"
          >
            <div class="flex items-start gap-3">
              <span class="min-w-0 flex-1">
                <span class="block font-semibold break-words text-surface-900">{{
                  s.description
                }}</span>
                <span class="block text-xs text-surface-500"
                  >{{ s.date ? tarih(s.date) : 'tarih okunamadı' }} · s.{{ s.source_page
                  }}<template v-if="s.balance !== null">
                    · bakiye {{ tl(s.balance) }}</template
                  ></span
                >
              </span>
              <span
                class="font-semibold whitespace-nowrap tabular-nums"
                :class="s.direction === 'credit' ? 'text-emerald-700' : 'text-surface-900'"
                >{{ s.direction === 'credit' ? '+' : '−' }}{{ tl(s.amount) }}</span
              >
            </div>
            <div class="flex flex-wrap gap-1.5 text-xs">
              <span
                v-if="s.denetim?.durum === 'red'"
                class="rounded-full bg-red-100 px-2 py-0.5 font-semibold text-red-700"
                >Alınmadı: {{ s.denetim.neden }}</span
              >
              <span
                v-for="n in s.denetim?.inceleme ?? []"
                :key="n"
                class="rounded-full bg-amber-100 px-2 py-0.5 text-amber-900"
                >{{ n }}</span
              >
              <span
                v-if="s.bakiyeHatasi"
                class="rounded-full bg-red-100 px-2 py-0.5 text-red-700"
                data-test="bakiye-hatasi"
                >Bakiye tutmuyor: beklenen {{ s.bakiyeHatasi.beklenen }} (fark
                {{ s.bakiyeHatasi.fark }})</span
              >
            </div>
          </li>
        </ul>
      </section>

      <section v-if="cikti" class="kart flex flex-col gap-2 p-4 sm:p-5">
        <ul v-if="hesap.denetim?.sayfa_denetimi" class="text-xs text-surface-600">
          <li v-for="p in hesap.denetim.sayfa_denetimi.sayfalar" :key="p.sayfa">
            Sayfa {{ p.sayfa }}: {{ p.ayristirilan_islem }} / {{ p.bulunan_islem }} işlem<template
              v-if="p.inceleme_gereken"
            >
              · {{ p.inceleme_gereken }} inceleme</template
            >
          </li>
        </ul>
        <Button
          :label="jsonAcik ? 'JSON\'u gizle' : 'JSON\'u göster'"
          icon="pi pi-code"
          size="small"
          severity="secondary"
          outlined
          class="self-start"
          @click="jsonAcik = !jsonAcik"
        />
        <pre
          v-if="jsonAcik"
          class="max-h-80 overflow-auto rounded-xl bg-surface-50 p-2 font-mono text-[11px] whitespace-pre text-surface-800"
          >{{ JSON.stringify(cikti, null, 2) }}</pre>
      </section>

      <div class="flex justify-center">
        <Button label="Önizlemeyi sil" icon="pi pi-trash" severity="danger" text @click="sil" />
      </div>
    </template>
  </div>
</template>
