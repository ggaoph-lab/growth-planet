import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import WishProgress from '@/kid/components/WishProgress.vue'

function bar(available: number, price: number | null) {
  return mount(WishProgress, { props: { available, price } }).get('[role="progressbar"]')
}

describe('WishProgress', () => {
  it('caps progress at 100 percent when coins already cover the price', () => {
    const wrapper = mount(WishProgress, { props: { available: 50, price: 20 } })

    expect(bar(50, 20).attributes('aria-valuenow')).toBe('100')
    expect(wrapper.text()).toContain('50 / 20')
    expect(wrapper.text()).toContain('100%')
  })

  it('uses available coins divided by the wish price', () => {
    expect(bar(10, 40).attributes('aria-valuenow')).toBe('25')
  })

  it('stays at zero when the wish has no price yet', () => {
    const wrapper = mount(WishProgress, { props: { available: 10, price: null } })

    expect(wrapper.get('[role="progressbar"]').attributes('aria-valuenow')).toBe('0')
    expect(wrapper.text()).toContain('家长还没定价')
    expect(wrapper.text()).not.toContain('%')
  })

  it('treats a negative balance as zero progress', () => {
    expect(bar(-5, 10).attributes('aria-valuenow')).toBe('0')
  })
})
