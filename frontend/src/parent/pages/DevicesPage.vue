<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ApiError, api } from '@/shared/api/client'
import type { Device } from '@/shared/api/types'
import { formatInTimezone } from '@/shared/time'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const session = useSessionStore()
const devices = ref<Device[]>([])
const loading = ref(true)
const name = ref(t('parent.devices.defaultName'))
const authorizing = ref(false)
const needKid = computed(() => route.query.for === 'kid')

const active = computed(() => devices.value.filter((d) => !d.revoked_at))
const revoked = computed(() => devices.value.filter((d) => d.revoked_at))
const currentAuthorized = computed(() => active.value.some((d) => d.is_current))

async function load(): Promise<void> {
  loading.value = true
  try {
    devices.value = await api.get<Device[]>('/parent/devices')
  } finally {
    loading.value = false
  }
}

async function enterKidMode(): Promise<void> {
  await session.logout()
  await router.push({ name: 'kid-select' })
}

async function authorize(): Promise<void> {
  if (!name.value.trim()) {
    ElMessage.warning(t('parent.devices.nameRequired'))
    return
  }
  authorizing.value = true
  try {
    await api.post<Device>('/parent/devices', { name: name.value.trim() })
    ElMessage.success(t('parent.devices.authorizedOk'))
    await load()
    await session.checkDevice()
    if (needKid.value) {
      await enterKidMode()
      return
    }
  } catch (e) {
    if (e instanceof ApiError && e.code !== 'SUDO_REQUIRED') ElMessage.error(e.message)
  } finally {
    authorizing.value = false
  }
}

async function revoke(device: Device): Promise<void> {
  try {
    await ElMessageBox.confirm(t('parent.devices.revokeConfirmLong', { name: device.name }), t('parent.devices.revokeTitle'), {
      type: 'warning',
      confirmButtonText: t('common.revoke'),
      cancelButtonText: t('common.cancel'),
    })
    await api.delete(`/parent/devices/${device.id}`)
    ElMessage.success(t('common.revokedOk'))
    await load()
  } catch (e) {
    if (e instanceof ApiError && e.code !== 'SUDO_REQUIRED') ElMessage.error(e.message)
  }
}

onMounted(load)
</script>

<template>
  <div class="devices">
    <section class="block">
      <h2>{{ t('parent.devices.title') }}</h2>
      <p class="desc">{{ t('parent.devices.desc') }}</p>
      <el-alert v-if="needKid && !currentAuthorized" type="warning" :closable="false" show-icon :title="t('parent.devices.needKid')" class="ok" />
      <el-alert v-else-if="currentAuthorized" type="success" :closable="false" show-icon :title="t('parent.devices.authorized')" class="ok" />
      <div class="form">
        <el-input v-model="name" maxlength="64" :placeholder="t('parent.devices.namePlaceholder')" @keyup.enter="authorize" />
        <el-button type="primary" :loading="authorizing" @click="authorize">
          {{
            needKid && !currentAuthorized
              ? t('parent.devices.authorizeAndKid')
              : currentAuthorized
                ? t('parent.devices.reauthorize')
                : t('common.authorizeVerb')
          }}
        </el-button>
        <el-button v-if="currentAuthorized && needKid" type="success" @click="enterKidMode">{{ t('parent.devices.enterKid') }}</el-button>
      </div>
    </section>

    <section v-loading="loading" class="block">
      <h2>{{ t('parent.devices.list') }}</h2>
      <el-empty v-if="!loading && active.length === 0" :description="t('parent.devices.empty')" />
      <div v-for="d in active" :key="d.id" class="row">
        <div>
          <div class="name">
            {{ d.name }} <el-tag v-if="d.is_current" size="small">{{ t('parent.devices.current') }}</el-tag>
          </div>
          <div class="meta">
            {{
              t('parent.devices.byAt', {
                who: d.authorized_by ?? '—',
                at: formatInTimezone(d.created_at, session.timezone),
                last: formatInTimezone(d.last_used_at, session.timezone),
              })
            }}
          </div>
        </div>
        <el-button size="small" type="danger" plain @click="revoke(d)">{{ t('common.revoke') }}</el-button>
      </div>
    </section>

    <section v-if="revoked.length" class="block">
      <h2>{{ t('parent.devices.revokedSection') }}</h2>
      <div v-for="d in revoked" :key="d.id" class="row muted">
        <div>
          <div class="name">{{ d.name }}</div>
          <div class="meta">{{ t('parent.devices.revokedAt', { at: formatInTimezone(d.revoked_at, session.timezone) }) }}</div>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.devices {
  display: flex;
  flex-direction: column;
  gap: 16px;
  max-width: 900px;
}
.block {
  background: #fff;
  border-radius: 14px;
  padding: 16px 20px;
}
h2 {
  font-size: 16px;
  margin: 0 0 8px;
}
.desc {
  color: #6b6f80;
  font-size: 13px;
  line-height: 1.6;
  margin: 0 0 12px;
}
.ok {
  margin-bottom: 12px;
}
.form {
  display: flex;
  gap: 8px;
}
.row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  padding: 12px 0;
  border-bottom: 1px solid #f0f1f5;
}
.row.muted {
  opacity: 0.6;
}
.name {
  font-weight: 600;
}
.meta {
  color: #6b6f80;
  font-size: 12px;
  margin-top: 4px;
}
</style>
