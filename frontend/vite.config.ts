import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath, URL } from 'node:url'
import { defineConfig, type Plugin } from 'vite'
import vue from '@vitejs/plugin-vue'
import AutoImport from 'unplugin-auto-import/vite'
import Components from 'unplugin-vue-components/vite'
import { ElementPlusResolver } from 'unplugin-vue-components/resolvers'

const rootDir = path.dirname(fileURLToPath(import.meta.url))

/**
 * Docker nests apps/word-atlas/dist at frontend/dist/ielts. Vite's default
 * emptyOutDir tries to rm that mount point and fails with EACCES, leaving a
 * half-written dist (blank /kid after returning from 雅思星球).
 */
function emptyDistKeepIelts(): Plugin {
  return {
    name: 'empty-dist-keep-ielts',
    apply: 'build',
    buildStart() {
      const dist = path.join(rootDir, 'dist')
      if (!fs.existsSync(dist)) return
      for (const name of fs.readdirSync(dist)) {
        if (name === 'ielts') continue
        fs.rmSync(path.join(dist, name), { recursive: true, force: true })
      }
    },
  }
}

// Element Plus is resolved on demand, so it only lands in the chunks of parent pages that use it.
export default defineConfig({
  plugins: [
    emptyDistKeepIelts(),
    vue(),
    AutoImport({
      resolvers: [ElementPlusResolver()],
      dts: 'src/auto-imports.d.ts',
    }),
    Components({
      resolvers: [ElementPlusResolver()],
      dts: 'src/components.d.ts',
      dirs: [],
    }),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: 5173,
    proxy: {
      '/api': 'http://localhost:8088',
      '/sanctum': 'http://localhost:8088',
    },
  },
  build: {
    target: 'es2020',
    emptyOutDir: false,
    rollupOptions: {
      output: {
        // One shared chunk for the small planet pages so the first tap downloads them all.
        // Keep English / games sub-pages separate — they are large or rarely opened together.
        manualChunks(id) {
          if (!id.includes('/kid/pages/')) return
          // Keep the heaviest / entry pages on their own async chunks.
          if (
            id.includes('KidEnglishPage') ||
            id.includes('KidSelectPage') ||
            id.includes('KidHomePage') ||
            id.includes('KidSchultePage') ||
            id.includes('KidMirrorPage') ||
            id.includes('KidSudokuPage')
          ) {
            return
          }
          return 'kid-planets'
        },
      },
    },
  },
})
