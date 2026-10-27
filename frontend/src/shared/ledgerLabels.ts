import { i18n } from '@/i18n'
import type { LedgerEntry } from '@/shared/api/types'

export function ledgerEntryLabel(entryType: string): string {
  const key = `ledger.entryTypes.${entryType}`
  return i18n.global.te(key) ? String(i18n.global.t(key)) : entryType
}

function translateOptional(prefix: string, code: string | null | undefined): string | null {
  if (!code) return null
  const key = `${prefix}.${code}`
  return i18n.global.te(key) ? String(i18n.global.t(key)) : null
}

/** Human-readable title with concrete source, e.g. "家长扣分 · 晚睡" / "完成任务 · 整理书桌". */
export function ledgerEntryTitle(entry: LedgerEntry): string {
  const typeLabel = ledgerEntryLabel(entry.entry_type)
  const note = entry.note?.trim() || null
  const name = entry.source_name?.trim() || null
  const policy = translateOptional('ledger.policies', entry.policy_code)
  const source = translateOptional('ledger.sources', entry.source_type)

  if (entry.entry_type === 'parent_deduction' || entry.entry_type === 'parent_bonus') {
    return note ? `${typeLabel} · ${note}` : typeLabel
  }

  if (entry.entry_type === 'redemption' || entry.entry_type === 'refund') {
    return name ? `${typeLabel} · ${name}` : typeLabel
  }

  const head = policy || source || typeLabel
  const detail = name || note
  if (detail && detail !== head) return `${head} · ${detail}`
  return head
}

/** Secondary line category (without repeating the concrete name). */
export function ledgerEntryKind(entry: LedgerEntry): string {
  return (
    translateOptional('ledger.policies', entry.policy_code) ||
    translateOptional('ledger.sources', entry.source_type) ||
    ledgerEntryLabel(entry.entry_type)
  )
}
