<script setup lang="ts">
import { computed, ref } from 'vue'
import Button from 'primevue/button'
import { useToast } from 'primevue/usetoast'
import { aileApi, type Ekstre, type SayfaDenetimi } from '@/api/aile'
import { hataMesaji } from '@/api/http'

/**
 * Yapay zekâ sonucunun denetimi (kart ekstresi): banka ve belge türü tanıma, PDF okuyucu ↔ yapay zekâ çapraz
 * kontrolü (hangi satırlar taslakta kaldı, farklar), kesim tarihi teyidi, sayfa sayfa denetim, doğrulamadan geçmeyen
 * satırlar, uyarılar ve maskeli JSON görünümü.
 */
const props = defineProps<{ ekstre: Ekstre }>()
const toast = useToast()
const json = ref<string | null>(null)
const jsonYukleniyor = ref(false)

const ai = computed(() => props.ekstre.denetim?.ai ?? null)
const capraz = computed(() => props.ekstre.denetim?.capraz ?? null)
const sayfa = computed<SayfaDenetimi | null>(() => ai.value?.sayfa_denetimi ?? null)

const TUR: Record<string, string> = {
  credit_card: 'kredi kartı ekstresi',
  bank_account: 'banka hesabı ekstresi',
  unknown: 'tanınmayan belge',
}
const SAYFA_DURUMU: Record<string, string> = {
  tamam: 'tamam',
  eksik: 'model daha çok satır gördü',
  fazla: 'beklenenden fazla satır',
  metinsiz: 'metin yok (görüntüden okundu)',
  modelde_yok: 'model raporlamadı',
}

async function jsonGoster(): Promise<void> {
  if (json.value !== null) {
    json.value = null

    return
  }
  jsonYukleniyor.value = true
  try {
    json.value = JSON.stringify(await aileApi.ekstreAiJson(props.ekstre.id), null, 2)
  } catch (e) {
    toast.add({ severity: 'error', summary: 'JSON alınamadı', detail: hataMesaji(e), life: 5000 })
  } finally {
    jsonYukleniyor.value = false
  }
}

async function kopyala(): Promise<void> {
  if (json.value === null) return
  try {
    await navigator.clipboard.writeText(json.value)
    toast.add({ severity: 'success', summary: 'JSON kopyalandı', life: 2000 })
  } catch {
    toast.add({ severity: 'warn', summary: 'Kopyalanamadı', life: 3000 })
  }
}
</script>

<template>
  <div
    v-if="ai"
    data-test="ai-denetimi"
    class="mt-3 flex flex-col gap-2 rounded-2xl bg-sky-50 p-3 text-xs text-sky-900"
  >
    <p v-if="ai.harici" class="font-semibold" data-test="harici-rozet">
      <span class="rounded-full bg-sky-200 px-2 py-0.5">Harici JSON ile okundu</span>
      <template v-if="ai.metinsiz"> · PDF'siz: kaynak satırlar doğrulanamadı</template>
      <template v-if="ai.banka"> · {{ ai.banka }}</template>
      <template v-if="sayfa"> · {{ sayfa.pdf_sayfa }} sayfa</template>
    </p>
    <p v-else class="font-semibold">
      Yapay zekâ okudu ({{ ai.model }})
      <template v-if="ai.banka"> · {{ ai.banka }}</template>
      <template v-if="ai.tur"> · {{ TUR[ai.tur] ?? ai.tur }}</template>
      <template v-if="sayfa">
        · {{ sayfa.analiz_edilen_sayfa }} / {{ sayfa.pdf_sayfa }} sayfa</template
      >
    </p>

    <p v-if="ai.oneri" class="rounded-xl bg-amber-50 px-2 py-1 text-amber-900" data-test="ai-oneri">
      PDF okuyucunun satırları korundu: ekstrenin toplamıyla tutuyor, yapay zekâ sonucu
      tutmadı<template v-if="ai.mutabakat?.fark"> ({{ ai.mutabakat.fark }} TL fark)</template>.
      Yapay zekâ sonucu yalnızca öneri; JSON'dan bakabilirsiniz.
    </p>
    <p
      v-if="ekstre.denetim?.kesim_teyit_gerekli"
      class="rounded-xl bg-amber-100 px-2 py-1 font-semibold text-amber-900"
      data-test="kesim-teyit"
    >
      Kesim tarihi PDF okuyucuda ve yapay zekâda farklı okundu. Aşağıdaki dönem bilgilerinden kesim
      tarihini kontrol edip kaydedin; kaydetmeden onaylanmaz.
    </p>

    <!-- Çapraz kontrol -->
    <div v-if="capraz && !ai.harici" data-test="capraz">
      <p v-if="capraz.durum === 'uyumlu'" class="text-emerald-800">
        PDF okuyucu ile yapay zekâ uyumlu: {{ capraz.ai.satir }} satır, toplamlar aynı.
      </p>
      <p v-else-if="capraz.durum === 'php_yok'">
        PDF okuyucu bu ekstreden satır çıkaramadı; yalnızca yapay zekâ sonucu var. Satırları PDF ile
        karşılaştırın.
      </p>
      <template v-else>
        <p class="font-semibold">
          PDF okuyucu ile yapay zekâ farklı ({{
            capraz.secilen === 'php'
              ? 'taslakta PDF okuyucunun satırları'
              : 'taslakta yapay zekânın satırları'
          }}):
        </p>
        <ul class="list-disc pl-4">
          <li v-for="f in capraz.farklar" :key="f">{{ f }}</li>
        </ul>
      </template>
    </div>

    <!-- Sayfa denetimi -->
    <ul v-if="sayfa" class="flex flex-col gap-0.5" data-test="sayfa-denetimi">
      <li v-for="s in sayfa.sayfalar" :key="s.sayfa">
        Sayfa {{ s.sayfa }}:
        <template v-if="s.islem_tablosu_var || s.ayristirilan_islem"
          >{{ s.ayristirilan_islem }} / {{ s.bulunan_islem }} işlem alındı</template
        >
        <template v-else>işlem tablosu yok</template>
        <template v-if="s.php_islem !== null && s.php_islem !== undefined">
          · PDF okuyucu {{ s.php_islem }}</template
        >
        <template v-if="s.inceleme_gereken"> · {{ s.inceleme_gereken }} inceleme</template>
        <span
          v-if="s.durum !== 'tamam'"
          class="ml-1 rounded-full bg-amber-100 px-1.5 text-amber-900"
          >{{ SAYFA_DURUMU[s.durum] ?? s.durum }}</span
        >
      </li>
    </ul>

    <template v-if="ai.reddedilen.length">
      <p class="font-semibold">Doğrulamadan geçmeyen {{ ai.reddedilen.length }} satır (alınmadı)</p>
      <p v-for="(r, i) in ai.reddedilen" :key="i" class="break-words">
        Sayfa {{ r.sayfa }}: {{ r.neden }} <span class="font-mono">{{ r.kaynak }}</span>
      </p>
    </template>
    <p v-for="u in ai.uyarilar" :key="u">{{ u }}</p>

    <div class="flex flex-wrap gap-2">
      <Button
        :label="json === null ? 'JSON\'u göster' : 'JSON\'u gizle'"
        icon="pi pi-code"
        size="small"
        severity="secondary"
        outlined
        :loading="jsonYukleniyor"
        data-test="json-goster"
        @click="jsonGoster"
      />
      <Button
        v-if="json !== null"
        label="Kopyala"
        icon="pi pi-copy"
        size="small"
        severity="secondary"
        text
        @click="kopyala"
      />
    </div>
    <pre
      v-if="json !== null"
      data-test="json"
      class="max-h-80 overflow-auto rounded-xl bg-white p-2 font-mono text-[11px] whitespace-pre text-surface-800"
      >{{ json }}</pre>
    <p v-if="json !== null" class="text-[11px] text-sky-800">
      Kart / IBAN / telefon / kimlik numarası maskelidir.
    </p>
  </div>
</template>
