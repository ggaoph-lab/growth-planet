import { describe, expect, it } from 'vitest'
import { formatInTimezone } from '@/shared/time'

describe('formatInTimezone', () => {
  it('runs with a device timezone different from the family timezone', () => {
    expect(Intl.DateTimeFormat().resolvedOptions().timeZone).toBe('America/New_York')
  })

  it('formats in the family timezone, not the device timezone', () => {
    // 2026-10-11 23:30 in Shanghai is still 2026-10-11 11:30 in New York.
    expect(formatInTimezone('2026-10-11T23:30:00+08:00', 'Asia/Shanghai')).toBe('2026-10-11 23:30')
    expect(formatInTimezone('2026-10-11T15:30:00Z', 'Asia/Shanghai', 'YYYY-MM-DD')).toBe('2026-10-11')
    expect(formatInTimezone('2026-10-11T16:30:00Z', 'Asia/Shanghai', 'YYYY-MM-DD')).toBe('2026-10-12')
  })

  it('shows a dash for missing values', () => {
    expect(formatInTimezone(null, 'Asia/Shanghai')).toBe('—')
  })
})
