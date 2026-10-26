<script setup lang="ts">
import { onMounted } from 'vue'

/** Hand off to the vendored Word Atlas React app at /ielts/. */
onMounted(() => {
  // replace so browser Back from /ielts/ lands on the kid home, not this handoff page.
  window.location.replace('/ielts/')
})
</script>

<template>
  <main class="handoff" aria-busy="true">
    <p>正在打开雅思星球…</p>
    <p class="fallback">
      <a href="/ielts/">点这里进入</a>
      ·
      <RouterLink :to="{ name: 'kid-home' }">返回星球</RouterLink>
    </p>
  </main>
</template>

<style scoped>
.handoff {
  display: grid;
  place-items: center;
  gap: 12px;
  min-height: 40vh;
  padding: 24px;
  opacity: 0.8;
}
.fallback {
  margin: 0;
  font-size: 14px;
}
.fallback a {
  color: inherit;
}
</style>
