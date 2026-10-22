import { describe, expect, it } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import PinPad from '@/kid/components/PinPad.vue'

function keyButton(wrapper: VueWrapper, label: string) {
  const button = wrapper.findAll('button').find((b) => b.text() === label)
  if (!button) throw new Error(`no key ${label}`)
  return button
}

function deleteButton(wrapper: VueWrapper) {
  const button = wrapper.findAll('button').find((b) => {
    const aria = b.attributes('aria-label') ?? ''
    return aria.includes('Delete') || aria.includes('删除')
  })
  if (!button) throw new Error('no delete button')
  return button
}

describe('PinPad', () => {
  it('auto-submits when the configured length is reached', async () => {
    const wrapper = mount(PinPad, { props: { length: 4 } })
    for (const d of ['1', '2', '3']) await keyButton(wrapper, d).trigger('click')
    expect(wrapper.emitted('submit')).toBeUndefined()

    await keyButton(wrapper, '4').trigger('click')
    expect(wrapper.emitted('submit')).toEqual([['1234']])
  })

  it('caps input at the configured length and supports backspace', async () => {
    const wrapper = mount(PinPad, { props: { length: 4 } })
    for (const d of ['9', '8', '7']) await keyButton(wrapper, d).trigger('click')
    await deleteButton(wrapper).trigger('click')
    await keyButton(wrapper, '0').trigger('click')
    await keyButton(wrapper, '6').trigger('click')

    expect(wrapper.emitted('submit')).toEqual([['9806']])
    expect(wrapper.findAll('.dot.filled')).toHaveLength(4)
  })

  it('ignores extra taps after auto-submit until cleared', async () => {
    const wrapper = mount(PinPad, { props: { length: 4 } })
    for (const d of ['1', '2', '3', '4', '5']) await keyButton(wrapper, d).trigger('click')

    expect(wrapper.emitted('submit')).toEqual([['1234']])
    expect(wrapper.findAll('.dot.filled')).toHaveLength(4)
  })
})
