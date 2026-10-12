import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import { router } from './router'
import { i18n } from './i18n'
import './styles/base.css'
import './styles/element-plus-services'

createApp(App).use(createPinia()).use(router).use(i18n).mount('#app')

if ('serviceWorker' in navigator && import.meta.env.PROD) {
  // When a new SW takes control (cache bump), reload once so the new hashed bundles load.
  let refreshing = false
  navigator.serviceWorker.addEventListener('controllerchange', () => {
    if (refreshing) return
    refreshing = true
    window.location.reload()
  })
  void navigator.serviceWorker.register('/sw.js').then((reg) => {
    // Pick up a newly built sw.js (cache version bump) without waiting for the next visit.
    void reg.update()
  }).catch(() => {
    /* PWA optional */
  })
}
