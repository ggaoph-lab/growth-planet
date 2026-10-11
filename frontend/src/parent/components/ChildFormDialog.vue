<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { ApiError, api } from '@/shared/api/client'
import type { ChildStatus, ParentChild } from '@/shared/api/types'
import { AVATARS, THEME_COLORS } from '@/shared/avatars'

const { t } = useI18n()
const props = defineProps<{ child: ParentChild | null }>()
const visible = defineModel<boolean>({ required: true })
const emit = defineEmits<{ saved: [child: ParentChild] }>()

const isEdit = computed(() => props.child !== null)
const form = reactive({
  nickname: '',
  avatar: 'astronaut',
  birth_date: '',
  grade: 1,
  theme_color: THEME_COLORS[0] ?? '#5B8DEF',
  status: 'active' as ChildStatus,
  pin: '',
})
const errors = ref<Record<string, string>>({})
const submitting = ref(false)

watch(visible, (open) => {
  if (!open) return
  errors.value = {}
  const c = props.child
  Object.assign(form, {
    nickname: c?.nickname ?? '',
    avatar: c?.avatar ?? 'astronaut',
    birth_date: c?.birth_date ?? '',
    grade: c?.grade ?? 1,
    theme_color: c?.theme_color ?? THEME_COLORS[0],
    status: c?.status ?? 'active',
    pin: '',
  })
})

const today = new Date()
const disabledDate = (d: Date) => d > today

async function submit(): Promise<void> {
  submitting.value = true
  errors.value = {}
  try {
    const saved = isEdit.value
      ? await api.patch<ParentChild>(`/parent/children/${props.child?.id}`, {
          nickname: form.nickname,
          avatar: form.avatar,
          grade: form.grade,
          theme_color: form.theme_color,
          status: form.status,
        })
      : await api.post<ParentChild>('/parent/children', {
          nickname: form.nickname,
          avatar: form.avatar,
          birth_date: form.birth_date,
          grade: form.grade,
          theme_color: form.theme_color,
          pin: form.pin || null,
        })
    ElMessage.success(t('common.saved'))
    emit('saved', saved)
    visible.value = false
  } catch (e) {
    if (e instanceof ApiError && e.code === 'VALIDATION_FAILED') {
      errors.value = Object.fromEntries(Object.entries(e.fieldErrors).map(([k, v]) => [k, v[0] ?? '']))
    } else {
      ElMessage.error(e instanceof ApiError ? e.message : t('common.saveFailed'))
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <el-dialog v-model="visible" :title="isEdit ? t('parent.children.edit') : t('parent.children.create')" width="min(520px, 94vw)" :close-on-click-modal="false">
    <el-form label-position="top" @submit.prevent="submit">
      <el-form-item :label="t('parent.children.nickname')" :error="errors.nickname">
        <el-input v-model="form.nickname" maxlength="32" :placeholder="t('parent.children.nicknameHint')" />
      </el-form-item>
      <el-form-item :label="t('parent.children.avatar')" :error="errors.avatar">
        <div class="avatars">
          <button v-for="(emoji, code) in AVATARS" :key="code" type="button" class="avatar" :class="{ selected: form.avatar === code }" :aria-label="code" @click="form.avatar = code">
            {{ emoji }}
          </button>
        </div>
      </el-form-item>
      <el-form-item v-if="!isEdit" :label="t('parent.children.birthDate')" :error="errors.birth_date">
        <el-date-picker v-model="form.birth_date" type="date" value-format="YYYY-MM-DD" :disabled-date="disabledDate" :placeholder="t('parent.children.birthHint')" style="width: 100%" />
      </el-form-item>
      <el-form-item :label="t('parent.children.grade')" :error="errors.grade">
        <el-select v-model="form.grade">
          <el-option v-for="g in 6" :key="g" :label="t('parent.children.gradeN', { n: g })" :value="g" />
        </el-select>
      </el-form-item>
      <el-form-item :label="t('parent.children.theme')" :error="errors.theme_color">
        <div class="colors">
          <button v-for="c in THEME_COLORS" :key="c" type="button" class="color" :class="{ selected: form.theme_color === c }" :style="{ background: c }" :aria-label="c" @click="form.theme_color = c" />
        </div>
      </el-form-item>
      <el-form-item v-if="!isEdit" :label="t('parent.children.pinOptional')" :error="errors.pin">
        <el-input v-model="form.pin" inputmode="numeric" maxlength="6" type="password" show-password :placeholder="t('parent.children.pinHint')" />
      </el-form-item>
      <el-form-item v-if="isEdit" :label="t('parent.children.status')" :error="errors.status">
        <el-radio-group v-model="form.status">
          <el-radio-button value="active">{{ t('parent.children.active') }}</el-radio-button>
          <el-radio-button value="paused">{{ t('parent.children.paused') }}</el-radio-button>
          <el-radio-button value="archived">{{ t('parent.children.archived') }}</el-radio-button>
        </el-radio-group>
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="visible = false">{{ t('common.cancel') }}</el-button>
      <el-button type="primary" :loading="submitting" @click="submit">{{ t('common.save') }}</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.avatars {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 8px;
  width: 100%;
}
.avatar {
  aspect-ratio: 1;
  min-height: 44px;
  font-size: 24px;
  border: 2px solid #e4e6ef;
  border-radius: 12px;
  background: #fff;
  cursor: pointer;
}
.avatar.selected {
  border-color: var(--el-color-primary);
  background: var(--el-color-primary-light-9);
}
.colors {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}
.color {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  border: 3px solid transparent;
  cursor: pointer;
}
.color.selected {
  border-color: #1f2333;
}
</style>
