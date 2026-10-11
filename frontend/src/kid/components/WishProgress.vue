<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { wishProgressPercent } from '@/kid/wishProgress'

const props = defineProps<{
  available: number
  price: number | null
}>()

const { t } = useI18n()
const percent = computed(() => wishProgressPercent(props.available, props.price))
const priced = computed(() => props.price != null && props.price > 0)
const label = computed(() =>
  priced.value
    ? t('kid.wishes.progress', { have: Math.max(0, props.available), need: props.price })
    : t('kid.wishes.noPrice'),
)
</script>

<template>
  <div class="wish-progress">
    <div class="label">
      <span>{{ label }}</span>
      <span v-if="priced">{{ percent }}%</span>
    </div>
    <div
      class="track"
      role="progressbar"
      :aria-valuenow="percent"
      aria-valuemin="0"
      aria-valuemax="100"
      :aria-label="label"
    >
      <div class="fill" :style="{ width: `${percent}%` }" />
    </div>
  </div>
</template>

<style scoped>
.label {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 6px;
  font-size: 14px;
}
.track {
  height: 12px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.16);
  overflow: hidden;
}
.fill {
  height: 100%;
  border-radius: inherit;
  background: var(--gp-sun);
}
</style>
