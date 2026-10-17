// 「アプリとしてインストール」ボタンの裏側。
//
// Chrome / Edge（Android・PC）は、インストールできる状態になると beforeinstallprompt を1回だけ
// 投げてくる。これはページ表示の直後に飛ぶので、main.js から最初に読み込んで取っておき、
// ボタンが押されたときに prompt() で本物の確認ダイアログを出す。
//
// iPhone / iPad の Safari にはこの仕組みが無い（サイトからインストールを始められない）。
// その場合は「共有 → ホーム画面に追加」の手順を画面で案内する。
import { reactive } from "vue"
import { isIos, isStandalone } from "./push"

export const installState = reactive({
  canPrompt: false,   // ボタン1つでインストールできる（Chrome / Edge）
  installed: isStandalone(),
})

let deferred = null

window.addEventListener("beforeinstallprompt", (e) => {
  // ブラウザ既定のミニバーは出さず、こちらのボタンから出す。
  e.preventDefault()
  deferred = e
  installState.canPrompt = true
})

window.addEventListener("appinstalled", () => {
  deferred = null
  installState.canPrompt = false
  installState.installed = true
})

/** 'ios-safari' / 'ios-other'（iPhone の Chrome など）/ 'android' / 'desktop' */
export function platform() {
  const ua = navigator.userAgent
  if (isIos()) return /CriOS|FxiOS|EdgiOS|OPiOS|GSA\//.test(ua) ? "ios-other" : "ios-safari"
  if (/Android/.test(ua)) return "android"
  return "desktop"
}

/** 本物のインストール確認を出す。出せなければ false（手順の案内に切り替える）。 */
export async function promptInstall() {
  if (!deferred) return false
  const e = deferred
  deferred = null
  installState.canPrompt = false
  await e.prompt()
  const { outcome } = await e.userChoice
  if (outcome === "accepted") installState.installed = true
  return true
}
