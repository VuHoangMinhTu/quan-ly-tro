import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { defineConfig } from 'vite'
import { realpathSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  cacheDir: fileURLToPath(new URL(`../tmp/vite-cache/${process.pid}`, import.meta.url)),
  server: { fs: { allow: [fileURLToPath(new URL('.', import.meta.url)), realpathSync(new URL('./node_modules', import.meta.url))] } },
})
