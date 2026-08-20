import { createI18n } from "vue-i18n"
import ja from "./locales/ja.js"
import en from "./locales/en.js"

// 言語判定は takumi.ca(WordPress)の bilingual.php と同じ優先順位を SPA で再現する:
//   1. ?lang=en|ja  … 明示選択
//   2. localStorage … 記憶（テーマ側の Cookie 相当）
//   3. navigator.language(s) … ブラウザ/OS 設定（Accept-Language 相当）
//   4. 既定 'ja'
const STORAGE_KEY = "takumi_lang"
const SUPPORTED = ["ja", "en"]
const DEFAULT_LOCALE = "ja"

// <html lang> 用のロケールコード（en は en-US 相当だが lang 属性は en で十分）
const HTML_LANG = { ja: "ja", en: "en" }

function readStored() {
  try {
    const saved = localStorage.getItem(STORAGE_KEY)
    return SUPPORTED.includes(saved) ? saved : null
  } catch {
    return null
  }
}

function writeStored(locale) {
  try {
    localStorage.setItem(STORAGE_KEY, locale)
  } catch {
    /* localStorage 不可（プライベートモード等）は黙って無視 */
  }
}

function detectLocale() {
  // 1. 明示選択（?lang=）— 見つかれば記憶もする
  const q = new URLSearchParams(window.location.search).get("lang")
  if (SUPPORTED.includes(q)) {
    writeStored(q)
    return q
  }
  // 2. 記憶された選択
  const stored = readStored()
  if (stored) return stored
  // 3. ブラウザ/OS の言語
  const prefs = navigator.languages && navigator.languages.length
    ? navigator.languages
    : [navigator.language || ""]
  for (const pref of prefs) {
    const base = String(pref).toLowerCase().split("-")[0]
    if (SUPPORTED.includes(base)) return base
  }
  // 4. 既定
  return DEFAULT_LOCALE
}

export const i18n = createI18n({
  legacy: false,
  globalInjection: true,
  locale: detectLocale(),
  fallbackLocale: DEFAULT_LOCALE,
  messages: { ja, en },
})

// <html lang> と <title>（英語圏の第一印象/SEOに効く不可視部分）をロケールに同期
function applyDocumentLang(locale) {
  document.documentElement.lang = HTML_LANG[locale] || DEFAULT_LOCALE
  document.title = i18n.global.t("app.title")
}

// トグルや明示切替から呼ぶ。ロケール変更 → 記憶 → <html lang>/<title> 更新
export function setLocale(locale) {
  if (!SUPPORTED.includes(locale)) return
  i18n.global.locale.value = locale
  writeStored(locale)
  applyDocumentLang(locale)
}

// ロケールに紐づく日時フォーマット（各ビューの toLocaleString 用）
export function activeDateLocale() {
  return i18n.global.locale.value === "en" ? "en-CA" : "ja-JP"
}

// 起動時に一度反映
applyDocumentLang(i18n.global.locale.value)

export { SUPPORTED }
