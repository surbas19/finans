import axios, { AxiosError } from 'axios'

/**
 * API istemcisi. Arayüz ve API aynı kökte (/api/v1); oturum `finans_oturum` çereziyle taşınır, token saklanmaz.
 * CSRF köken denetimiyle yapılır (Sec-Fetch-Site), XSRF çerezi kullanılmaz: okul sisteminin .yoklm.com'daki
 * XSRF-TOKEN çerezi buraya da gelir, axios onu başlığa koymasın diye çerez adı boş bırakılır.
 */
export const http = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,
  xsrfCookieName: '',
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

let oturumDustuIsleyici: (() => void) | null = null

export function oturumDustuAyarla(isleyici: () => void): void {
  oturumDustuIsleyici = isleyici
}

http.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => {
    if (error.response?.status === 401 && !error.config?.url?.endsWith('/ben')) {
      oturumDustuIsleyici?.()
    }

    return Promise.reject(error)
  },
)

/** Laravel 422 doğrulama hatalarını alan => ilk mesaj biçimine çevirir. */
export function dogrulamaHatalari(error: unknown): Record<string, string> {
  if (error instanceof AxiosError && error.response?.status === 422) {
    const hatalar = (error.response.data?.errors ?? {}) as Record<string, string[]>

    return Object.fromEntries(
      Object.entries(hatalar).map(([alan, mesajlar]) => [alan, mesajlar[0] ?? '']),
    )
  }

  return {}
}

export function hataMesaji(error: unknown): string {
  if (error instanceof AxiosError) {
    if (!error.response) {
      return 'Sunucuya ulaşılamıyor. İnternet bağlantınızı kontrol edin.'
    }
    if (error.response.status === 429) {
      return 'Çok fazla deneme yaptınız. Lütfen bir dakika sonra tekrar deneyin.'
    }
    if (error.response.status === 419) {
      return 'Sayfanın süresi doldu. Sayfayı yenileyip yeniden deneyin.'
    }

    return (error.response.data as { message?: string })?.message ?? 'Beklenmeyen bir hata oluştu.'
  }

  return 'Beklenmeyen bir hata oluştu.'
}
