import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import PlanetTile from '@/kid/components/PlanetTile.vue'

const planet = { key: 'movie_planet', title: '电影星球', icon: 'movie', available: false, opens_in_phase: 4 }

describe('PlanetTile', () => {
  it('marks unavailable planets as coming soon', () => {
    const wrapper = mount(PlanetTile, { props: { planet, animate: true } })

    expect(wrapper.text()).toContain('电影星球')
    expect(wrapper.text()).toContain('Coming soon')
    expect(wrapper.text()).toContain('即将开放')
    expect(wrapper.attributes('aria-disabled')).toBe('true')
  })

  it('omits animation class when the child turned animations off', () => {
    const wrapper = mount(PlanetTile, { props: { planet: { ...planet, available: true }, animate: false } })

    expect(wrapper.classes()).not.toContain('animate')
    expect(wrapper.text()).not.toContain('Coming soon')
  })

  it('emits open with the planet on click', async () => {
    const wrapper = mount(PlanetTile, { props: { planet, animate: true } })
    await wrapper.trigger('click')

    expect(wrapper.emitted('open')).toEqual([[planet]])
  })

  it('emits prefetch on pointerdown for available planets', async () => {
    const available = { ...planet, available: true }
    const wrapper = mount(PlanetTile, { props: { planet: available, animate: true } })
    await wrapper.trigger('pointerdown')

    expect(wrapper.emitted('prefetch')).toEqual([[available]])
  })
})
