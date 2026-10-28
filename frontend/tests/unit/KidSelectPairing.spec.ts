import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, expect, it, vi } from 'vitest'
import KidSelectPage from '@/kid/pages/KidSelectPage.vue'
import { setAppLocale } from '@/i18n'
import { ApiError, api, ensureCsrfCookie } from '@/shared/api/client'

vi.mock('@/shared/api/client', () => ({
  ApiError: class extends Error {
    code: string
    constructor(_status: number, body: { code: string; message: string }) { super(body.message); this.code = body.code }
  },
  api: { get: vi.fn(), post: vi.fn() },
  ensureCsrfCookie: vi.fn().mockResolvedValue(undefined),
}))
vi.mock('@/stores/session', () => ({
  useSessionStore: () => ({ isParent: false, checkDevice: vi.fn().mockResolvedValue(true) }),
}))
vi.mock('vue-router', () => ({ useRouter: () => ({ replace: vi.fn() }) }))
vi.mock('@/kid/KidLayout.vue', () => ({ default: {} }))
vi.mock('@/kid/pages/KidHomePage.vue', () => ({ default: {} }))

beforeEach(() => {
  vi.clearAllMocks()
  setAppLocale('zh-CN')
  let paired = false
  vi.mocked(api.get).mockImplementation(async () => {
    if (!paired) throw new ApiError(401, { code: 'DEVICE_NOT_AUTHORIZED', message: 'unbound' })
    return [{ id: 1, nickname: '小星星', avatar: 'star', theme_color: '#fff', pin_required: true }]
  })
  vi.mocked(api.post).mockImplementation(async () => { paired = true; return { authorized: true } })
})

it('lets an unbound browser enter a pairing code and then choose a child', async () => {
  const wrapper = mount(KidSelectPage, { global: { stubs: ['RouterLink', 'PinPad'] } })
  await flushPromises()
  expect(wrapper.text()).toContain('这个浏览器还没有授权')

  await wrapper.find('#pairing-code').setValue('1234 5678')
  await wrapper.find('#pairing-name').setValue('iPad Chrome')
  await wrapper.find('form.pairing-form').trigger('submit')
  await flushPromises()

  expect(ensureCsrfCookie).toHaveBeenCalled()
  expect(api.post).toHaveBeenCalledWith('/auth/device/pair', { code: '12345678', name: 'iPad Chrome' })
  expect(wrapper.text()).toContain('小星星')
})
