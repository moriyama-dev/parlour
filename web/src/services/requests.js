/**
 * 依頼キュー（/requests）の画面で共有する小物。
 * 状態・種類・優先度の値の一覧は API の WorkRequest::STATUSES などと揃える。
 */
import { activeDateLocale } from '../i18n'

export const CATEGORIES = ['change', 'bug', 'new_page', 'content', 'design', 'seo', 'other']
export const PRIORITIES = ['low', 'normal', 'high', 'urgent']

// 状態ごとの色。StatusBadge と同じく色だけをここで持ち、文言は requests.status.* から引く。
export const STATUS_COLORS = {
  queued: 'bg-gray-100 text-gray-700',
  in_progress: 'bg-blue-100 text-blue-800',
  client_review: 'bg-yellow-100 text-yellow-800',
  revision: 'bg-orange-100 text-orange-800',
  on_hold: 'bg-purple-100 text-purple-800',
  done: 'bg-green-100 text-green-800',
  cancelled: 'bg-gray-100 text-gray-400',
}

export const PRIORITY_COLORS = {
  low: 'bg-gray-100 text-gray-500',
  normal: 'bg-gray-100 text-gray-600',
  high: 'bg-orange-100 text-orange-800',
  urgent: 'bg-red-100 text-red-700',
}

export function formatDateTime(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleString(activeDateLocale(), { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

export function formatDate(iso) {
  if (!iso) return ''
  // 日付だけの値（YYYY-MM-DD）は UTC の 0 時として読まれ、トロントでは前日になる。正午に寄せる。
  const d = /^\d{4}-\d{2}-\d{2}$/.test(iso) ? new Date(iso + 'T12:00:00') : new Date(iso)
  return d.toLocaleDateString(activeDateLocale(), { year: 'numeric', month: 'short', day: 'numeric' })
}

/** 分 → 時間（小数1桁）。 */
export function hours(minutes) {
  return (Math.round((Number(minutes || 0) / 60) * 10) / 10).toString()
}

/** 納期の目安の表示。24 の倍数なら営業日で言う。 */
export function turnaroundText(t, h) {
  if (!h) return ''
  return h % 24 === 0
    ? t('requests.turnaroundDays', { n: h / 24 })
    : t('requests.turnaroundHours', { n: h })
}
