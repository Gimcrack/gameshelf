import tailwindcss from '@tailwindcss/vite'

// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },
  // Hybrid per SPEC §C.nuxt-mode (amended): public marketing/auth routes
  // prerender to static HTML (real SEO/OG tags); authenticated app routes
  // stay client-only SPA (ssr:false) — no personalized data at render time,
  // and this keeps `nuxt generate` output pure-static (no Node server).
  srcDir: 'app',
  routeRules: {
    '/welcome': { prerender: true },
    '/login': { prerender: true },
    '/register': { prerender: true },
    '/': { ssr: false },
    '/discover': { ssr: false },
    '/games/**': { ssr: false },
    '/profile': { ssr: false },
    '/stats': { ssr: false }
  },
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
      // Fixed origin for absolute SEO URLs (og:image, canonical) — resolved
      // at prerender/build time, not per-request, so can't come from
      // useRequestURL().
      siteUrl: process.env.NUXT_PUBLIC_SITE_URL || 'https://gamebower.com',
      apiBase: process.env.NUXT_PUBLIC_API_BASE || 'http://localhost:8000',
      // Public OAuth identifier, not a secret — used to build the GOG login URL.
      gogClientId: process.env.NUXT_PUBLIC_GOG_CLIENT_ID || '',
      // Public OAuth identifier, not a secret — used to build the Xbox login URL (T63).
      xboxClientId: process.env.NUXT_PUBLIC_XBOX_CLIENT_ID || ''
    }
  }
})
