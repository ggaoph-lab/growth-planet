import dayjs from 'dayjs'
import utc from 'dayjs/plugin/utc'
import timezone from 'dayjs/plugin/timezone'

dayjs.extend(utc)
dayjs.extend(timezone)

/**
 * Formats an ISO 8601 timestamp in the family's timezone, regardless of the device's timezone.
 */
export function formatInTimezone(iso: string | null | undefined, tz: string, pattern = 'YYYY-MM-DD HH:mm'): string {
  if (!iso) return '—'
  return dayjs(iso).tz(tz).format(pattern)
}
