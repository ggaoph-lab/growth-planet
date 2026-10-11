export interface ApiErrorBody {
  code: string
  message: string
  details?: Record<string, unknown>
}

import { i18n } from '@/i18n'

function networkErrorMessage(): string {
  return String(i18n.global.t('network.error'))
}

export class ApiError extends Error {
  readonly status: number
  readonly code: string
  readonly details: Record<string, unknown>

  constructor(status: number, body: ApiErrorBody) {
    super(body.message)
    this.status = status
    this.code = body.code
    this.details = body.details ?? {}
  }

  /** Field validation messages from a 422 VALIDATION_FAILED response. */
  get fieldErrors(): Record<string, string[]> {
    const fields = this.details.fields
    return fields && typeof fields === 'object' ? (fields as Record<string, string[]>) : {}
  }
}

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

export interface RequestOptions {
  query?: Record<string, string | number | undefined>
  body?: unknown
  /** When true, sends a unique Idempotency-Key header (required by some mutating endpoints). */
  idempotent?: boolean
}

/**
 * Called on 423 SUDO_REQUIRED. Should ask the parent for their password and resolve true once confirmed.
 * Registered by the parent layout; when absent, the 423 error is surfaced to the caller.
 */
type SudoHandler = () => Promise<boolean>
let sudoHandler: SudoHandler | null = null

export function setSudoHandler(handler: SudoHandler | null): void {
  sudoHandler = handler
}

function readCookie(name: string): string | null {
  const match = document.cookie.split('; ').find((c) => c.startsWith(`${name}=`))
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null
}

export async function ensureCsrfCookie(force = false): Promise<void> {
  if (!force && readCookie('XSRF-TOKEN')) return
  await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
}

function newIdempotencyKey(): string {
  return crypto.randomUUID()
}

async function send(method: Method, path: string, options: RequestOptions, idempotencyKey?: string): Promise<Response> {
  const url = new URL(`/api/v1${path}`, window.location.origin)
  for (const [key, value] of Object.entries(options.query ?? {})) {
    if (value !== undefined && value !== '') url.searchParams.set(key, String(value))
  }

  const headers: Record<string, string> = { Accept: 'application/json' }
  if (method !== 'GET') {
    await ensureCsrfCookie()
    const token = readCookie('XSRF-TOKEN')
    if (token) headers['X-XSRF-TOKEN'] = token
  }
  if (options.body !== undefined) headers['Content-Type'] = 'application/json'
  if (idempotencyKey) headers['Idempotency-Key'] = idempotencyKey.slice(0, 64)

  return fetch(url.pathname + url.search, {
    method,
    headers,
    credentials: 'same-origin',
    body: options.body === undefined ? undefined : JSON.stringify(options.body),
  })
}

async function toError(response: Response): Promise<ApiError> {
  try {
    const json = (await response.json()) as { error?: ApiErrorBody }
    if (json.error) return new ApiError(response.status, json.error)
  } catch {
    // Non-JSON error body (e.g. proxy error page).
  }
  return new ApiError(response.status, { code: 'NETWORK_ERROR', message: networkErrorMessage() })
}

async function requestJson<T>(
  method: Method,
  path: string,
  options: RequestOptions,
  idempotencyKey?: string,
): Promise<T> {
  const key =
    idempotencyKey ??
    (options.idempotent === true && method !== 'GET' ? newIdempotencyKey() : undefined)

  let response: Response
  try {
    response = await send(method, path, options, key)
  } catch {
    throw new ApiError(0, { code: 'NETWORK_ERROR', message: networkErrorMessage() })
  }

  if (response.status === 419) {
    await ensureCsrfCookie(true)
    response = await send(method, path, options, key)
  }

  if (response.status === 423 && sudoHandler) {
    const confirmed = await sudoHandler()
    if (confirmed) response = await send(method, path, options, key)
  }

  if (!response.ok) throw await toError(response)

  return (await response.json()) as T
}

export async function request<T>(method: Method, path: string, options: RequestOptions = {}): Promise<T> {
  const json = await requestJson<{ data: T }>(method, path, options)
  return json.data
}

async function requestIdempotent<T>(method: 'POST' | 'PUT', path: string, body?: unknown): Promise<T> {
  const json = await requestJson<{ data: T }>(method, path, { body }, newIdempotencyKey())
  return json.data
}

export const api = {
  get: <T>(path: string, query?: RequestOptions['query']) => request<T>('GET', path, { query }),
  post: <T>(path: string, body?: unknown, options?: Omit<RequestOptions, 'body'>) =>
    request<T>('POST', path, { ...options, body }),
  put: <T>(path: string, body?: unknown, options?: Omit<RequestOptions, 'body'>) =>
    request<T>('PUT', path, { ...options, body }),
  patch: <T>(path: string, body?: unknown, options?: Omit<RequestOptions, 'body'>) =>
    request<T>('PATCH', path, { ...options, body }),
  delete: <T>(path: string, options?: Omit<RequestOptions, 'body'>) => request<T>('DELETE', path, options ?? {}),
  postIdempotent: <T>(path: string, body?: unknown) => requestIdempotent<T>('POST', path, body),
  putIdempotent: <T>(path: string, body?: unknown) => requestIdempotent<T>('PUT', path, body),
}

/** Paginated endpoints return meta alongside data. */
export function requestPage<T>(path: string, query: RequestOptions['query']): Promise<{ data: T[]; meta: PageMeta }> {
  return requestJson<{ data: T[]; meta: PageMeta }>('GET', path, { query })
}

export interface PageMeta {
  page: number
  per_page: number
  total: number
}
