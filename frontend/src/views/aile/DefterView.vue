<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Skeleton from 'primevue/skeleton'
import BosDurum from '@/components/BosDurum.vue'
import AySecici from '@/components/aile/AySecici.vue'
import { aileApi, type DefterDegisikligi, type DefterSatiri, type DefterYaniti } from '@/api/aile'
import { buAy } from '@/utils/donem'
import { miktar, tarih, tarihSaat, tl } from '@/utils/bicim'

/**
 * Defter: ayın tüm kayıtları kategorili, el yazısı görünümlü, salt okunur sekmeli ajanda. Kapanmış ay mühürlüdür (başka
 * yerden yapılan değişiklik görüntüyü bozmaz); kapanıştan sonraki ekleme / düzeltme / silme "Sonradan değişiklikler"
 * sekmesindedir ve ay toplamlarına katılmaz. Düzenle / sil düğmesi yoktur.
 */
const donem = ref(buAy())
const yanit = ref<DefterYaniti | null>(null)
const hata = ref(false)
const aktif = ref('kalem')

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

const SABIT_RENK: Record<string, string> = {
  gelir: '#1b7f4b',
  tasarruf: '#1f4fbf',
  diger: '#6b7280',
}
const PALET = ['#c2570c', '#7b3fb0', '#0b7a8a', '#c0252d', '#b0287a', '#8a6d00', '#2f6f3e']

const defter = computed(() => yanit.value?.defter ?? null)
const gruplar = computed(() =>
  (defter.value?.gruplar ?? [])
    .filter((g) => g.satirlar.length > 0 || g.toplam !== 0)
    .map((g, i) => ({ ...g, renk2: SABIT_RENK[g.id] ?? g.renk ?? PALET[i % PALET.length]! })),
)
const degisiklikler = computed(() => yanit.value?.degisiklikler ?? [])
const kalemler = computed(() => defter.value?.yatirim.kalemler ?? [])

const sekmeler = computed(() => [
  { id: 'kalem', ad: 'Kalemler', renk: '#1f4fbf' },
  { id: 'tumu', ad: 'Tümü', renk: '#4a4660' },
  ...gruplar.value.map((g) => ({ id: 'g:' + g.id, ad: g.ad, renk: g.renk2 })),
  { id: 'temettu', ad: 'Temettü', renk: '#5c7a00' },
  { id: 'borc', ad: 'Borç / Alacak', renk: '#7a4b2a' },
  { id: 'yatirim', ad: 'Yatırım işlemleri', renk: '#b07a00' },
  {
    id: 'sonradan',
    ad: `Sonradan değişiklikler${degisiklikler.value.length ? ` (${degisiklikler.value.length})` : ''}`,
    renk: '#455a64',
  },
  { id: 'deger', ad: 'Değerlendirme', renk: '#b0287a' },
])
const aktifSekme = computed(
  () => sekmeler.value.find((s) => s.id === aktif.value) ?? sekmeler.value[0]!,
)
const aktifGrup = computed(() => gruplar.value.find((g) => 'g:' + g.id === aktif.value) ?? null)

const tumSatirlar = computed(() =>
  gruplar.value
    .flatMap((g) => g.satirlar.map((s) => ({ ...s, renk: g.renk2, tur: g.tur })))
    .sort((a, b) => (a.tarih ?? '9999').localeCompare(b.tarih ?? '9999')),
)

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
const sinif = (n: number): string => (n > 0 ? 'art' : n < 0 ? 'azal' : 'notr')
const ok = (n: number): string => (n > 0 ? '▲' : n < 0 ? '▼' : '■')
const yz = (x: number): string =>
  '%' +
  (Math.abs(x) * 100).toLocaleString('tr-TR', {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
  })

function satirIsareti(g: { tur: string }): string {
  return g.tur === 'gelir' ? '+' : '−'
}
function satirNotu(s: DefterSatiri): string {
  return [s.yazan, s.not].filter(Boolean).join(' · ')
}

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
const alinanlar = computed(() =>
  kalemler.value
    .filter((k) => k.son > k.ilk)
    .map((k) => `${miktar(k.son - k.ilk)} ${k.birim} ${k.kod}`)
    .join(', '),
)
const satilanlar = computed(() =>
  kalemler.value
    .filter((k) => k.son < k.ilk)
    .map((k) => `${miktar(k.ilk - k.son)} ${k.birim} ${k.kod}`)
    .join(', '),
)

function degisimMetni(d: DefterDegisikligi): string {
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
      <Skeleton height="5rem" border-radius="1rem" />
      <Skeleton height="18rem" border-radius="1rem" />
    </div>

    <template v-else>
      <p class="text-xs text-surface-500" data-test="defter-durum">
        <template v-if="yanit.kapali"
          ><i class="pi pi-lock mr-1" />{{ defter.etiket }} kapandı, defter mühürlü. Değiştirilemez
          ve silinemez; sonradan yapılan değişiklikler ayrı sekmede.</template
        >
        <template v-else
          ><i class="pi pi-pencil mr-1" />{{ defter.etiket }} henüz bitmedi; defter canlı görünüyor,
          ay bitince mühürlenir.</template
        >
      </p>

      <!-- Üst şerit -->
      <section class="defter-serit" data-test="defter-serit">
        <div>
          <small>YATIRIM DEĞERİ</small>
          <b data-test="serit-deger">{{ toplam.eksik && !toplam.d1 ? '—' : tl(toplam.d1) }}</b>
          <span :class="sinif(toplam.d1 - toplam.d0)"
            >{{ ok(toplam.d1 - toplam.d0) }} {{ isaret(toplam.d1 - toplam.d0)
            }}{{ mutlakTl(toplam.d1 - toplam.d0) }}</span
          >
        </div>
        <div>
          <small>FİYAT HAREKETİ</small>
          <b :class="sinif(toplam.fe)">{{ isaret(toplam.fe) }}{{ mutlakTl(toplam.fe) }}</b>
          <span>kendi işleminiz hariç</span>
        </div>
        <div>
          <small>NET ALIM / SATIM</small>
          <b :class="sinif(toplam.net)">{{ isaret(toplam.net) }}{{ mutlakTl(toplam.net) }}</b>
          <span>{{ toplam.net >= 0 ? 'alım fazla' : 'satım fazla' }}</span>
        </div>
        <div>
          <small>BÜTÇEDEN KALAN</small>
          <b :class="sinif(defter.ozet.kalan)">{{ tl(defter.ozet.kalan) }}</b>
          <span>gelir {{ tl(defter.ozet.gelir) }}</span>
        </div>
        <div>
          <small>TEMETTÜ (NET)</small>
          <b class="art">+{{ mutlakTl(defter.temettu.toplam) }}</b>
          <span>bütçe gelirine girmez</span>
        </div>
        <div>
          <small>AY KAPANDIKTAN SONRA</small>
          <b class="notr" data-test="serit-sonradan">{{ degisiklikler.length }} değişiklik</b>
          <span>gider etkisi {{ isaret(giderEtkisi) }}{{ mutlakTl(giderEtkisi) }}</span>
        </div>
      </section>

      <!-- Sekmeli ajanda -->
      <div class="ajanda">
        <div class="sekme-sira" role="tablist">
          <button
            v-for="s in sekmeler"
            :key="s.id"
            type="button"
            role="tab"
            class="sekme"
            :aria-selected="s.id === aktif"
            :style="{ '--c': s.renk }"
            :data-test="`defter-sekme-${s.id}`"
            @click="aktif = s.id"
          >
            {{ s.ad }}
          </button>
        </div>

        <div class="sayfa" :style="{ '--c': aktifSekme.renk }" data-test="defter-sayfa">
          <!-- Kalemler -->
          <template v-if="aktif === 'kalem'">
            <h2>
              <span>Kalemler · {{ defter.etiket }}</span>
            </h2>
            <p class="alt-not">
              Önceki ay sonuna göre adet ve değer değişimi. Değer değişimi, sizin alım / satımınız
              ile fiyat hareketine ayrılır.
            </p>
            <BosDurum
              v-if="!kalemler.length"
              baslik="Bu ay kalem yok"
              aciklama="Hesaplarda bu ay adet ya da işlem olan varlık görünmüyor."
            />
            <div v-else class="kalemler">
              <article
                v-for="k in kalemler"
                :key="k.varlik_id"
                class="kalem"
                data-test="defter-kalem"
              >
                <header>
                  <h3>{{ k.kod }}</h3>
                  <span class="tur">{{ k.ad }} · {{ k.birim }}</span>
                </header>
                <div class="k">
                  <span>Önceki ay sonu</span><b>{{ miktar(k.ilk) }} {{ k.birim }}</b>
                </div>
                <div v-for="i in k.islemler" :key="i.tarih + i.adet" class="k alt">
                  <span
                    >{{ gun(i.tarih) }} · {{ i.adet > 0 ? 'alım' : 'satım' }}
                    {{ miktar(Math.abs(i.adet)) }} {{ k.birim }}
                    <template v-if="i.fiyat !== null"
                      >× {{ tl(i.fiyat) }} = {{ mutlakTl(i.adet * i.fiyat) }}</template
                    ></span
                  >
                </div>
                <div class="k">
                  <span>Bu ay adet</span>
                  <b v-if="k.son !== k.ilk" :class="sinif(k.son - k.ilk)"
                    >{{ ok(k.son - k.ilk) }} {{ isaret(k.son - k.ilk)
                    }}{{ miktar(Math.abs(k.son - k.ilk)) }} {{ k.birim
                    }}<template v-if="k.ilk"> · {{ yz((k.son - k.ilk) / k.ilk) }}</template></b
                  >
                  <b v-else class="notr">■ değişmedi</b>
                </div>
                <div class="k">
                  <span>Ay sonu</span><b>{{ miktar(k.son) }} {{ k.birim }}</b>
                </div>
                <div class="k">
                  <span>Birim fiyat</span>
                  <b v-if="k.fiyat_ilk !== null && k.fiyat_son !== null"
                    >{{ tl(k.fiyat_ilk) }} → {{ tl(k.fiyat_son) }}
                    <span :class="sinif(k.fiyat_son - k.fiyat_ilk)"
                      >{{ ok(k.fiyat_son - k.fiyat_ilk) }}
                      {{ yz(k.fiyat_son / k.fiyat_ilk - 1) }}</span
                    ></b
                  >
                  <b v-else class="notr">fiyat yok</b>
                </div>
                <hr class="ayrac" />
                <div class="k">
                  <span>Değer</span>
                  <b>{{ tl(k.deger_ilk) }} → {{ tl(k.deger_son) }}</b>
                </div>
                <template v-if="k.deger_ilk !== null && k.deger_son !== null">
                  <div class="k">
                    <span>Değer değişimi</span>
                    <b :class="sinif(k.deger_son - k.deger_ilk)"
                      >{{ ok(k.deger_son - k.deger_ilk) }} {{ isaret(k.deger_son - k.deger_ilk)
                      }}{{ mutlakTl(k.deger_son - k.deger_ilk)
                      }}<template v-if="k.deger_ilk">
                        · {{ yz((k.deger_son - k.deger_ilk) / k.deger_ilk) }}</template
                      ></b
                    >
                  </div>
                  <div class="kirilim">
                    <span :class="sinif(k.net_islem)"
                      >{{ k.net_islem >= 0 ? 'Alımınız' : 'Satışınız' }} {{ isaret(k.net_islem)
                      }}{{ mutlakTl(k.net_islem) }}</span
                    >
                    <span :class="sinif(k.fiyat_etkisi ?? 0)"
                      >Fiyat hareketi {{ isaret(k.fiyat_etkisi ?? 0)
                      }}{{ mutlakTl(k.fiyat_etkisi ?? 0) }}</span
                    >
                    <span v-if="k.temettu" class="art"
                      >Temettü +{{ mutlakTl(k.temettu) }} (nakde)</span
                    >
                  </div>
                </template>
              </article>
            </div>
          </template>

          <!-- Tümü -->
          <template v-else-if="aktif === 'tumu'">
            <h2>
              <span>{{ defter.etiket }}</span>
            </h2>
            <BosDurum
              v-if="!tumSatirlar.length"
              baslik="Bu ay kayıt yok"
              aciklama="Bu ay için gelir, gider ya da tasarruf kaydı görünmüyor."
            />
            <div v-for="(s, i) in tumSatirlar" :key="i" class="sat" :style="{ '--c': s.renk }">
              <span class="rozet">{{ gun(s.tarih) }}</span>
              <span class="a"
                >{{ s.ad }}<small>{{ satirNotu(s) }}</small></span
              >
              <span class="t" :style="{ color: s.renk }"
                >{{ satirIsareti(s) }}{{ mutlakTl(s.tutar) }}</span
              >
            </div>
          </template>

          <!-- Tek kategori -->
          <template v-else-if="aktifGrup">
            <h2>
              <span>{{ aktifGrup.ad }}</span
              ><span class="tut">{{ tl(aktifGrup.toplam) }}</span>
            </h2>
            <BosDurum
              v-if="!aktifGrup.satirlar.length"
              baslik="Satır yok"
              aciklama="Bu kategorinin toplamı var ama listelenecek satır görünmüyor."
            />
            <div
              v-for="(s, i) in aktifGrup.satirlar"
              :key="i"
              class="sat"
              :style="{ '--c': aktifGrup.renk2 }"
            >
              <span class="rozet">{{ gun(s.tarih) }}</span>
              <span class="a"
                >{{ s.ad }}<small>{{ satirNotu(s) }}</small></span
              >
              <span class="t" :style="{ color: aktifGrup.renk2 }"
                >{{ satirIsareti(aktifGrup) }}{{ mutlakTl(s.tutar) }}</span
              >
            </div>
          </template>

          <!-- Temettü -->
          <template v-else-if="aktif === 'temettu'">
            <h2>
              <span>Temettü</span><span class="tut">{{ tl(defter.temettu.toplam) }}</span>
            </h2>
            <BosDurum
              v-if="!defter.temettu.satirlar.length"
              baslik="Bu ay temettü yok"
              aciklama="Hisse hesaplarına bu ay temettü yatmadı."
            />
            <div
              v-for="(s, i) in defter.temettu.satirlar"
              :key="i"
              class="sat"
              style="--c: #5c7a00"
            >
              <span class="rozet">{{ gun(s.tarih) }}</span>
              <span class="a"
                >{{ s.ad }}<small>{{ satirNotu(s) }}</small></span
              >
              <span class="t art">+{{ mutlakTl(s.tutar) }}</span>
            </div>
            <p class="alt-not">Temettü hisse hesabının nakdine yazılır, bütçe gelirine girmez.</p>
          </template>

          <!-- Borç / alacak -->
          <template v-else-if="aktif === 'borc'">
            <h2>
              <span>Borç / Alacak</span
              ><span class="tut"
                >net {{ isaret(defter.borclar.alacak - defter.borclar.borc)
                }}{{ mutlakTl(defter.borclar.alacak - defter.borclar.borc) }}</span
              >
            </h2>
            <BosDurum
              v-if="!defter.borclar.kayitlar.length"
              baslik="Bu ay borç / alacak hareketi yok"
              aciklama="Bu ay açılan ya da ödemesi yapılan kayıt görünmüyor."
            />
            <template v-for="(b, i) in defter.borclar.kayitlar" :key="i">
              <div class="sat" style="--c: #7a4b2a">
                <span class="rozet">{{ gun(b.tarih) }}</span>
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
              <div v-for="(o, j) in b.odemeler" :key="j" class="sat alt-sat" style="--c: #7a4b2a">
                <span class="rozet">{{ gun(o.tarih) }}</span>
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
            <ul class="yorum" style="--c: #7a4b2a">
              <li>
                Alacağım <b class="art">{{ tl(defter.borclar.alacak) }}</b
                >, borcum <b class="azal">{{ tl(defter.borclar.borc) }}</b
                >.
              </li>
              <li>Bütçe gelir ve giderine, ev nakdine ve zekâta karışmaz.</li>
            </ul>
          </template>

          <!-- Yatırım işlemleri -->
          <template v-else-if="aktif === 'yatirim'">
            <h2>
              <span>Yatırım işlemleri</span
              ><span class="tut">net {{ isaret(toplam.net) }}{{ mutlakTl(toplam.net) }}</span>
            </h2>
            <BosDurum
              v-if="!kalemler.some((k) => k.islemler.length)"
              baslik="Bu ay alım / satım yok"
              aciklama="Hesaplarda bu ay yapılmış alım ya da satım görünmüyor."
            />
            <template v-for="k in kalemler" :key="k.varlik_id">
              <div v-for="(i, j) in k.islemler" :key="j" class="sat" style="--c: #b07a00">
                <span class="rozet">{{ gun(i.tarih) }}</span>
                <span class="a"
                  >{{ k.kod }} · {{ i.adet > 0 ? 'alım' : 'satım' }} {{ miktar(Math.abs(i.adet)) }}
                  {{ k.birim }}<template v-if="i.fiyat !== null"> × {{ tl(i.fiyat) }}</template
                  ><small>{{ i.yazan }}</small></span
                >
                <span v-if="i.fiyat !== null" class="t" :class="i.adet > 0 ? 'notr' : 'art'"
                  >{{ i.adet > 0 ? '−' : '+' }}{{ mutlakTl(i.adet * i.fiyat) }}</span
                >
              </div>
            </template>
          </template>

          <!-- Sonradan değişiklikler -->
          <template v-else-if="aktif === 'sonradan'">
            <h2>
              <span>Sonradan yapılan değişiklikler</span
              ><span class="tut">{{ degisiklikler.length }} kayıt</span>
            </h2>
            <p class="alt-not">
              Ay kapandıktan sonra yapılanlar burada toplanır; diğer sekmelerdeki ay toplamlarına
              katılmaz.
            </p>
            <BosDurum
              v-if="!degisiklikler.length"
              baslik="Sonradan değişiklik yok"
              aciklama="Bu ay kapandıktan sonra ona ait bir kayıt eklenmedi, düzeltilmedi ya da silinmedi."
            />
            <div v-for="d in degisiklikler" :key="d.id" class="sat" data-test="defter-degisiklik">
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
              <span class="t">{{ degisimMetni(d) }}</span>
            </div>
            <ul v-if="degisiklikler.length" class="yorum" style="--c: #455a64">
              <li>
                Gider etkisi:
                <b :class="sinif(-giderEtkisi)"
                  >{{ isaret(giderEtkisi) }}{{ mutlakTl(giderEtkisi) }}</b
                >; bütçeden kalan {{ tl(defter.ozet.kalan) }} yerine
                <b>{{ tl(defter.ozet.kalan - giderEtkisi) }}</b> olurdu.
              </li>
            </ul>
          </template>

          <!-- Değerlendirme -->
          <template v-else-if="aktif === 'deger'">
            <h2>
              <span>Ay sonu değerlendirmesi</span><span class="tut">{{ defter.etiket }}</span>
            </h2>
            <div class="karne">
              <div v-for="k in kalemler" :key="k.varlik_id">
                <span>{{ k.kod }}</span>
                <span
                  v-if="k.deger_ilk && k.deger_son !== null"
                  :class="sinif(k.deger_son - k.deger_ilk)"
                  >{{ ok(k.deger_son - k.deger_ilk) }}
                  {{ yz((k.deger_son - k.deger_ilk) / k.deger_ilk) }}</span
                >
                <span v-else class="notr">—</span>
              </div>
            </div>
            <ul class="yorum" style="--c: #b0287a" data-test="defter-yorum">
              <li v-if="toplam.d0 || toplam.d1">
                Yatırım değeri <b>{{ tl(toplam.d0) }}</b> iken <b>{{ tl(toplam.d1) }}</b> oldu:
                <b :class="sinif(toplam.d1 - toplam.d0)"
                  >{{ isaret(toplam.d1 - toplam.d0) }}{{ mutlakTl(toplam.d1 - toplam.d0)
                  }}<template v-if="toplam.d0">
                    ({{ yz((toplam.d1 - toplam.d0) / toplam.d0) }})</template
                  ></b
                >.
                <template v-if="toplam.eksik"
                  >Fiyatı bulunamayan kalemler toplama girmedi.</template
                >
              </li>
              <li v-if="toplam.d0 || toplam.d1">
                Bunun
                <b :class="sinif(toplam.net)">{{ isaret(toplam.net) }}{{ mutlakTl(toplam.net) }}</b>
                kısmı sizin net alım / satımınız,
                <b :class="sinif(toplam.fe)">{{ isaret(toplam.fe) }}{{ mutlakTl(toplam.fe) }}</b>
                kısmı fiyat hareketi.
              </li>
              <li v-if="enYukselen && enDusen && enYukselen !== enDusen">
                Fiyatı en çok yükselen
                <b class="art"
                  >{{ enYukselen.kod }}
                  {{ yz(enYukselen.fiyat_son! / enYukselen.fiyat_ilk! - 1) }}</b
                >, en çok düşen
                <b class="azal"
                  >{{ enDusen.kod }} {{ yz(enDusen.fiyat_son! / enDusen.fiyat_ilk! - 1) }}</b
                >.
              </li>
              <li v-if="alinanlar || satilanlar">
                Bu ay alınan: {{ alinanlar || 'yok' }}. Satılan: {{ satilanlar || 'yok' }}.
              </li>
              <li>
                Bütçe: gelir <b class="art">{{ tl(defter.ozet.gelir) }}</b
                >, gider <b class="azal">{{ tl(defter.ozet.gider) }}</b
                >, tasarruf <b class="notr">{{ tl(defter.ozet.tasarruf) }}</b
                ><template v-if="defter.ozet.gelir > 0">
                  (gelirin {{ yz(defter.ozet.tasarruf / defter.ozet.gelir) }})</template
                >. Ay sonunda
                <b :class="sinif(defter.ozet.kalan)">{{ tl(defter.ozet.kalan) }}</b> kaldı.
              </li>
              <li v-if="defter.temettu.toplam">
                Temettü: net <b class="art">+{{ mutlakTl(defter.temettu.toplam) }}</b> nakde yattı;
                bütçe gelirine girmez.
              </li>
              <li v-if="defter.borclar.alacak || defter.borclar.borc">
                Borç / alacak: alacağım <b class="art">{{ tl(defter.borclar.alacak) }}</b
                >, borcum <b class="azal">{{ tl(defter.borclar.borc) }}</b
                >; bütçeye karışmaz.
              </li>
              <li v-if="degisiklikler.length">
                Ay kapandıktan sonra <b>{{ degisiklikler.length }}</b> değişiklik yapıldı (gider
                etkisi {{ isaret(giderEtkisi) }}{{ mutlakTl(giderEtkisi) }}); bu rakamlara
                katılmadı.
              </li>
            </ul>
          </template>
        </div>
      </div>

      <p class="text-xs text-surface-500">
        Her satırın altındaki not: kaydı kim, ne zaman girdi. Silinen kayıtların tamamı
        <RouterLink :to="{ name: 'aile-silinenler' }" class="underline">Silinenler</RouterLink>
        sayfasında.
      </p>
    </template>
  </div>
</template>

<style scoped>
.defter-serit {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 8px;
}
.defter-serit > div {
  background: #fffef8;
  border-radius: 0.75rem;
  padding: 8px 12px 10px;
  box-shadow: 0 2px 0 #cfc8b8;
  min-width: 0;
  color: #2b2a33;
}
.defter-serit small {
  font-size: 12px;
  font-weight: 600;
  color: #6d6a75;
  letter-spacing: 0.04em;
}
.defter-serit b {
  display: block;
  font-family: Caveat, 'Segoe Script', cursive;
  font-size: 28px;
  line-height: 1.1;
  font-variant-numeric: tabular-nums;
}
.defter-serit span {
  font-family: 'Patrick Hand', 'Segoe Print', cursive;
  font-size: 15px;
}

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

.ajanda {
  position: relative;
}
.sekme-sira {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  padding-left: 38px;
}
.sekme {
  font-family: Caveat, 'Segoe Script', cursive;
  font-size: 22px;
  font-weight: 700;
  border: 0;
  cursor: pointer;
  padding: 4px 14px 6px;
  border-radius: 10px 10px 0 0;
  background: var(--c);
  color: #fff;
  opacity: 0.6;
}
.sekme[aria-selected='true'] {
  opacity: 1;
  transform: translateY(2px);
}
.sekme:focus-visible {
  outline: 3px solid #1f4fbf;
  outline-offset: 2px;
}
.sayfa {
  background: #fffef8;
  color: #2b2a33;
  border-radius: 0 12px 12px 12px;
  padding: 16px 16px 24px 50px;
  position: relative;
  box-shadow: 0 10px 24px rgba(60, 50, 30, 0.18);
  border-top: 6px solid var(--c);
  font-family: 'Patrick Hand', 'Segoe Print', cursive;
  font-size: 19px;
  min-height: 320px;
}
.sayfa::before {
  content: '';
  position: absolute;
  left: 12px;
  top: 18px;
  bottom: 18px;
  width: 18px;
  background: radial-gradient(circle at 9px 9px, #8f89a0 0 3px, #d9d3c4 3px 7px, transparent 8px) 0
    0 / 18px 36px repeat-y;
}
.sayfa h2 {
  font-family: Caveat, 'Segoe Script', cursive;
  font-size: 32px;
  margin: 0 0 6px;
  color: var(--c);
  display: flex;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
}
.tut {
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}
.alt-not {
  font-size: 16px;
  color: #6d6a75;
  margin: 0 0 8px;
}
.sat {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  gap: 10px;
  align-items: baseline;
  padding: 6px 0;
  border-bottom: 1.5px dashed #d8d1c0;
}
.alt-sat {
  padding-left: 22px;
}
.rozet {
  font-family: system-ui, sans-serif;
  font-size: 12px;
  font-weight: 600;
  background: var(--c);
  color: #fff;
  border-radius: 6px;
  padding: 1px 7px;
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
.sat .a small {
  display: block;
  font-size: 14px;
  color: #6d6a75;
  overflow-wrap: anywhere;
}
.sat .t {
  font-family: Caveat, 'Segoe Script', cursive;
  font-size: 24px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}
.kalan {
  font-family: Caveat, 'Segoe Script', cursive;
  font-size: 22px;
  font-weight: 700;
  text-align: right;
  padding: 2px 0 10px;
}

.kalemler {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(290px, 1fr));
  gap: 16px;
  margin-top: 8px;
}
.kalem {
  border: 2px solid var(--c);
  border-radius: 14px 6px 14px 8px;
  padding: 10px 14px 12px;
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  background: #fffdf3;
}
.kalem header {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 8px;
}
.kalem h3 {
  font-family: Caveat, 'Segoe Script', cursive;
  font-size: 30px;
  margin: 0;
  color: var(--c);
}
.kalem .tur {
  font:
    600 12px system-ui,
    sans-serif;
  color: #6d6a75;
}
.k {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 10px;
}
.k > span:first-child {
  color: #6d6a75;
  font-size: 16px;
}
.k b {
  font-family: Caveat, 'Segoe Script', cursive;
  font-size: 23px;
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.k.alt {
  font-size: 15px;
  justify-content: flex-start;
  padding-left: 14px;
  color: #55607a;
}
.ayrac {
  border: 0;
  border-top: 2px dashed var(--c);
  opacity: 0.5;
  margin: 6px 0;
}
.kirilim {
  font-size: 16px;
  display: flex;
  flex-wrap: wrap;
  gap: 2px 14px;
}
.kirilim span {
  white-space: nowrap;
}
.yorum {
  margin: 10px 0 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.yorum li {
  padding-left: 26px;
  position: relative;
  line-height: 1.35;
  max-width: 70ch;
}
.yorum li::before {
  content: '✎';
  position: absolute;
  left: 0;
  color: var(--c);
}
.yorum b {
  font-family: Caveat, 'Segoe Script', cursive;
  font-size: 23px;
}
.karne {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 8px 16px;
  margin: 6px 0 14px;
}
.karne div {
  display: flex;
  justify-content: space-between;
  border-bottom: 2px solid #b0287a;
  font-family: Caveat, 'Segoe Script', cursive;
  font-size: 24px;
}

@media (max-width: 480px) {
  .sayfa {
    padding-left: 44px;
  }
  .kalemler {
    grid-template-columns: 1fr;
  }
}
@media (prefers-reduced-motion: no-preference) {
  .sayfa > * {
    animation: gel 0.25s ease-out;
  }
  @keyframes gel {
    from {
      opacity: 0;
      transform: translateY(6px);
    }
  }
}
</style>
