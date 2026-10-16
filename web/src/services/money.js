/**
 * 金額の表示。
 *
 * 請求書は日本円で発行しているのに、画面によって `¥` と `$` が混ざっていた
 * （案件詳細だけ `$` 直書き）。通貨は1か所で決めて全画面で共有する。
 * 端数は円に無いので既定は 0 桁。
 */
import { activeDateLocale } from '../i18n'

export const CURRENCY = 'JPY'

// 円は端数なし、ドル（サブスクは CAD）はセントまで出す。
const FRACTION_DIGITS = { JPY: 0 }

export function formatMoney(amount, { currency = CURRENCY } = {}) {
  const n = Number(amount || 0)
  const digits = FRACTION_DIGITS[currency] ?? 2
  try {
    return new Intl.NumberFormat(activeDateLocale(), {
      style: 'currency',
      currency,
      // en-CA だと既定で "JP¥" になる。他の画面がずっと "¥" なので表記を揃える。
      // CAD は symbol 表示: 日本語では "CA$"（USD と区別できる）、en-CA では地元表記の "$"。
      currencyDisplay: currency === 'JPY' ? 'narrowSymbol' : 'symbol',
      minimumFractionDigits: digits,
      maximumFractionDigits: digits,
    }).format(n)
  } catch {
    return (currency === 'JPY' ? '¥' : currency + ' ') + n.toLocaleString()
  }
}
