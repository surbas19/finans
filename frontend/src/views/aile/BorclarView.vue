<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import BosDurum from '@/components/BosDurum.vue'
import BorcFormu from '@/components/aile/BorcFormu.vue'
import BorcOdemeDialog from '@/components/aile/BorcOdemeDialog.vue'
import { aileApi, type Borc } from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { tarih, tl } from '@/utils/bicim'

/**
 * Borç / alacak defteri: kişiyle verilen ya da alınan para, kısmi ödemeler, kalan. Bütçe gelir / gider toplamlarına,
 * ev nakdine ve zekâta karışmaz. Açık kayıtlar önce (vadesi yakın üstte), kapananlar soluk ve sonda.
 */
const confirm = useConfirm()
const toast = useToast()
const borclar = ref<Borc[] | null>(null)
const toplamBorc = ref(0)
const toplamAlacak = ref(0)
const formAcik = ref(false)
const duzenlenen = ref<Borc | null>(null)
const yeniYon = ref<'borc' | 'alacak'>('borc')
const odemeAcik = ref(false)
const odemeBorcu = ref<Borc | null>(null)

async function yukle(): Promise<void> {
  const r = await aileApi.borclar()
  borclar.value = r.borclar
  toplamBorc.value = r.toplam_borc
  toplamAlacak.value = r.toplam_alacak
}
onMounted(yukle)

const net = computed(() => toplamAlacak.value - toplamBorc.value)

function ekle(yon: 'borc' | 'alacak'): void {
  duzenlenen.value = null
  yeniYon.value = yon
  formAcik.value = true
}

function duzenle(b: Borc): void {
  duzenlenen.value = b
  formAcik.value = true
}

function odemeAc(b: Borc): void {
  odemeBorcu.value = b
  odemeAcik.value = true
}

function sil(b: Borc): void {
  confirm.require({
    header: 'Kaydı sil',
    message: `${b.kisi} kaydı ve ödemeleri silinsin mi?`,
    acceptProps: { label: 'Sil', severity: 'danger' },
    rejectProps: { label: 'Vazgeç', severity: 'secondary', text: true },
    accept: async () => {
      try {
        await aileApi.borcSil(b.id)
        toast.add({ severity: 'success', summary: 'Silindi', life: 3000 })
        await yukle()
      } catch (e) {
        toast.add({ severity: 'error', summary: 'Silinemedi', detail: hataMesaji(e), life: 5000 })
      }
    },
  })
}

function oran(b: Borc): number {
  return b.tutar > 0 ? Math.min(100, Math.round((b.odenen / b.tutar) * 100)) : 0
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="flex flex-wrap justify-end gap-2">
      <Button label="Borç ekle" icon="pi pi-plus" data-test="borc-ekle" @click="ekle('borc')" />
      <Button
        label="Alacak ekle"
        icon="pi pi-plus"
        severity="secondary"
        outlined
        data-test="alacak-ekle"
        @click="ekle('alacak')"
      />
    </div>

    <Skeleton v-if="borclar === null" height="8rem" border-radius="1.5rem" />
    <template v-else>
      <section class="kart grid grid-cols-3 gap-2 p-4 sm:p-5" data-test="borc-ozet">
        <div class="rounded-2xl bg-red-50 p-3">
          <p class="text-xs text-red-700">Borcum</p>
          <p class="font-bold text-red-800 tabular-nums" data-test="toplam-borc">
            {{ tl(toplamBorc) }}
          </p>
        </div>
        <div class="rounded-2xl bg-green-50 p-3">
          <p class="text-xs text-green-700">Alacağım</p>
          <p class="font-bold text-green-800 tabular-nums" data-test="toplam-alacak">
            {{ tl(toplamAlacak) }}
          </p>
        </div>
        <div class="rounded-2xl bg-surface-50 p-3">
          <p class="text-xs text-surface-500">Net</p>
          <p class="font-bold tabular-nums" :class="net < 0 ? 'text-red-700' : 'text-surface-900'">
            {{ tl(net) }}
          </p>
        </div>
      </section>

      <section class="kart p-4 sm:p-5" data-test="borc-liste">
        <h2 class="bolum-etiketi mb-2">Kayıtlar</h2>
        <BosDurum
          v-if="!borclar.length"
          kucuk
          baslik="Kayıt yok"
          aciklama="Verdiğiniz ya da aldığınız borcu ekleyin; ödemeler kalan tutarı düşürür."
          ikon="pi pi-users"
        />
        <ul v-else class="divide-y divide-surface-100">
          <li
            v-for="b in borclar"
            :key="b.id"
            class="flex items-center gap-1"
            :class="b.durum === 'kapali' ? 'opacity-60' : ''"
            data-test="borc-satir"
          >
            <button
              type="button"
              class="min-w-0 flex-1 py-3 text-left"
              :data-test="`borc-${b.id}`"
              @click="odemeAc(b)"
            >
              <span class="flex items-center gap-2">
                <span class="truncate font-semibold text-surface-900">{{ b.kisi }}</span>
                <Tag
                  :value="b.yon === 'borc' ? 'Borç' : 'Alacak'"
                  :severity="b.yon === 'borc' ? 'danger' : 'success'"
                />
                <Tag v-if="b.durum === 'kapali'" value="Kapandı" severity="secondary" />
                <Tag v-else-if="b.vade_gecti" value="Vadesi geçti" severity="warn" />
              </span>
              <span class="mt-0.5 block text-xs text-surface-500">
                {{ tarih(b.tarih) }}<template v-if="b.vade"> · vade {{ tarih(b.vade) }}</template
                ><template v-if="b.not"> · {{ b.not }}</template>
              </span>
              <span class="mt-1.5 block h-1.5 overflow-hidden rounded-full bg-surface-100">
                <span
                  class="block h-full rounded-full"
                  :class="b.yon === 'borc' ? 'bg-red-400' : 'bg-green-500'"
                  :style="{ width: `${oran(b)}%` }"
                />
              </span>
            </button>
            <span class="text-right">
              <span class="block font-semibold whitespace-nowrap tabular-nums">{{
                tl(b.kalan)
              }}</span>
              <span class="block text-xs whitespace-nowrap text-surface-500"
                >/ {{ tl(b.tutar) }}</span
              >
            </span>
            <Button
              icon="pi pi-pencil"
              text
              rounded
              size="small"
              severity="secondary"
              aria-label="Düzenle"
              @click="duzenle(b)"
            />
            <Button
              icon="pi pi-trash"
              text
              rounded
              size="small"
              severity="danger"
              aria-label="Sil"
              data-test="borc-sil"
              @click="sil(b)"
            />
          </li>
        </ul>
      </section>
    </template>

    <BorcFormu v-model:visible="formAcik" :borc="duzenlenen" :yon="yeniYon" @kaydedildi="yukle" />
    <BorcOdemeDialog v-model:visible="odemeAcik" :borc="odemeBorcu" @degisti="yukle" />
  </div>
</template>
