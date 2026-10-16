/**
 * 「料金」ページに並べるサービスと金額。
 *
 * 文言は i18n の辞書（`pricing.plans.*` / `pricing.addons.*`）から引き、ここには
 * 金額と構成だけを置く。日本語と英語で値段が食い違う事故を防ぐため、数値は
 * 言語ごとに複製しない。
 *
 * 契約データ（誰がどのプランか）はまだ持っていない。ここは「何にいくらかかるか」
 * を確認する場所で、実際に請求した金額は請求書の一覧が正になる。
 */

/** 月額のホスティング・保守プラン。`key` が辞書のキー。 */
export const HOSTING_PLANS = [
  { key: 'lite', monthly: 3000, featured: false, features: ['hosting', 'ssl', 'backup', 'uptime'] },
  { key: 'standard', monthly: 6000, featured: true, features: ['hosting', 'ssl', 'backup', 'uptime', 'updates', 'edits'] },
  { key: 'business', monthly: 12000, featured: false, features: ['hosting', 'ssl', 'backup', 'uptime', 'updates', 'editsPlus', 'report', 'priority'] },
]

/** 制作の目安（初期費用）。 */
export const BUILD_PLANS = [
  { key: 'lp', from: 80000 },
  { key: 'corporate', from: 250000 },
  { key: 'ec', from: 450000 },
]

/** 単発・オプション。`unit` が辞書の単位キー。 */
export const ADDONS = [
  { key: 'page', price: 25000, unit: 'perPage' },
  { key: 'content', price: 8000, unit: 'perHour' },
  { key: 'seo', price: 40000, unit: 'perOnce' },
  { key: 'photo', price: 30000, unit: 'perDay' },
]

/** 請求のサイクルなど、金額以外の約束事。 */
export const TERMS = ['billingCycle', 'paymentMethod', 'taxNote', 'cancelNote']
