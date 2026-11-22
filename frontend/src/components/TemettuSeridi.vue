<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import TemettuYattiDialog from '@/components/TemettuYattiDialog.vue'
import { finansApi, type TemettuPlani } from '@/api/finans'
import { bugun, tl } from '@/utils/bicim'

/**
 * Portföy Özet'in tepesindeki temettü şeridi: bekleyen planlı temettünün ödeme gününe 3 gün ve altı kala "N gün sonra",
 * gününde "Bugün yatıyor", günü geçip kaydedilmediyse "yattı mı?" (kapanana ya da "Yattı, kaydet"e kadar durur).
 * "Sonra" aynı günün şeridini gizler (yarın yeniden çıkar).
 */
const emit = defineEmits<{ degisti: [] }>()
const SONRA_ANAHTAR = 'finans-temettu-sonra'
const planlar = ref<TemettuPlani[]>([])
const gizli = ref<Record<string, string>>({})
const yattiAcik = ref(false)
const secilen = ref<TemettuPlani | null>(null)

function gizliOku(): void {
  try {
    gizli.value = JSON.parse(localStorage.getItem(SONRA_ANAHTAR) ?? '{}') as Record<string, string>
  } catch {
    gizli.value = {}
  }
}

async function yukle(): Promise<void> {
  try {
    planlar.value = (await finansApi.temettuPlanlari()).serit
  } catch {
    planlar.value = []
  }
}
onMounted(() => {
  gizliOku()
  void yukle()
})

const gorunenler = computed(() =>
  planlar.value.filter((p) => gizli.value[String(p.id)] !== bugun()),
)

function sonra(p: TemettuPlani): void {
  gizli.value = { ...gizli.value, [String(p.id)]: bugun() }
  try {
    localStorage.setItem(SONRA_ANAHTAR, JSON.stringify(gizli.value))
  } catch {
    // Hatırlanamazsa sayfa yenilenince şerit yine çıkar
  }
}

function yatti(p: TemettuPlani): void {
  secilen.value = p
  yattiAcik.value = true
}

async function kaydedildi(): Promise<void> {
  await yukle()
  emit('degisti')
}

function baslik(p: TemettuPlani): string {
  if (p.gun_kaldi < 0) return `${p.varlik.kod} temettüsü yattı mı?`
  if (p.gun_kaldi === 0) return `Bugün ${p.varlik.kod} temettüsü yatıyor`

  return `${p.gun_kaldi} gün sonra ${p.varlik.kod} temettüsü yatıyor`
}
</script>

<template>
  <div v-if="gorunenler.length" class="flex flex-col gap-2" data-test="temettu-seridi">
    <article
      v-for="p in gorunenler"
      :key="p.id"
      class="flex flex-col gap-1 rounded-2xl bg-amber-50 p-3 ring-1 ring-amber-200"
      data-test="temettu-serit"
    >
      <div class="flex items-center justify-between gap-2">
        <p class="text-sm font-bold text-amber-900" data-test="temettu-serit-baslik">
          {{ baslik(p) }}
        </p>
        <RouterLink
          :to="{ name: 'temettu' }"
          class="shrink-0 text-xs font-semibold text-amber-800 underline"
          >Temettü sayfası</RouterLink
        >
      </div>
      <p class="text-xs text-amber-900">
        {{ p.lot }} lot · yatacak net {{ tl(p.net)
        }}<template v-if="p.oran_yuzde !== null">
          · oran %{{ p.oran_yuzde.toLocaleString('tr-TR') }}</template
        >
      </p>
      <div class="mt-1 flex gap-2">
        <Button label="Yattı, kaydet" size="small" data-test="serit-yatti" @click="yatti(p)" />
        <Button
          :label="p.gun_kaldi < 0 ? 'Henüz değil' : 'Sonra'"
          size="small"
          severity="secondary"
          outlined
          data-test="serit-sonra"
          @click="sonra(p)"
        />
      </div>
    </article>
    <TemettuYattiDialog v-model:visible="yattiAcik" :plan="secilen" @kaydedildi="kaydedildi" />
  </div>
</template>
