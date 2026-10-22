<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

/** PIN is 4–6 digits; pad always allows up to `length` (default 6). */
const props = withDefaults(defineProps<{ length?: number; disabled?: boolean }>(), { length: 6, disabled: false })
const emit = defineEmits<{ submit: [pin: string] }>()

const pin = ref('')
const keys = ['1', '2', '3', '4', '5', '6', '7', '8', '9']
let submitting = false

function press(digit: string): void {
  if (props.disabled || submitting || pin.value.length >= props.length) return
  pin.value += digit
  if (pin.value.length >= props.length) void submit()
}

function backspace(): void {
  if (props.disabled || submitting) return
  pin.value = pin.value.slice(0, -1)
}

function submit(): void {
  if (props.disabled || submitting || pin.value.length < 4) return
  submitting = true
  emit('submit', pin.value)
}

function clear(): void {
  submitting = false
  pin.value = ''
}

onBeforeUnmount(() => {
  submitting = false
})

defineExpose({ clear })
</script>

<template>
  <div class="pinpad">
    <div class="dots" role="status" :aria-label="t('pinpad.digitsEntered', { n: pin.length })">
      <span v-for="i in length" :key="i" class="dot" :class="{ filled: i <= pin.length }" />
    </div>
    <div class="keys">
      <button v-for="k in keys" :key="k" type="button" class="key" :disabled="disabled" @click="press(k)">{{ k }}</button>
      <button type="button" class="key aux" :aria-label="t('pinpad.delete')" :disabled="disabled" @click="backspace">⌫</button>
      <button type="button" class="key" :disabled="disabled" @click="press('0')">0</button>
      <button
        type="button"
        class="key go"
        :aria-label="t('pinpad.confirm')"
        :disabled="disabled || pin.length < 4"
        @click="submit"
      >
        ✓
      </button>
    </div>
  </div>
</template>

<style scoped>
.pinpad {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 20px;
}
.dots {
  display: flex;
  gap: 12px;
}
.dot {
  width: 16px;
  height: 16px;
  border-radius: 50%;
  border: 2px solid rgba(255, 255, 255, 0.7);
}
.dot.filled {
  background: var(--gp-sun);
  border-color: var(--gp-sun);
}
.keys {
  display: grid;
  grid-template-columns: repeat(3, 72px);
  gap: 14px;
}
.key {
  width: 72px;
  height: 72px;
  border-radius: 50%;
  border: none;
  background: rgba(255, 255, 255, 0.16);
  color: #fff;
  font-size: 28px;
  font-weight: 600;
  cursor: pointer;
  touch-action: manipulation;
}
.key:active:not(:disabled) {
  transform: scale(0.94);
  background: rgba(255, 255, 255, 0.3);
}
.key:disabled {
  opacity: 0.4;
}
.key.go {
  background: var(--gp-sun);
  color: var(--gp-space-dark);
}
.key.aux {
  font-size: 24px;
}
</style>
