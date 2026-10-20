<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Planet } from '@/shared/api/types'
import { planetTitle } from '@/shared/planetTitle'

const props = defineProps<{ planet: Planet; animate: boolean }>()
const emit = defineEmits<{ open: [planet: Planet] }>()
const { t } = useI18n()

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
  gamepad: '🎮',
  gift: '🎁',
  museum: '🏛️',
}

const icon = computed(() => ICONS[props.planet.icon] ?? '⭐')
</script>

<template>
  <button type="button" class="tile" :class="{ locked: !planet.available, animate }" :aria-disabled="!planet.available" @click="emit('open', planet)">
    <span class="icon" aria-hidden="true">{{ icon }}</span>
    <span class="title">{{ title }}</span>
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
  min-height: 132px;
  padding: 16px 8px;
  border: none;
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
