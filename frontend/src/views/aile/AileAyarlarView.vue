<script setup lang="ts">
import { onMounted, ref } from 'vue'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import ToggleSwitch from 'primevue/toggleswitch'
import { useToast } from 'primevue/usetoast'
import { aileApi, type HaneUyesi, type Saglayici } from '@/api/aile'
import { hataMesaji } from '@/api/http'
import { useAileStore } from '@/stores/aile'

/**
 * Bütçe ayarları: hane ve üyeler (girişsiz üye, ör. çocuk), bankalar, harcama kategorileri ve kuralları, fatura
 * türleri, fatura sağlayıcıları (ekstrede görünen yazımları, otomatik kayıt).
 */
const aile = useAileStore()
const toast = useToast()

const haneAdi = ref('')
const yeniUye = ref('')
const yeniKategori = ref('')
const yeniKural = ref<Record<number, string>>({})
const yeniTur = ref('')
const yeniDesen = ref<Record<number, string>>({})
const yeniSaglayici = ref({
  ad: '',
  fatura_turu_id: null as number | null,
  otomatik_kayit: false,
  desen: '',
})

onMounted(async () => {
  await aile.yukle()
  haneAdi.value = aile.hane?.hane.ad ?? ''
  yeniSaglayici.value.fatura_turu_id = aile.turler[0]?.id ?? null
})

/** İşlemi yapar, tanımları yeniler, hatayı bildirir */
async function yap(islem: () => Promise<unknown>, basari?: string): Promise<boolean> {
  try {
    await islem()
    await aile.yukle(true)
    if (basari) toast.add({ severity: 'success', summary: basari, life: 2500 })

    return true
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Yapılamadı', detail: hataMesaji(e), life: 5000 })

    return false
  }
}

async function uyeEkle(): Promise<void> {
  if (await yap(() => aileApi.uyeEkle({ ad: yeniUye.value.trim() }), 'Üye eklendi'))
    yeniUye.value = ''
}

async function uyeSil(u: HaneUyesi): Promise<void> {
  try {
    const sonuc = await aileApi.uyeSil(u.id)
    toast.add({ severity: 'success', summary: sonuc.message, life: 3500 })
    await aile.yukle(true)
  } catch (e) {
    toast.add({ severity: 'error', summary: 'Yapılamadı', detail: hataMesaji(e), life: 5000 })
  }
}

async function kategoriEkle(): Promise<void> {
  if (await yap(() => aileApi.kategoriEkle(yeniKategori.value.trim()), 'Kategori eklendi'))
    yeniKategori.value = ''
}

async function kuralEkle(kategoriId: number): Promise<void> {
  const desen = (yeniKural.value[kategoriId] ?? '').trim()
  if (desen.length < 2) return
  if (await yap(() => aileApi.kuralEkle(kategoriId, desen))) yeniKural.value[kategoriId] = ''
}

async function turEkle(): Promise<void> {
  if (await yap(() => aileApi.faturaTuruEkle(yeniTur.value.trim()), 'Fatura türü eklendi'))
    yeniTur.value = ''
}

async function saglayiciEkle(): Promise<void> {
  const s = yeniSaglayici.value
  if (!s.fatura_turu_id) return
  const ok = await yap(
    () =>
      aileApi.saglayiciEkle({
        ad: s.ad.trim(),
        fatura_turu_id: s.fatura_turu_id!,
        otomatik_kayit: s.otomatik_kayit,
        desenler: s.desen.trim() ? [s.desen.trim()] : [],
      }),
    'Sağlayıcı eklendi',
  )
  if (ok)
    yeniSaglayici.value = {
      ad: '',
      fatura_turu_id: s.fatura_turu_id,
      otomatik_kayit: false,
      desen: '',
    }
}

function saglayiciGuncelle(s: Saglayici, degisiklik: Partial<Saglayici>): void {
  void yap(() =>
    aileApi.saglayiciGuncelle(s.id, { ad: s.ad, fatura_turu_id: s.fatura_turu_id, ...degisiklik }),
  )
}

async function desenEkle(s: Saglayici): Promise<void> {
  const desen = (yeniDesen.value[s.id] ?? '').trim()
  if (desen.length < 2) return
  if (await yap(() => aileApi.desenEkle(s.id, desen))) yeniDesen.value[s.id] = ''
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <!-- Hane ve üyeler -->
    <section class="kart p-4 sm:p-5">
      <h2 class="bolum-etiketi mb-3">Hane</h2>
      <form
        class="flex gap-2"
        @submit.prevent="yap(() => aileApi.haneAdi(haneAdi.trim()), 'Hane adı kaydedildi')"
      >
        <InputText v-model="haneAdi" maxlength="60" class="min-w-0 flex-1" aria-label="Hane adı" />
        <Button
          type="submit"
          label="Kaydet"
          severity="secondary"
          outlined
          :disabled="haneAdi.trim().length < 1"
        />
      </form>
      <h3 class="mt-4 mb-2 text-sm font-semibold text-surface-800">Üyeler</h3>
      <p class="mb-2 text-xs text-surface-500">
        Girişi olan kişileri (ör. eşinizi) yönetici haneye bağlar; girişi olmayanları (ör. çocuk)
        buradan ekleyin.
      </p>
      <ul class="divide-y divide-surface-100">
        <li v-for="u in aile.uyeler" :key="u.id" class="flex items-center gap-2 py-2">
          <span
            class="min-w-0 flex-1 truncate"
            :class="u.aktif ? 'text-surface-900' : 'text-surface-400'"
            >{{ u.ad }}</span
          >
          <Tag v-if="u.user_id" value="Girişi var" severity="info" />
          <Tag v-if="!u.aktif" value="Pasif" severity="secondary" />
          <Button
            v-if="!u.user_id && !u.aktif"
            label="Etkinleştir"
            size="small"
            text
            @click="yap(() => aileApi.uyeGuncelle(u.id, { ad: u.ad, aktif: true }))"
          />
          <Button
            v-if="!u.user_id && u.aktif"
            icon="pi pi-trash"
            size="small"
            text
            severity="danger"
            :aria-label="`${u.ad} üyesini sil`"
            @click="uyeSil(u)"
          />
        </li>
      </ul>
      <form class="mt-2 flex gap-2" @submit.prevent="uyeEkle">
        <InputText
          v-model="yeniUye"
          maxlength="60"
          placeholder="Girişsiz üye adı"
          class="min-w-0 flex-1"
          aria-label="Yeni üye adı"
        />
        <Button
          type="submit"
          icon="pi pi-plus"
          label="Ekle"
          :disabled="yeniUye.trim().length < 1"
        />
      </form>
    </section>

    <!-- Fatura sağlayıcıları -->
    <section class="kart p-4 sm:p-5">
      <h2 class="bolum-etiketi mb-1">Fatura sağlayıcıları</h2>
      <p class="mb-3 text-xs text-surface-500">
        Ekstrede fatura ödemesi bu adlarla ve yazımlarla aranır (ör. "IGDAS", "ISKI SU"). Otomatik
        kayıt açıksa güvenilir eşleşme onay beklemeden faturaya yazılır; belirsizler her zaman size
        sorulur.
      </p>
      <ul class="flex flex-col gap-3">
        <li
          v-for="s in aile.saglayicilar"
          :key="s.id"
          class="rounded-2xl p-3 ring-1 ring-surface-200"
          data-test="saglayici"
        >
          <div class="flex flex-wrap items-center gap-2">
            <span
              class="font-semibold"
              :class="s.aktif ? 'text-surface-900' : 'text-surface-400'"
              >{{ s.ad }}</span
            >
            <span class="text-xs text-surface-500">{{ aile.tur(s.fatura_turu_id)?.ad }}</span>
            <label class="ml-auto flex items-center gap-1.5 text-xs text-surface-600">
              <ToggleSwitch
                :model-value="s.otomatik_kayit"
                @update:model-value="(d: boolean) => saglayiciGuncelle(s, { otomatik_kayit: d })"
              />
              Otomatik kayıt
            </label>
            <label class="flex items-center gap-1.5 text-xs text-surface-600">
              <ToggleSwitch
                :model-value="s.aktif"
                @update:model-value="(d: boolean) => saglayiciGuncelle(s, { aktif: d })"
              />
              Aktif
            </label>
            <Button
              icon="pi pi-trash"
              size="small"
              text
              severity="danger"
              :aria-label="`${s.ad} sağlayıcısını sil`"
              @click="yap(() => aileApi.saglayiciSil(s.id), 'Sağlayıcı silindi')"
            />
          </div>
          <div class="mt-2 flex flex-wrap items-center gap-1.5">
            <span
              v-for="d in s.desenler"
              :key="d.id"
              class="inline-flex items-center gap-1 rounded-full bg-surface-100 py-0.5 pr-1 pl-2.5 text-xs font-mono"
            >
              {{ d.desen }}
              <button
                type="button"
                class="rounded-full px-1 text-surface-500 hover:text-red-600"
                :aria-label="`${d.desen} yazımını sil`"
                @click="yap(() => aileApi.desenSil(d.id))"
              >
                ×
              </button>
            </span>
            <form class="flex gap-1" @submit.prevent="desenEkle(s)">
              <InputText
                v-model="yeniDesen[s.id]"
                size="small"
                placeholder="Ekstredeki yazım"
                maxlength="60"
                class="w-36"
                :aria-label="`${s.ad} için yeni yazım`"
              />
              <Button type="submit" icon="pi pi-plus" size="small" text aria-label="Yazımı ekle" />
            </form>
          </div>
        </li>
      </ul>
      <form
        class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-[1fr_9rem_1fr_auto]"
        @submit.prevent="saglayiciEkle"
      >
        <InputText
          v-model="yeniSaglayici.ad"
          placeholder="Sağlayıcı (ör. İGDAŞ)"
          maxlength="60"
          aria-label="Sağlayıcı adı"
        />
        <Select
          v-model="yeniSaglayici.fatura_turu_id"
          :options="aile.turler"
          option-label="ad"
          option-value="id"
          aria-label="Fatura türü"
        />
        <InputText
          v-model="yeniSaglayici.desen"
          placeholder="Ekstredeki yazımı (isteğe bağlı)"
          maxlength="60"
          aria-label="Ekstredeki yazım"
        />
        <Button
          type="submit"
          icon="pi pi-plus"
          label="Ekle"
          :disabled="yeniSaglayici.ad.trim().length < 2"
        />
      </form>
    </section>

    <!-- Fatura türleri -->
    <section class="kart p-4 sm:p-5">
      <h2 class="bolum-etiketi mb-2">Fatura türleri</h2>
      <div class="flex flex-wrap gap-1.5">
        <span
          v-for="t in aile.turler"
          :key="t.id"
          class="inline-flex items-center gap-1.5 rounded-full bg-surface-100 px-3 py-1 text-sm"
        >
          <i :class="t.ikon ?? 'pi pi-file'" class="text-xs" /> {{ t.ad }}
          <button
            v-if="!t.sistem"
            type="button"
            class="text-surface-500 hover:text-red-600"
            :aria-label="`${t.ad} türünü sil`"
            @click="yap(() => aileApi.faturaTuruSil(t.id))"
          >
            ×
          </button>
        </span>
      </div>
      <form class="mt-2 flex gap-2" @submit.prevent="turEkle">
        <InputText
          v-model="yeniTur"
          placeholder="Yeni tür (ör. İnternet, Aidat)"
          maxlength="40"
          class="min-w-0 flex-1"
          aria-label="Yeni fatura türü"
        />
        <Button
          type="submit"
          icon="pi pi-plus"
          label="Ekle"
          severity="secondary"
          outlined
          :disabled="yeniTur.trim().length < 2"
        />
      </form>
    </section>

    <!-- Kategoriler -->
    <section class="kart p-4 sm:p-5">
      <h2 class="bolum-etiketi mb-1">Harcama kategorileri</h2>
      <p class="mb-3 text-xs text-surface-500">
        Açıklamada kelime geçen kart satırı o kategoriye girer (ör. "MIGROS" → Market).
      </p>
      <ul class="flex flex-col gap-2">
        <li
          v-for="k in aile.kategoriler"
          :key="k.id"
          class="rounded-2xl p-3 ring-1 ring-surface-200"
        >
          <div class="flex items-center gap-2">
            <i :class="k.ikon ?? 'pi pi-tag'" class="text-surface-500" />
            <span class="flex-1 font-semibold">{{ k.ad }}</span>
            <Button
              v-if="!k.fatura"
              icon="pi pi-trash"
              size="small"
              text
              severity="danger"
              :aria-label="`${k.ad} kategorisini sil`"
              @click="yap(() => aileApi.kategoriSil(k.id), 'Kategori silindi')"
            />
          </div>
          <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
            <span
              v-for="r in k.kurallar ?? []"
              :key="r.id"
              class="inline-flex items-center gap-1 rounded-full bg-surface-100 py-0.5 pr-1 pl-2.5 text-xs font-mono"
            >
              {{ r.desen }}
              <button
                type="button"
                class="rounded-full px-1 text-surface-500 hover:text-red-600"
                :aria-label="`${r.desen} kuralını sil`"
                @click="yap(() => aileApi.kuralSil(r.id))"
              >
                ×
              </button>
            </span>
            <form class="flex gap-1" @submit.prevent="kuralEkle(k.id)">
              <InputText
                v-model="yeniKural[k.id]"
                size="small"
                placeholder="Kelime"
                maxlength="60"
                class="w-28"
                :aria-label="`${k.ad} için kelime`"
              />
              <Button
                type="submit"
                icon="pi pi-plus"
                size="small"
                text
                aria-label="Kelimeyi ekle"
              />
            </form>
          </div>
        </li>
      </ul>
      <form class="mt-2 flex gap-2" @submit.prevent="kategoriEkle">
        <InputText
          v-model="yeniKategori"
          placeholder="Yeni kategori"
          maxlength="40"
          class="min-w-0 flex-1"
          aria-label="Yeni kategori"
        />
        <Button
          type="submit"
          icon="pi pi-plus"
          label="Ekle"
          severity="secondary"
          outlined
          :disabled="yeniKategori.trim().length < 2"
        />
      </form>
    </section>

    <!-- Bankalar -->
    <section class="kart p-4 sm:p-5">
      <h2 class="bolum-etiketi mb-2">Bankalar</h2>
      <p v-if="!aile.bankalar.length" class="text-sm text-surface-500">
        Banka, kart eklerken eklenir.
      </p>
      <ul class="divide-y divide-surface-100">
        <li v-for="b in aile.bankalar" :key="b.id" class="flex items-center gap-2 py-2">
          <span class="flex-1">{{ b.ad }}</span>
          <Button
            icon="pi pi-trash"
            size="small"
            text
            severity="danger"
            :aria-label="`${b.ad} bankasını sil`"
            @click="yap(() => aileApi.bankaSil(b.id), 'Banka silindi')"
          />
        </li>
      </ul>
    </section>
  </div>
</template>
