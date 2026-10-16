/*
 * Service Worker — 端末への通知（Web Push）専用。
 *
 * やることは「届いたプッシュを通知として出す」「通知を押したら該当画面を開く」だけ。
 * オフライン用のキャッシュは持たない（デプロイ後に古い画面が残る事故を避けるため）。
 *
 * 本文はサーバー（App\Services\PushNotifier::payload）が受け取り手の言語で作って送ってくる。
 * ここで手を入れるのは予約日時だけ（端末のタイムゾーンで出すため）。
 */

self.addEventListener("install", () => self.skipWaiting())
self.addEventListener("activate", (event) => event.waitUntil(self.clients.claim()))

function formatAt(iso, locale) {
  try {
    return new Date(iso).toLocaleString(locale === "en" ? "en-CA" : "ja-JP", {
      month: "short", day: "numeric", weekday: "short", hour: "2-digit", minute: "2-digit",
    })
  } catch {
    return iso
  }
}

self.addEventListener("push", (event) => {
  let data = {}
  try {
    data = event.data ? event.data.json() : {}
  } catch {
    data = { title: "Takumi Web Services", body: event.data ? event.data.text() : "" }
  }

  let body = data.body || ""
  if (data.at) body = body.replace("{{at}}", formatAt(data.at, data.locale))

  const isCall = data.type === "call_incoming"

  event.waitUntil(
    self.registration.showNotification(data.title || "Takumi Web Services", {
      body,
      icon: "/favicon-192.png",
      badge: "/favicon-192.png",
      tag: data.tag,
      // 同じ tag で置き換わったときも、もう一度鳴らす（同じ案件の2通目のメッセージなど）。
      renotify: !!data.tag,
      // 着信は押すか閉じるまで出し続ける（対応ブラウザのみ）。
      requireInteraction: isCall,
      vibrate: isCall ? [300, 150, 300, 150, 300] : [120],
      data: { url: data.url || "/" },
    }),
  )
})

self.addEventListener("notificationclick", (event) => {
  event.notification.close()
  const target = new URL(event.notification.data?.url || "/", self.location.origin).href

  event.waitUntil(
    (async () => {
      const windows = await self.clients.matchAll({ type: "window", includeUncontrolled: true })
      // 既に開いている画面があれば、それを前に出して移動する（タブを増やさない）。
      for (const client of windows) {
        if (new URL(client.url).origin !== self.location.origin) continue
        await client.focus()
        // navigate() はこの SW の管理下にない画面では失敗するので、そのときは SPA に頼む。
        try {
          if ("navigate" in client && (await client.navigate(target))) return
        } catch { /* fall through */ }
        client.postMessage({ type: "navigate", url: target })
        return
      }
      return self.clients.openWindow(target)
    })(),
  )
})

// 配信サーバー側で購読が作り直されたとき（まれ）。次に画面を開いたときに
// services/push.js の sync() が新しい購読をサーバーへ登録し直すので、ここでは何もしない。
self.addEventListener("pushsubscriptionchange", () => {})
