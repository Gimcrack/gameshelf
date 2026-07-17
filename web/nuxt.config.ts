import tailwindcss from '@tailwindcss/vite'

// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },
  // SPA per SPEC §C.nuxt-mode — all views behind auth, no SEO need
  ssr: false,
  srcDir: 'app',
  css: ['~/assets/css/main.css'],
  app: {
    head: {
      htmlAttrs: { lang: 'en' },
      // T43: Nest Hex brandmark in a deep-indigo disc (SPEC §C.brand favicon).
      link: [{ rel: 'icon', type: 'image/svg+xml', href: '/favicon.svg' }]
    }
  },
  vite: {
    plugins: [tailwindcss()]
  },
  runtimeConfig: {
    public: {
      apiBase: process.env.NUXT_PUBLIC_API_BASE || 'http://localhost:8000',
      // Public OAuth identifier, not a secret — used to build the GOG login URL.
      gogClientId: process.env.NUXT_PUBLIC_GOG_CLIENT_ID || '',
      // Public OAuth identifier, not a secret — used to build the Xbox login URL (T63).
      xboxClientId: process.env.NUXT_PUBLIC_XBOX_CLIENT_ID || ''
    }
  }
})
