import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { finansApi, type Kullanici } from '@/api/finans'

export const useAuthStore = defineStore('auth', () => {
  const kullanici = ref<Kullanici | null>(null)
  /** Açılışta oturum bir kez soruldu mu */
  const denetlendi = ref(false)

  const yonetici = computed(() => kullanici.value?.yonetici ?? false)

  async function denetle(): Promise<void> {
    if (denetlendi.value) return
    try {
      kullanici.value = await finansApi.ben()
    } catch {
      kullanici.value = null
    } finally {
      denetlendi.value = true
    }
  }

  async function giris(email: string, sifre: string, hatirla: boolean): Promise<void> {
    kullanici.value = await finansApi.giris(email, sifre, hatirla)
    denetlendi.value = true
  }

  async function cikis(): Promise<void> {
    try {
      await finansApi.cikis()
    } finally {
      temizle()
    }
  }

  function temizle(): void {
    kullanici.value = null
  }

  return { kullanici, denetlendi, yonetici, denetle, giris, cikis, temizle }
})
