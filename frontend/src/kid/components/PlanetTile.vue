<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Planet } from '@/shared/api/types'
import { planetTitle } from '@/shared/planetTitle'

const props = defineProps<{ planet: Planet; animate: boolean }>()
const emit = defineEmits<{ open: [planet: Planet]; prefetch: [planet: Planet] }>()
const { t } = useI18n()

function warm(): void {
  if (props.planet.available) emit('prefetch', props.planet)
}

const title = computed(() => planetTitle(props.planet.key, props.planet.title))

const ICONS: Record<string, string> = {
  coin: '🪙',
  'planet-task': '🪐',
  backpack: '🎒',
  calendar: '📅',
  compass: '🧭',
  food: '🥕',
  movie: '🎬',
  abc: '🔤',
  ielts: '🌟',
  book: '📖',
  scroll: '📜',
  gamepad: '🎮',
  gift: '🎁',
  museum: '🏛️',
}

const icon = computed(() => ICONS[props.planet.icon] ?? '⭐')
</script>

<template>
  <button
    type="button"
    class="tile"
    :class="{ locked: !planet.available, animate }"
    :aria-disabled="!planet.available"
    @pointerdown="warm"
    @mouseenter="warm"
    @focus="warm"
    @click="emit('open', planet)"
  >
    <span class="icon" aria-hidden="true">{{ icon }}</span>
    <span class="title">{{ title }}</span>
    <span v-if="planet.key === 'english_beta'" class="badge">Beta</span>
    <span v-if="!planet.available" class="badge">{{ t('kid.comingSoon') }}</span>
  </button>
</template>

<style scoped>
.tile {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-width: 0;
  min-height: 148px;
  padding: 24px 12px;
  border: 1px solid rgba(255, 255, 255, 0.12);
  border-radius: 24px;
  background: rgba(255, 255, 255, 0.12);
  color: #fff;
  cursor: pointer;
  touch-action: manipulation;
}
.tile.animate {
  transition: transform 0.15s ease;
}
.tile.animate:active {
  transform: scale(0.95);
}
.icon {
  font-size: 44px;
  line-height: 1;
}
.tile.animate .icon {
  animation: float 4s ease-in-out infinite;
}
.title {
  font-size: 17px;
  font-weight: 600;
  text-align: center;
  white-space: pre-line;
  line-height: 1.25;
  overflow-wrap: anywhere;
}
.tile:focus-visible { outline: 3px solid var(--gp-sun); outline-offset: 4px; }
@media (hover: hover) {
  .tile:hover { background: rgba(255, 255, 255, 0.2); border-color: rgba(255, 255, 255, 0.35); }
}
@media (min-width: 960px) {
  .tile { min-height: 164px; gap: 14px; }
  .icon { font-size: 48px; }
  .title { font-size: 18px; }
}
@media (max-width: 374px) {
  .tile { min-height: 132px; padding: 20px 8px; border-radius: 20px; }
  .title { font-size: 15px; }
  .icon { font-size: 38px; }
}
@media (orientation: landscape) and (max-height: 600px) {
  .tile { min-height: 112px; padding: 16px 10px; gap: 8px; }
  .icon { font-size: 34px; }
  .title { font-size: 15px; }
}
.locked .icon {
  filter: grayscale(0.6);
  opacity: 0.7;
}
.badge {
  position: absolute;
  top: 10px;
  right: 10px;
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.2);
}
@keyframes float {
  0%,
  100% {
    transform: translateY(0);
  }
  50% {
    transform: translateY(-5px);
  }
}
</style>
