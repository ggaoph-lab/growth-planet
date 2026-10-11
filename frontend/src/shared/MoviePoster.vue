<script setup lang="ts">
import { computed, ref, watch } from 'vue'

const props = defineProps<{
  title: string
  src?: string
}>()

const failed = ref(false)
watch(
  () => props.src,
  () => {
    failed.value = false
  },
)

const palettes: [string, string][] = [
  ['#3d4ed7', '#8ea0ff'],
  ['#c4513a', '#ffb547'],
  ['#0f766e', '#5eead4'],
  ['#6d28d9', '#c4b5fd'],
  ['#1d4e89', '#7dd3fc'],
  ['#b45309', '#fcd34d'],
  ['#9d174d', '#fda4af'],
  ['#14532d', '#86efac'],
]

const colors = computed((): [string, string] => {
  let hash = 0
  const title = props.title.trim() || '电影'
  for (const ch of title) hash = (hash * 33 + (ch.codePointAt(0) ?? 0)) >>> 0
  return palettes[hash % palettes.length] ?? ['#3d4ed7', '#8ea0ff']
})

const mark = computed(() => {
  const title = props.title.trim()
  return title ? [...title][0] : '🎬'
})
</script>

<template>
  <div
    class="poster"
    :style="{ background: `linear-gradient(165deg, ${colors[0]} 0%, ${colors[1]} 100%)` }"
  >
    <img v-if="src && !failed" class="photo" :src="src" :alt="title" @error="failed = true" />
    <span v-else class="mark">{{ mark }}</span>
    <span class="shine" />
  </div>
</template>

<style scoped>
.poster {
  position: relative;
  aspect-ratio: 2 / 3;
  border-radius: 14px;
  overflow: hidden;
  box-shadow: 0 8px 18px rgba(20, 24, 60, 0.18);
}
.photo {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.mark {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 48px;
  font-weight: 700;
  color: #fff;
  text-shadow: 0 6px 16px rgba(0, 0, 0, 0.28);
}
.shine {
  position: absolute;
  inset: 0;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.28), transparent 42%, rgba(0, 0, 0, 0.18));
  pointer-events: none;
}
</style>
