import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import AutoImport from 'unplugin-auto-import/vite'
import Components from 'unplugin-vue-components/vite'
import { ElementPlusResolver } from 'unplugin-vue-components/resolvers'

// Element Plus is resolved on demand, so it only lands in the chunks of parent pages that use it.
export default defineConfig({
  plugins: [
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
