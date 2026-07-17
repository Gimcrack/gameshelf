import tailwindcss from '@tailwindcss/vite'

// Fixed origin for absolute SEO URLs — resolved at prerender/build time
// (or baked into the SPA shell template), not per-request.
const SITE_URL = process.env.NUXT_PUBLIC_SITE_URL || 'https://gamebower.com'
const SITE_TITLE = 'GameBower — All your games, one library'
const SITE_DESCRIPTION =
  'Aggregate every game you own across Steam, GOG, Xbox, and manual entries into one organized library. Search, filter, rate, and discover what to play next.'

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
      title: SITE_TITLE,
      // Site-wide OG/Twitter defaults — baked into the base app template, so
      // even client-only routes (/, /discover, /games/**, /profile, /stats;
      // all ssr:false, never prerendered) ship real crawler-visible meta in
      // their raw SPA-shell HTML instead of nothing. /welcome overrides the
      // page-specific ones (og:url etc) via its own useSeoMeta.
      meta: [
        { name: 'description', content: SITE_DESCRIPTION },
        { property: 'og:site_name', content: 'GameBower' },
        { property: 'og:title', content: SITE_TITLE },
        { property: 'og:description', content: SITE_DESCRIPTION },
        { property: 'og:type', content: 'website' },
        { property: 'og:url', content: SITE_URL },
        { property: 'og:image', content: `${SITE_URL}/screenshots/library-hero.webp` },
        { property: 'og:image:width', content: '1600' },
        { property: 'og:image:height', content: '872' },
        // Not core Open Graph (ogp.me) — some validators/brand-card
        // consumers still check it, so ship it. Raster PNG (SVG support for
        // og tags is inconsistent across crawlers).
        { property: 'og:logo', content: `${SITE_URL}/brand/gamebower-app-icon.png` },
        { name: 'twitter:card', content: 'summary_large_image' },
        { name: 'twitter:title', content: SITE_TITLE },
        { name: 'twitter:description', content: SITE_DESCRIPTION },
        { name: 'twitter:image', content: `${SITE_URL}/screenshots/library-hero.webp` }
      ],
      link: [
        // T43: Nest Hex brandmark in a deep-indigo disc (SPEC §C.brand favicon).
        { rel: 'icon', type: 'image/svg+xml', href: '/favicon.svg' },
        { rel: 'canonical', href: SITE_URL }
      ]
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
