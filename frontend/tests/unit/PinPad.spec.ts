import { describe, expect, it } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import PinPad from '@/kid/components/PinPad.vue'

function keyButton(wrapper: VueWrapper, label: string) {
  const button = wrapper.findAll('button').find((b) => b.text() === label)
  if (!button) throw new Error(`no key ${label}`)
  return button
}

function confirmButton(wrapper: VueWrapper) {
  const button = wrapper.findAll('button').find((b) => {
    const aria = b.attributes('aria-label') ?? ''
    return aria.includes('Confirm') || aria.includes('确定')
  })
  if (!button) throw new Error('no confirm button')
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
  it('requires at least 4 digits before submitting', async () => {
    const wrapper = mount(PinPad)
    for (const d of ['1', '2', '3']) await keyButton(wrapper, d).trigger('click')

    expect(confirmButton(wrapper).attributes('disabled')).toBeDefined()

    await keyButton(wrapper, '4').trigger('click')
    await confirmButton(wrapper).trigger('click')

    expect(wrapper.emitted('submit')).toEqual([['1234']])
  })

  it('caps input at the configured length and supports backspace', async () => {
    const wrapper = mount(PinPad, { props: { length: 4 } })
    for (const d of ['9', '8', '7', '6', '5']) await keyButton(wrapper, d).trigger('click')
    await deleteButton(wrapper).trigger('click')
    await keyButton(wrapper, '0').trigger('click')
    await confirmButton(wrapper).trigger('click')

    expect(wrapper.emitted('submit')).toEqual([['9870']])
    expect(wrapper.findAll('.dot.filled')).toHaveLength(4)
  })
})
