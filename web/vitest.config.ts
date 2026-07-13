import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vitest/config'

export default defineConfig({
  resolve: {
    alias: {
      '#app': fileURLToPath(new URL('./test/mocks/nuxt-app.ts', import.meta.url))
    }
  },
  test: {
    environment: 'node',
    globals: true
  }
})
