import { i18n } from '@/i18n'

export function auditLabel(action: string): string {
  const key = `auditActions.${action}`
  return i18n.global.te(key) ? String(i18n.global.t(key)) : action
}
