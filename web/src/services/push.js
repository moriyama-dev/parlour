// 端末への通知（Web Push）のブラウザ側。
//
// 流れ: Service Worker を登録 → 利用者がボタンを押して通知を許可 → ブラウザが
// 配信サーバー（FCM / Apple など）の購読を発行 → それを API に預ける。
// 以後サーバーはお知らせを作るたびにその購読へ送り、public/sw.js が通知を出す。
//
// iPhone/iPad は「ホーム画面に追加」したアプリからでないと購読できない（iOS 16.4+）。
import api from "../api"
import { i18n } from "../i18n"

export function isSupported() {
  return window.isSecureContext
    && "serviceWorker" in navigator
    && "PushManager" in window
    && "Notification" in window
}

export function isIos() {
  return /iPad|iPhone|iPod/.test(navigator.userAgent)
    || (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1)
}

export function isStandalone() {
  return window.matchMedia("(display-mode: standalone)").matches || navigator.standalone === true
}

export function permission() {
  return "Notification" in window ? Notification.permission : "unsupported"
}

// アプリ起動時に1回。通知を押したときの画面移動も受け取る。
export function registerServiceWorker(router) {
  if (!window.isSecureContext || !("serviceWorker" in navigator)) return
  navigator.serviceWorker.register("/sw.js").catch(() => {})
  navigator.serviceWorker.addEventListener("message", (e) => {
    if (e.data?.type !== "navigate" || !e.data.url) return
    const url = new URL(e.data.url, location.origin)
    if (url.origin === location.origin) router.push(url.pathname + url.search)
  })
}

async function registration() {
  return navigator.serviceWorker.ready
}

export async function currentSubscription() {
  if (!isSupported()) return null
  const reg = await registration()
  return reg.pushManager.getSubscription()
}

function base64UrlToUint8Array(base64Url) {
  const padding = "=".repeat((4 - (base64Url.length % 4)) % 4)
  const raw = atob((base64Url + padding).replace(/-/g, "+").replace(/_/g, "/"))
  return Uint8Array.from(raw, (c) => c.charCodeAt(0))
}

function deviceName() {
  const ua = navigator.userAgent
  const os = /iPhone/.test(ua) ? "iPhone"
    : /iPad/.test(ua) || (isIos() && !/iPhone/.test(ua)) ? "iPad"
    : /Android/.test(ua) ? "Android"
    : /Mac OS X/.test(ua) ? "Mac"
    : /Windows/.test(ua) ? "Windows"
    : "Linux"
  const browser = /Edg\//.test(ua) ? "Edge"
    : /Firefox\//.test(ua) ? "Firefox"
    : /SamsungBrowser/.test(ua) ? "Samsung Internet"
    : /Chrome\//.test(ua) ? "Chrome"
    : /Safari\//.test(ua) ? "Safari"
    : "Browser"
  return `${os} · ${browser}${isStandalone() ? " (App)" : ""}`
}

async function save(sub) {
  const json = sub.toJSON()
  await api.post("/push/subscriptions", {
    endpoint: json.endpoint,
    keys: json.keys,
    device_name: deviceName(),
    locale: i18n.global.locale.value,
  })
}

// 設定画面の「この端末で通知を受け取る」。許可ダイアログはボタン操作の中でしか出せない。
export async function enable(publicKey) {
  if (!isSupported()) throw new Error("unsupported")
  const result = await Notification.requestPermission()
  if (result !== "granted") throw new Error(result === "denied" ? "denied" : "dismissed")

  const reg = await registration()
  const options = { userVisibleOnly: true, applicationServerKey: base64UrlToUint8Array(publicKey) }
  let sub
  try {
    sub = await reg.pushManager.subscribe(options)
  } catch (e) {
    // サーバーの鍵を作り直した後は、古い購読を捨てないと新しい鍵で購読できない。
    const old = await reg.pushManager.getSubscription()
    if (!old) throw e
    await old.unsubscribe()
    sub = await reg.pushManager.subscribe(options)
  }
  await save(sub)
  return sub
}

export async function disable() {
  const sub = await currentSubscription()
  if (!sub) return
  try {
    await api.delete("/push/subscriptions", { data: { endpoint: sub.endpoint } })
  } finally {
    await sub.unsubscribe()
  }
}

// ログアウト時。共用の端末に前の人の通知が届き続けないように、サーバー側の行も消す。
// トークンを捨てる前に呼ぶこと。
export async function forgetThisDevice() {
  try {
    await disable()
  } catch { /* オフライン等。サーバー側は 410 が返った時点で自然に片付く */ }
}

// 起動時・言語切替時。既に購読している端末の言語と持ち主をサーバーに伝え直す。
export async function sync() {
  try {
    if (!isSupported() || permission() !== "granted") return
    const sub = await currentSubscription()
    if (sub) await save(sub)
  } catch { /* 通知はおまけ。失敗しても画面は止めない */ }
}

// 設定画面の端末一覧で「この端末」を見分けるため（サーバーは endpoint の SHA-256 を返す）。
export async function endpointHash(endpoint) {
  const buf = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(endpoint))
  return [...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, "0")).join("")
}
