import { api } from '@/shared/api/client'

type Query = Record<string, string | number | undefined>

type Entry = {
  promise: Promise<unknown>
  value?: unknown
}

const cache = new Map<string, Entry>()
const TTL_MS = 120_000

function keyOf(path: string, query?: Query): string {
  if (!query) return path
  const parts = Object.entries(query)
    .filter(([, value]) => value !== undefined && value !== '')
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([key, value]) => `${key}=${String(value)}`)
  return parts.length ? `${path}?${parts.join('&')}` : path
}

/** Sync read of a settled warm GET — used to paint planet pages without a loading flash. */
export function peekWarmGet<T>(path: string, query?: Query): T | undefined {
  const entry = cache.get(keyOf(path, query))
  return entry && 'value' in entry ? (entry.value as T | undefined) : undefined
}

/**
 * Deduped GET with a short TTL so home prefetch and the page mount share one request.
 * Pass `{ force: true }` after a mutation when the caller needs a fresh payload.
 */
export function warmGet<T>(path: string, query?: Query, options?: { force?: boolean }): Promise<T> {
  const key = keyOf(path, query)
  if (options?.force) cache.delete(key)
  const hit = cache.get(key)
  if (hit) return hit.promise as Promise<T>

  const entry: Entry = {
    promise: null as unknown as Promise<T>,
  }
  entry.promise = api.get<T>(path, query).then(
    (data) => {
      entry.value = data
      window.setTimeout(() => {
        if (cache.get(key) === entry) cache.delete(key)
      }, TTL_MS)
      return data
    },
    (err: unknown) => {
      cache.delete(key)
      throw err
    },
  )
  cache.set(key, entry)
  return entry.promise as Promise<T>
}

export function clearWarmGets(): void {
  cache.clear()
}
