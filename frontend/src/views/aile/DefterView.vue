<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Skeleton from 'primevue/skeleton'
import BosDurum from '@/components/BosDurum.vue'
import UygulamaPenceresi from '@/components/UygulamaPenceresi.vue'
import AySecici from '@/components/aile/AySecici.vue'
import { aileApi, type DefterSatiri, type DefterYaniti } from '@/api/aile'
import { buAy } from '@/utils/donem'
import { miktar, tarih, tarihSaat, tl } from '@/utils/bicim'

/**
 * Defter: ayın kayıtlarını el yazısı görünümlü, salt okunur gösterir. Telefonda sade: yalnız iki ana bölüm (Kalemler,
 * Kayıtlar); Temettü, Borç / Alacak ve Sonradan değişiklikler üstteki üç düğmeyle alt sayfada açılır. Kayıtlar tek
 * tarih sıralı listedir (harcama türü sekmesi yoktur). Kapanmış ay mühürlüdür; kapanıştan sonraki ekleme / düzeltme /
 * silme "Sonradan değişiklikler"dedir ve ay toplamlarına katılmaz. Düzenle / sil düğmesi yoktur.
 */
type Bolum = 'kalem' | 'kayit'
type Sayfa = 'temettu' | 'borc' | 'sonradan'
type Suzgec = 'hepsi' | 'gelir' | 'gider' | 'tasarruf'

const donem = ref(buAy())
const yanit = ref<DefterYaniti | null>(null)
const hata = ref(false)
const bolum = ref<Bolum>('kalem')
const suzgec = ref<Suzgec>('hepsi')
const sayfa = ref<Sayfa | null>(null)

async function yukle(): Promise<void> {
  yanit.value = null
  hata.value = false
  try {
    yanit.value = await aileApi.defter(donem.value)
  } catch {
    hata.value = true
  }
}
watch(donem, yukle, { immediate: true })

const defter = computed(() => yanit.value?.defter ?? null)
const degisiklikler = computed(() => yanit.value?.degisiklikler ?? [])
const kalemler = computed(() => defter.value?.yatirim.kalemler ?? [])

const sayfaAcik = computed({
  get: () => sayfa.value !== null,
  set: (v: boolean) => {
    if (!v) sayfa.value = null
  },
})
const SAYFA_BASLIGI: Record<Sayfa, string> = {
  temettu: 'Temettü',
  borc: 'Borç / Alacak',
  sonradan: 'Sonradan değişiklikler',
}

const toplam = computed(() => {
  const d0 = kalemler.value.reduce((t, k) => t + (k.deger_ilk ?? 0), 0)
  const d1 = kalemler.value.reduce((t, k) => t + (k.deger_son ?? 0), 0)
  const fe = kalemler.value.reduce((t, k) => t + (k.fiyat_etkisi ?? 0), 0)
  const net = kalemler.value.reduce((t, k) => t + k.net_islem, 0)
  const eksik = kalemler.value.some((k) => k.deger_son === null || k.deger_ilk === null)

  return { d0, d1, fe, net, eksik }
})

const giderEtkisi = computed(() =>
  degisiklikler.value
    .filter((d) => d.tur === 'gider')
    .reduce(
      (t, d) =>
        t +
        (d.tip === 'eklendi'
          ? (d.yeni_tutar ?? 0)
          : d.tip === 'silindi'
            ? -(d.eski_tutar ?? 0)
            : (d.yeni_tutar ?? 0) - (d.eski_tutar ?? 0)),
      0,
    ),
)

/** Tüm kayıtlar tek liste, tarih sırasıyla; tür (gelir / gider / tasarruf) yalnız işareti ve rengi belirler */
const kayitlar = computed(() =>
  (defter.value?.gruplar ?? [])
    .flatMap((g) => g.satirlar.map((s) => ({ ...s, tur: g.tur })))
    .sort((a, b) => (a.tarih ?? '9999').localeCompare(b.tarih ?? '9999')),
)
const gorunenKayitlar = computed(() =>
  kayitlar.value.filter((k) => suzgec.value === 'hepsi' || k.tur === suzgec.value),
)
const SUZGECLER: { id: Suzgec; ad: string }[] = [
  { id: 'hepsi', ad: 'Hepsi' },
  { id: 'gelir', ad: 'Gelir' },
  { id: 'gider', ad: 'Gider' },
  { id: 'tasarruf', ad: 'Tasarruf' },
]

const TUR_ETIKETI: Record<string, string> = {
  gelir: 'Gelir',
  gider: 'Gider',
  tasarruf: 'Tasarruf',
  borc: 'Borç / alacak',
  borc_odemesi: 'Borç ödemesi',
  portfoy_islemi: 'Portföy işlemi',
}
const TIP_ETIKETI = { eklendi: 'EKLENDİ', duzeltildi: 'DÜZELTİLDİ', silindi: 'SİLİNDİ' } as const
const TIP_RENK = { eklendi: '#1b7f4b', duzeltildi: '#c2570c', silindi: '#c0252d' } as const

/** "2026-11-13" → "13.11" */
function gun(t: string | null): string {
  return t ? `${t.slice(8, 10)}.${t.slice(5, 7)}` : '—'
}
const isaret = (n: number): string => (n > 0 ? '+' : n < 0 ? '−' : '')
const mutlakTl = (n: number): string => tl(Math.abs(n))
/** Üst şerit: kuruşsuz, dar ekranda sığsın */
const tamTl = (n: number): string =>
  `${n < 0 ? '−' : ''}₺${Math.abs(n).toLocaleString('tr-TR', { maximumFractionDigits: 0 })}`
const sinif = (n: number): string => (n > 0 ? 'art' : n < 0 ? 'azal' : 'notr')
const ok = (n: number): string => (n > 0 ? '▲' : n < 0 ? '▼' : '■')
const yz = (x: number): string =>
  '%' +
  (Math.abs(x) * 100).toLocaleString('tr-TR', {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
  })
const satirIsareti = (tur: string): string => (tur === 'gelir' ? '+' : '−')
const satirSinifi = (tur: string): string =>
  tur === 'gelir' ? 'art' : tur === 'tasarruf' ? 'notr' : 'azal'
const satirNotu = (s: DefterSatiri): string => [s.yazan, s.not].filter(Boolean).join(' · ')

const enYukselen = computed(
  () =>
    [...kalemler.value]
      .filter((k) => k.fiyat_ilk && k.fiyat_son)
      .sort((a, b) => b.fiyat_son! / b.fiyat_ilk! - a.fiyat_son! / a.fiyat_ilk!)[0] ?? null,
)
const enDusen = computed(
  () =>
    [...kalemler.value]
      .filter((k) => k.fiyat_ilk && k.fiyat_son)
      .sort((a, b) => a.fiyat_son! / a.fiyat_ilk! - b.fiyat_son! / b.fiyat_ilk!)[0] ?? null,
)

function degisimMetni(d: {
  tip: string
  eski_tutar: number | null
  yeni_tutar: number | null
}): string {
  if (d.tip === 'duzeltildi') return `${tl(d.eski_tutar)} → ${tl(d.yeni_tutar)}`

  return tl(d.tip === 'silindi' ? d.eski_tutar : d.yeni_tutar)
}
</script>

<template>
  <div class="flex flex-col gap-3" data-test="defter">
    <AySecici v-model="donem" class="ay-carki-yapiskan" />

    <div v-if="hata" class="kart p-4 text-sm text-red-600">
      Defter yüklenemedi. Sayfayı yenileyin.
    </div>
    <div v-else-if="!yanit || !defter" class="flex flex-col gap-3">
      <Skeleton height="4rem" border-radius="1rem" />
      <Skeleton height="16rem" border-radius="1rem" />
    </div>

    <template v-else>
      <p class="text-center text-xs text-surface-500" data-test="defter-durum">
        <template v-if="yanit.kapali"
          ><i class="pi pi-lock mr-1" />{{ defter.etiket }} kapandı, defter mühürlü. Değiştirilemez
          ve silinemez.</template
        >
        <template v-else
          ><i class="pi pi-pencil mr-1" />{{ defter.etiket }} henüz bitmedi; defter canlı görünüyor,
          ay bitince mühürlenir.</template
        >
      </p>

      <!-- Üç sayı -->
      <section class="d3" data-test="defter-serit">
        <div>
          <small>GELİR</small><b class="art">{{ tamTl(defter.ozet.gelir) }}</b>
        </div>
        <div>
          <small>GİDER</small><b class="azal">{{ tamTl(defter.ozet.gider) }}</b>
        </div>
        <div>
          <small>KALAN</small
          ><b :class="sinif(defter.ozet.kalan)">{{ tamTl(defter.ozet.kalan) }}</b>
        </div>
      </section>

      <!-- İkinci derece bilgi: alt sayfada -->
      <div class="altcip" data-test="defter-alt-dugmeler">
        <button type="button" data-test="defter-sayfa-temettu" @click="sayfa = 'temettu'">
          Temettü<small>+{{ mutlakTl(defter.temettu.toplam) }}</small>
        </button>
        <button type="button" data-test="defter-sayfa-borc" @click="sayfa = 'borc'">
          Borç / Alacak<small
            >net {{ isaret(defter.borclar.alacak - defter.borclar.borc)
            }}{{ mutlakTl(defter.borclar.alacak - defter.borclar.borc) }}</small
          >
        </button>
        <button type="button" data-test="defter-sayfa-sonradan" @click="sayfa = 'sonradan'">
          Sonradan<small data-test="serit-sonradan">{{ degisiklikler.length }} değişiklik</small>
        </button>
      </div>

      <div class="seg" role="tablist">
        <button
          type="button"
          role="tab"
          :aria-selected="bolum === 'kalem'"
          data-test="defter-sekme-kalem"
          @click="bolum = 'kalem'"
        >
          Kalemler
        </button>
        <button
          type="button"
          role="tab"
          :aria-selected="bolum === 'kayit'"
          data-test="defter-sekme-kayit"
          @click="bolum = 'kayit'"
        >
          Kayıtlar
        </button>
      </div>

      <div class="kagit sayfa" data-test="defter-sayfa">
        <!-- Kalemler -->
        <template v-if="bolum === 'kalem'">
          <p v-if="kalemler.length" class="ay-ozeti" data-test="defter-ay-ozeti">
            Yatırım değeri <b>{{ tl(toplam.d0) }}</b> iken <b>{{ tl(toplam.d1) }}</b> oldu:
            <b :class="sinif(toplam.d1 - toplam.d0)"
              >{{ ok(toplam.d1 - toplam.d0) }} {{ isaret(toplam.d1 - toplam.d0)
              }}{{ mutlakTl(toplam.d1 - toplam.d0)
              }}<template v-if="toplam.d0">
                ({{ yz((toplam.d1 - toplam.d0) / toplam.d0) }})</template
              ></b
            >.
            <template v-if="toplam.eksik">Fiyatı bulunamayan kalemler toplama girmedi. </template>
            Bunun
            <b :class="sinif(toplam.net)">{{ isaret(toplam.net) }}{{ mutlakTl(toplam.net) }}</b>
            kısmı sizin alım / satımınız,
            <b :class="sinif(toplam.fe)">{{ isaret(toplam.fe) }}{{ mutlakTl(toplam.fe) }}</b> kısmı
            fiyat hareketi.
            <template v-if="enYukselen && enDusen && enYukselen !== enDusen">
              En çok yükselen
              <b class="art"
                >{{ enYukselen.kod }} {{ yz(enYukselen.fiyat_son! / enYukselen.fiyat_ilk! - 1) }}</b
              >, en çok düşen
              <b class="azal"
                >{{ enDusen.kod }} {{ yz(enDusen.fiyat_son! / enDusen.fiyat_ilk! - 1) }}</b
              >.
            </template>
          </p>
          <BosDurum
            v-if="!kalemler.length"
            kucuk
            baslik="Bu ay kalem yok"
            aciklama="Hesaplarda bu ay adet ya da işlem olan varlık görünmüyor."
          />
          <article v-for="k in kalemler" :key="k.varlik_id" class="kal" data-test="defter-kalem">
            <div class="b">
              <h4>{{ k.kod }}</h4>
              <span
                v-if="k.deger_ilk !== null && k.deger_son !== null && k.deger_ilk"
                class="v"
                :class="sinif(k.deger_son - k.deger_ilk)"
                >{{ ok(k.deger_son - k.deger_ilk) }}
                {{ yz((k.deger_son - k.deger_ilk) / k.deger_ilk) }}</span
              >
            </div>
            <p>
              {{ miktar(k.ilk) }} → {{ miktar(k.son) }} {{ k.birim
              }}<template v-if="k.son !== k.ilk">
                (<span :class="sinif(k.son - k.ilk)"
                  >{{ isaret(k.son - k.ilk) }}{{ miktar(Math.abs(k.son - k.ilk)) }}</span
                >)</template
              ><template v-else> · değişmedi</template>
              <template v-for="i in k.islemler" :key="i.tarih + i.adet">
                · {{ gun(i.tarih) }} {{ i.adet > 0 ? 'alım' : 'satım' }}
                {{ miktar(Math.abs(i.adet)) }}
                <template v-if="i.fiyat !== null">× {{ tl(i.fiyat) }}</template>
              </template>
            </p>
            <p v-if="k.fiyat_ilk !== null && k.fiyat_son !== null">
              Birim {{ tl(k.fiyat_ilk) }} → {{ tl(k.fiyat_son) }}
              <span :class="sinif(k.fiyat_son - k.fiyat_ilk)">{{
                ok(k.fiyat_son - k.fiyat_ilk)
              }}</span>
            </p>
            <p v-if="k.deger_ilk !== null && k.deger_son !== null">
              {{ tl(k.deger_ilk) }} → <b>{{ tl(k.deger_son) }}</b>
            </p>
            <p v-else class="notr">fiyat yok</p>
            <p v-if="k.deger_ilk !== null && k.deger_son !== null" class="kirilim">
              <span :class="sinif(k.net_islem)"
                >{{ k.net_islem >= 0 ? 'Alımınız' : 'Satışınız' }} {{ isaret(k.net_islem)
                }}{{ mutlakTl(k.net_islem) }}</span
              >
              <span :class="sinif(k.fiyat_etkisi ?? 0)"
                >Fiyat hareketi {{ isaret(k.fiyat_etkisi ?? 0)
                }}{{ mutlakTl(k.fiyat_etkisi ?? 0) }}</span
              >
              <span v-if="k.temettu" class="art">Temettü +{{ mutlakTl(k.temettu) }} (nakde)</span>
            </p>
          </article>
        </template>

        <!-- Kayıtlar -->
        <template v-else>
          <div class="cipler" role="tablist">
            <button
              v-for="s in SUZGECLER"
              :key="s.id"
              type="button"
              role="tab"
              :aria-selected="suzgec === s.id"
              :data-test="`defter-filtre-${s.id}`"
              @click="suzgec = s.id"
            >
              {{ s.ad }}
            </button>
          </div>
          <BosDurum
            v-if="!gorunenKayitlar.length"
            kucuk
            baslik="Kayıt yok"
            aciklama="Bu ay için bu türde kayıt görünmüyor."
          />
          <div v-for="(s, i) in gorunenKayitlar" :key="i" class="kay" data-test="defter-kayit">
            <span class="g">{{ gun(s.tarih) }}</span>
            <span class="a"
              >{{ s.ad }}<small>{{ satirNotu(s) }}</small></span
            >
            <span class="t" :class="satirSinifi(s.tur)"
              >{{ satirIsareti(s.tur) }}{{ mutlakTl(s.tutar) }}</span
            >
          </div>
        </template>
      </div>

      <p class="text-xs text-surface-500">
        Her satırın altındaki not: kaydı kim, ne zaman girdi. Silinen kayıtların tamamı
        <RouterLink :to="{ name: 'aile-silinenler' }" class="underline">Silinenler</RouterLink>
        sayfasında.
      </p>

      <!-- Alt sayfa: Temettü / Borç / Sonradan -->
      <UygulamaPenceresi
        v-model:visible="sayfaAcik"
        :baslik="sayfa ? SAYFA_BASLIGI[sayfa] : ''"
        :genislik="30"
      >
        <div class="kagit alt-sayfa" data-test="defter-alt-sayfa">
          <template v-if="sayfa === 'temettu'">
            <BosDurum
              v-if="!defter.temettu.satirlar.length"
              kucuk
              baslik="Bu ay temettü yok"
              aciklama="Hisse hesaplarına bu ay temettü yatmadı."
            />
            <div v-for="(s, i) in defter.temettu.satirlar" :key="i" class="kay">
              <span class="g">{{ gun(s.tarih) }}</span>
              <span class="a"
                >{{ s.ad }}<small>{{ satirNotu(s) }}</small></span
              >
              <span class="t art">+{{ mutlakTl(s.tutar) }}</span>
            </div>
            <p class="alt-not">Temettü hisse hesabının nakdine yazılır, bütçe gelirine girmez.</p>
          </template>

          <template v-else-if="sayfa === 'borc'">
            <BosDurum
              v-if="!defter.borclar.kayitlar.length"
              kucuk
              baslik="Bu ay borç / alacak hareketi yok"
              aciklama="Bu ay açılan ya da ödemesi yapılan kayıt görünmüyor."
            />
            <template v-for="(b, i) in defter.borclar.kayitlar" :key="i">
              <div class="kay">
                <span class="g">{{ gun(b.tarih) }}</span>
                <span class="a"
                  >{{
                    b.yon === 'alacak'
                      ? `${b.kisi}'e verdim (alacak)`
                      : `${b.kisi}'den aldım (borç)`
                  }}<small>{{ b.yazan }}</small></span
                >
                <span class="t" :class="b.yon === 'alacak' ? 'art' : 'azal'">{{
                  mutlakTl(b.tutar)
                }}</span>
              </div>
              <div v-for="(o, j) in b.odemeler" :key="j" class="kay alt-kay">
                <span class="g">{{ gun(o.tarih) }}</span>
                <span class="a"
                  >{{ b.yon === 'alacak' ? 'Tahsilat' : 'Ödeme' }}<small>{{ o.yazan }}</small></span
                >
                <span class="t" :class="b.yon === 'alacak' ? 'art' : 'azal'"
                  >−{{ mutlakTl(o.tutar) }}</span
                >
              </div>
              <div class="kalan" :class="b.yon === 'alacak' ? 'art' : 'azal'">
                Kalan {{ mutlakTl(b.kalan) }}
              </div>
            </template>
            <p class="alt-not">
              Alacağım <b class="art">{{ tl(defter.borclar.alacak) }}</b
              >, borcum <b class="azal">{{ tl(defter.borclar.borc) }}</b
              >. Bütçe gelir ve giderine, ev nakdine ve zekâta karışmaz.
            </p>
          </template>

          <template v-else-if="sayfa === 'sonradan'">
            <p class="alt-not">
              Ay kapandıktan sonra yapılanlar burada toplanır; ay toplamlarına katılmaz.
            </p>
            <BosDurum
              v-if="!degisiklikler.length"
              kucuk
              baslik="Sonradan değişiklik yok"
              aciklama="Bu ay kapandıktan sonra ona ait bir kayıt eklenmedi, düzeltilmedi ya da silinmedi."
            />
            <div v-for="d in degisiklikler" :key="d.id" class="kay" data-test="defter-degisiklik">
              <span class="pill" :style="{ background: TIP_RENK[d.tip] }">{{
                TIP_ETIKETI[d.tip]
              }}</span>
              <span class="a" :class="{ iptal: d.tip === 'silindi' }"
                >{{ TUR_ETIKETI[d.tur] ?? d.tur }} · {{ d.baslik
                }}<small
                  >Kayıt tarihi {{ tarih(d.kayit_tarihi) }} · {{ d.yapan_ad }} ·
                  {{ tarihSaat(d.yapildi_at) }}</small
                ></span
              >
              <span class="t sonra-t">{{ degisimMetni(d) }}</span>
            </div>
            <p v-if="degisiklikler.length" class="alt-not">
              Gider etkisi
              <b :class="sinif(-giderEtkisi)"
                >{{ isaret(giderEtkisi) }}{{ mutlakTl(giderEtkisi) }}</b
              >; bütçeden kalan {{ tl(defter.ozet.kalan) }} yerine
              <b>{{ tl(defter.ozet.kalan - giderEtkisi) }}</b> olurdu.
            </p>
          </template>
        </div>
      </UygulamaPenceresi>
    </template>
  </div>
</template>

<style scoped>
.art {
  color: #1b7f4b;
}
.azal {
  color: #c0252d;
}
.notr {
  color: #1f4fbf;
}
.iptal {
  text-decoration: line-through;
  text-decoration-color: #c0252d;
  text-decoration-thickness: 2px;
  opacity: 0.6;
}

.d3 {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 6px;
}
.d3 > div {
  background: #fffef8;
  color: #2b2a33;
  border-radius: 0.75rem;
  box-shadow: 0 2px 0 #cfc8b8;
  padding: 6px 10px 8px;
  min-width: 0;
}
.d3 small {
  font-size: 12px;
  font-weight: 600;
  color: #6d6a75;
  letter-spacing: 0.04em;
  display: block;
}
.d3 b {
  font-family: Caveat, 'Segoe Script', cursive;
  font-size: 21px;
  line-height: 1.15;
  font-variant-numeric: tabular-nums;
  display: block;
  white-space: nowrap;
}

.altcip {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 6px;
}
.altcip button {
  font:
    600 13px system-ui,
    sans-serif;
  border: 1.5px solid #d8d1c0;
  background: #fffef8;
  color: #2b2a33;
  border-radius: 12px;
  padding: 8px 4px;
  cursor: pointer;
}
.altcip button small {
  display: block;
  font-weight: 500;
  color: #6d6a75;
  font-size: 12px;
  font-variant-numeric: tabular-nums;
}
.altcip button:focus-visible,
.seg button:focus-visible,
.cipler button:focus-visible {
  outline: 3px solid #1f4fbf;
  outline-offset: 2px;
}

.seg {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 4px;
  background: #ece7d8;
  border-radius: 12px;
  padding: 4px;
}
.seg button {
  border: 0;
  background: transparent;
  padding: 9px;
  border-radius: 9px;
  font:
    700 15px system-ui,
    sans-serif;
  color: #55515d;
  cursor: pointer;
}
.seg button[aria-selected='true'] {
  background: #fffef8;
  color: #2b2a33;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.18);
}

.kagit {
  background: #fffef8;
  color: #2b2a33;
  font-family: 'Patrick Hand', 'Segoe Print', cursive;
  font-size: 18px;
}
.sayfa {
  border-radius: 14px;
  padding: 12px 12px 16px;
  box-shadow: 0 8px 22px rgba(60, 50, 30, 0.16);
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-height: 240px;
}
.alt-sayfa {
  border-radius: 12px;
  padding: 4px 2px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.ay-ozeti {
  margin: 0;
  font-size: 17px;
  line-height: 1.4;
}
.ay-ozeti b {
  font-family: Caveat, 'Segoe Script', cursive;
  font-size: 21px;
}
.alt-not {
  font-size: 16px;
  color: #6d6a75;
  margin: 6px 0 0;
}
.alt-not b {
  font-family: Caveat, 'Segoe Script', cursive;
  font-size: 20px;
}

.kal {
  border: 2px solid #3a4fb5;
  border-radius: 12px 5px 12px 7px;
  padding: 6px 10px 8px;
  background: #fffdf3;
}
.kal .b {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 8px;
}
.kal h4 {
  margin: 0;
  font-family: Caveat, 'Segoe Script', cursive;
  font-size: 26px;
  color: #3a4fb5;
}
.kal .v {
  font-family: Caveat, 'Segoe Script', cursive;
  font-size: 22px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}
.kal p {
  margin: 0;
  font-size: 16px;
  color: #55515d;
  line-height: 1.3;
  font-variant-numeric: tabular-nums;
  overflow-wrap: anywhere;
}
.kal p b {
  color: #2b2a33;
}
.kirilim {
  display: flex;
  flex-wrap: wrap;
  gap: 0 12px;
}

.kay {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  gap: 8px;
  align-items: baseline;
  padding: 5px 0;
  border-bottom: 1.5px dashed #ddd6c6;
}
.alt-kay {
  padding-left: 18px;
}
.kay .g {
  font:
    600 12px system-ui,
    sans-serif;
  background: #6b6475;
  color: #fff;
  border-radius: 5px;
  padding: 1px 6px;
  text-align: center;
}
.kay .a {
  min-width: 0;
}
.kay small {
  display: block;
  font-size: 14px;
  color: #6d6a75;
  overflow-wrap: anywhere;
}
.kay .t {
  font-family: Caveat, 'Segoe Script', cursive;
  font-size: 22px;
  font-weight: 700;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}
.kay .sonra-t {
  font-size: 19px;
}
.pill {
  font:
    700 11px system-ui,
    sans-serif;
  letter-spacing: 0.04em;
  color: #fff;
  border-radius: 6px;
  padding: 2px 7px;
  white-space: nowrap;
}
.kalan {
  font-family: Caveat, 'Segoe Script', cursive;
  font-size: 22px;
  font-weight: 700;
  text-align: right;
  padding: 2px 0 8px;
}

.cipler {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.cipler button {
  font:
    600 13px system-ui,
    sans-serif;
  border: 1.5px solid #d8d1c0;
  background: #fffef8;
  border-radius: 999px;
  padding: 4px 12px;
  cursor: pointer;
  color: #2b2a33;
}
.cipler button[aria-selected='true'] {
  background: #2b2a33;
  color: #fffef8;
  border-color: #2b2a33;
}

@media (prefers-reduced-motion: no-preference) {
  .sayfa > * {
    animation: gel 0.2s ease-out;
  }
  @keyframes gel {
    from {
      opacity: 0;
      transform: translateY(5px);
    }
  }
}
</style>
