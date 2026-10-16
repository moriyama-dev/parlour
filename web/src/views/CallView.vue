<template>
  <div class="fixed inset-0 bg-ink flex flex-col z-50">
    <!-- ヘッダー -->
    <header class="flex items-center justify-between gap-3 px-4 py-2.5 bg-black/30 text-white shrink-0">
      <div class="min-w-0">
        <div class="font-serif text-base leading-tight truncate">
          {{ call?.title || t('call.untitled') }}
        </div>
        <div class="text-xs text-white/50 truncate">
          {{ call?.project?.title }}<span v-if="elapsed"> · {{ elapsed }}</span>
        </div>
      </div>
      <div class="flex items-center gap-2 text-xs">
        <span v-if="!hasTurn" :title="t('call.noTurnHelp')"
          class="hidden sm:inline px-2 py-1 rounded-full bg-white/10 text-white/60">
          {{ t('call.noTurn') }}
        </span>
        <span class="px-2 py-1 rounded-full bg-white/10 text-white/70">
          {{ t('call.peopleCount', { n: peers.length + 1 }) }}
        </span>
      </div>
    </header>

    <!-- 事前チェック / エラー -->
    <div v-if="fatal" class="flex-1 flex items-center justify-center p-6">
      <div class="max-w-md bg-white rounded-2xl p-6 text-center shadow-xl">
        <div class="text-3xl mb-3">⚠️</div>
        <p class="text-ink font-medium mb-2">{{ fatal.title }}</p>
        <p class="text-sm text-gray-600 whitespace-pre-line mb-5">{{ fatal.detail }}</p>
        <button @click="exit" class="px-5 py-2 rounded-lg bg-indigo-600 text-white text-sm cursor-pointer">
          {{ t('call.back') }}
        </button>
      </div>
    </div>

    <template v-else>
      <!-- 障害の通知。今まで onWarning を握り潰していたので、転送が拒否されても
           接続が切れても画面上は何も起きなかった。 -->
      <div v-if="notice"
        class="mx-3 mt-2 px-4 py-2.5 rounded-lg bg-amber-500/20 border border-amber-500/40
               text-amber-100 text-sm flex items-center justify-between gap-3 shrink-0">
        <span>{{ notice }}</span>
        <button @click="notice = ''" class="text-amber-200/60 hover:text-white cursor-pointer">✕</button>
      </div>

      <div class="flex-1 flex min-h-0">
        <!-- ステージ -->
        <div class="flex-1 flex flex-col min-w-0 p-3 gap-3">
          <!-- 画面共有があるときは、それを主役にして人はサムネイルに落とす -->
          <div v-if="spotlight" class="flex-1 min-h-0 rounded-xl overflow-hidden bg-black relative">
            <CallVideo :stream="spotlight.stream" muted contain />
            <div class="absolute left-3 top-3 px-2.5 py-1 rounded-md bg-black/60 text-white text-xs">
              {{ t('call.sharingScreen', { name: spotlight.name }) }}
            </div>
          </div>

          <div class="grid gap-3 min-h-0"
            :class="spotlight ? 'grid-flow-col auto-cols-[10rem] h-32 overflow-x-auto' : gridClass">
            <div v-for="tile in tiles" :key="tile.key"
              class="relative rounded-xl overflow-hidden bg-black/60 min-h-0">
              <CallVideo v-if="tile.stream && tile.cam" :stream="tile.stream" :muted="tile.self"
                :mirror="tile.self && !tile.effected" />
              <div v-else class="w-full h-full flex items-center justify-center">
                <div class="w-16 h-16 rounded-full bg-white/10 flex items-center justify-center
                            font-serif text-2xl text-white/70">
                  {{ initial(tile.name) }}
                </div>
              </div>

              <div class="absolute left-2 bottom-2 flex items-center gap-1.5 px-2 py-1 rounded-md
                          bg-black/60 text-white text-xs max-w-[90%]">
                <span v-if="!tile.mic">🔇</span>
                <span class="truncate">{{ tile.name }}</span>
              </div>

              <div v-if="tile.connecting"
                class="absolute inset-0 flex items-center justify-center bg-black/60 text-white/70 text-xs">
                {{ t('call.connecting') }}
              </div>
            </div>
          </div>

          <p v-if="!peers.length" class="text-center text-white/40 text-xs shrink-0">
            {{ t('call.waitingForOthers') }}
          </p>
        </div>

        <!-- チャット -->
        <aside v-if="showChat"
          class="w-full max-w-xs border-l border-white/10 flex flex-col bg-black/20 shrink-0">
          <div class="px-4 py-3 border-b border-white/10 text-white text-sm flex justify-between items-center">
            <span>{{ t('call.chat') }}</span>
            <button @click="showChat = false" class="text-white/40 hover:text-white cursor-pointer">✕</button>
          </div>

          <div ref="chatScroll" class="flex-1 overflow-y-auto p-3 space-y-2.5">
            <p v-if="!messages.length" class="text-white/30 text-xs text-center py-6">
              {{ t('call.chatEmpty') }}
            </p>
            <div v-for="(m, i) in messages" :key="i" :class="m.mine ? 'text-right' : ''">
              <div class="text-[11px] text-white/40 mb-0.5">{{ m.mine ? t('call.you') : m.name }}</div>
              <div class="inline-block px-3 py-1.5 rounded-lg text-sm break-words max-w-full"
                :class="m.mine ? 'bg-indigo-500 text-white' : 'bg-white/10 text-white'">
                <a v-if="m.url" :href="m.url" :download="m.fileName" class="underline">
                  📎 {{ m.fileName }} ({{ formatSize(m.size) }})
                </a>
                <span v-else>{{ m.text }}</span>
              </div>
            </div>
            <div v-if="transfer" class="text-[11px] text-white/50 text-center">
              {{ t('call.transferring', { name: transfer.name, pct: transfer.pct }) }}
            </div>
          </div>

          <form @submit.prevent="send" class="p-3 border-t border-white/10 flex gap-2">
            <input v-model="draft" :placeholder="t('call.chatPlaceholder')"
              class="flex-1 min-w-0 bg-white/10 text-white text-sm rounded-lg px-3 py-2
                     placeholder:text-white/30 focus:outline-none" />
            <button type="button" @click="pickFile" :title="t('call.sendFile')"
              class="px-2 text-white/60 hover:text-white cursor-pointer">📎</button>
            <input ref="fileInput" type="file" class="hidden" @change="onFilePicked" />
            <button type="submit"
              class="px-3 rounded-lg bg-indigo-500 text-white text-sm cursor-pointer">↑</button>
          </form>
        </aside>
      </div>

      <!-- エフェクト -->
      <div v-if="showEffects"
        class="mx-3 mb-2 p-3 rounded-xl bg-black/40 text-white text-sm flex flex-wrap items-center gap-3 shrink-0">
        <span class="text-white/50 text-xs">{{ t('call.background') }}</span>
        <div class="flex gap-1.5">
          <button v-for="opt in ['none', 'blur', 'image']" :key="opt" @click="setEffect(opt)"
            class="px-3 py-1.5 rounded-lg text-xs cursor-pointer transition"
            :class="effect === opt ? 'bg-white text-ink' : 'bg-white/10 hover:bg-white/20'">
            {{ t('call.effect.' + opt) }}
          </button>
        </div>

        <label v-if="effect === 'blur'" class="flex items-center gap-2 text-xs text-white/60">
          {{ t('call.blurStrength') }}
          <input type="range" min="4" max="30" v-model.number="blurPx" @input="syncEffect" class="w-28" />
        </label>

        <div v-if="effect === 'image'" class="flex items-center gap-2">
          <button v-for="p in presets" :key="p.id" @click="useBackground(p)"
            class="w-10 h-7 rounded border border-white/20 cursor-pointer"
            :style="{ background: p.css }" :title="p.label" />
          <button @click="pickBackground"
            class="px-2.5 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 text-xs cursor-pointer">
            {{ t('call.uploadBackground') }}
          </button>
          <input ref="bgInput" type="file" accept="image/*" class="hidden" @change="onBackgroundPicked" />
        </div>

        <span v-if="effectError" class="text-amber-400 text-xs">{{ t('call.effectUnavailable') }}</span>
      </div>

      <!-- 操作バー -->
      <footer class="px-3 py-3 bg-black/40 flex items-center justify-center gap-2 flex-wrap shrink-0">
        <button @click="toggleMic" :title="t(mic ? 'call.muteMic' : 'call.unmuteMic')"
          class="call-ctl" :class="mic ? 'call-ctl-on' : 'call-ctl-off'">{{ mic ? '🎤' : '🔇' }}</button>

        <button @click="toggleCam" :title="t(cam ? 'call.stopCam' : 'call.startCam')"
          class="call-ctl" :class="cam ? 'call-ctl-on' : 'call-ctl-off'">{{ cam ? '📹' : '🚫' }}</button>

        <button v-if="canShareScreen" @click="toggleScreen" :title="t('call.shareScreen')"
          class="call-ctl" :class="sharing ? 'call-ctl-active' : 'call-ctl-on'">🖥️</button>

        <button v-if="canUseEffects" @click="showEffects = !showEffects" :title="t('call.background')"
          class="call-ctl" :class="effect !== 'none' ? 'call-ctl-active' : 'call-ctl-on'">✨</button>

        <button @click="showChat = !showChat" :title="t('call.chat')"
          class="call-ctl relative" :class="showChat ? 'call-ctl-active' : 'call-ctl-on'">
          💬
          <span v-if="unread && !showChat"
            class="absolute -top-1 -right-1 bg-red-500 text-white text-[10px] rounded-full w-4 h-4
                   flex items-center justify-center">{{ unread > 9 ? '9+' : unread }}</span>
        </button>

        <button @click="hangUp"
          class="ml-2 px-5 py-3 rounded-full bg-red-600 hover:bg-red-500 text-white text-sm cursor-pointer transition">
          {{ isHost ? t('call.endForAll') : t('call.leave') }}
        </button>
      </footer>
    </template>
  </div>
</template>

<script setup>
import { ref, shallowRef, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue'
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '../api'
import { useAuthStore } from '../stores/auth'
import CallVideo from '../components/CallVideo.vue'
import { CallSession } from '../services/webrtc'
import { VideoEffects, effectsSupported, EFFECT_NONE } from '../services/videoEffects'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const projectId = route.params.id
const callId = route.params.callId

const call = ref(null)
const peers = shallowRef([])
const messages = ref([])
const draft = ref('')
const transfer = ref(null)
const unread = ref(0)

const mic = ref(true)
const cam = ref(true)
const sharing = ref(false)
const showChat = ref(false)
const showEffects = ref(false)
const effect = ref(EFFECT_NONE)
const blurPx = ref(12)
const effectError = ref(false)
const fatal = ref(null)
const notice = ref('')
const hasTurn = ref(false)
const startedAt = ref(null)
const now = ref(Date.now())

const localPreview = shallowRef(null)
const chatScroll = ref(null)
const fileInput = ref(null)
const bgInput = ref(null)

let session = null
let effects = null
let localStream = null
let backgroundImage = null
let clock = null

const canUseEffects = computed(() => effectsSupported())
const canShareScreen = computed(() => !!navigator.mediaDevices?.getDisplayMedia)
const isHost = computed(() => !!call.value?.is_host)

// 自分＋相手。自分を先頭に置くと人数が変わってもタイルが飛び回らない。
const tiles = computed(() => {
  const self = {
    key: 'self',
    self: true,
    name: t('call.you'),
    stream: localPreview.value,
    mic: mic.value,
    cam: cam.value,
    // エフェクト適用中は canvas の映像で、既に反転していないので二重に反転しない。
    effected: effect.value !== EFFECT_NONE,
    connecting: false,
  }
  return [
    self,
    ...peers.value.map((p) => ({
      key: 'peer-' + p.userId,
      self: false,
      name: p.user?.name || t('call.guest'),
      stream: p.cameraStream,
      mic: p.mic,
      cam: p.cam && !!p.cameraStream,
      connecting: p.connectionState !== 'connected',
    })),
  ]
})

// 画面共有は 1 つだけ主役にする。自分の共有は自分の画面に出しても意味が薄いので、
// 相手の共有を優先する。
const spotlight = computed(() => {
  const sharer = peers.value.find((p) => p.screenStream)
  if (!sharer) return null
  return { stream: sharer.screenStream, name: sharer.user?.name || t('call.guest') }
})

const gridClass = computed(() => {
  const n = tiles.value.length
  if (n <= 1) return 'grid-cols-1 flex-1'
  if (n === 2) return 'grid-cols-1 sm:grid-cols-2 flex-1'
  return 'grid-cols-2 flex-1'
})

const elapsed = computed(() => {
  if (!startedAt.value) return ''
  const secs = Math.max(0, Math.floor((now.value - startedAt.value) / 1000))
  const m = String(Math.floor(secs / 60)).padStart(2, '0')
  const s = String(secs % 60).padStart(2, '0')
  return `${m}:${s}`
})

const presets = [
  { id: 'paper', label: 'Paper', css: 'linear-gradient(135deg,#fbf7ef,#e7ddcb)' },
  { id: 'navy', label: 'Navy', css: 'linear-gradient(135deg,#173a63,#10233a)' },
  { id: 'gold', label: 'Gold', css: 'linear-gradient(135deg,#d1b07f,#8a6b3d)' },
]

function initial(name) {
  return (name || '?').trim().charAt(0).toUpperCase()
}

function formatSize(bytes) {
  if (!bytes) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB']
  const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)))
  return `${(bytes / 1024 ** i).toFixed(i ? 1 : 0)} ${units[i]}`
}

function refreshPreview() {
  const track = session?.outboundVideoTrack
  localPreview.value = track ? new MediaStream([track]) : null
}

// ------------------------------------------------------------------ 起動

onMounted(async () => {
  // getUserMedia は安全なコンテキスト（HTTPS か localhost）でしか動かない。
  // 平文 HTTP で開いたときに「カメラが壊れた」と誤解されないよう、先に切り分ける。
  if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
    fatal.value = { title: t('call.insecureTitle'), detail: t('call.insecureDetail') }
    return
  }

  try {
    const { data } = await api.get(`/projects/${projectId}/calls/${callId}`)
    call.value = data.data
    startedAt.value = data.data.started_at ? new Date(data.data.started_at).getTime() : Date.now()
  } catch {
    fatal.value = { title: t('call.notFoundTitle'), detail: t('call.notFoundDetail') }
    return
  }

  try {
    localStream = await navigator.mediaDevices.getUserMedia({
      video: { width: { ideal: 1280 }, height: { ideal: 720 } },
      audio: { echoCancellation: true, noiseSuppression: true },
    })
  } catch {
    // カメラ/マイクを断られても、相手の映像と音は受け取れる。通話自体は続ける。
    localStream = null
    mic.value = false
    cam.value = false
  }

  session = new CallSession({
    projectId,
    callId,
    selfId: auth.user.id,
    handlers: {
      onPeers: (list) => { peers.value = list },
      onChat: (m) => pushMessage(m),
      onFile: (f) => pushMessage(f),
      onFileProgress: (p) => {
        // size が 0 のまま割ると Infinity% になる。
        const pct = p.size > 0 ? Math.round((p.received / p.size) * 100) : 0
        transfer.value = { name: p.name, pct }
        if (p.size > 0 && p.received >= p.size) setTimeout(() => { transfer.value = null }, 800)
      },
      onEnded: () => exit(),
      onScreenShareEnded: () => { sharing.value = false },
      onWarning: (error, kind) => {
        if (kind === 'file-too-large') notice.value = t('call.warnFileRejected')
        else if (kind === 'poll-failed') notice.value = t('call.warnConnectionLost')
        // 種別なしは一時的な通信断。次の周期で回復するので黙っておく。
      },
    },
  })

  try {
    await session.start(localStream)
    hasTurn.value = session.hasTurn
    refreshPreview()
  } catch (e) {
    fatal.value = {
      title: t('call.joinFailedTitle'),
      detail: e.response?.data?.message || t('call.joinFailedDetail'),
    }
    return
  }

  clock = setInterval(() => { now.value = Date.now() }, 1000)
})

onBeforeUnmount(() => teardown())
onBeforeRouteLeave(() => { teardown() })

let torn = false
function teardown() {
  if (torn) return
  torn = true
  clearInterval(clock)
  effects?.stop()
  session?.leave()
  localStream?.getTracks().forEach((t) => t.stop())
}

function exit() {
  teardown()
  router.push(auth.isClient ? '/client/dashboard' : `/projects/${projectId}`)
}

async function hangUp() {
  torn = true
  clearInterval(clock)
  await effects?.stop()
  await session?.leave({ end: isHost.value })
  localStream?.getTracks().forEach((t) => t.stop())
  router.push(auth.isClient ? '/client/dashboard' : `/projects/${projectId}`)
}

// ------------------------------------------------------------ 操作

function toggleMic() { mic.value = session.toggleMic(!mic.value) }
function toggleCam() { cam.value = session.toggleCam(!cam.value) }

async function toggleScreen() {
  try {
    if (sharing.value) {
      await session.stopScreenShare()
      sharing.value = false
    } else {
      await session.startScreenShare()
      sharing.value = true
    }
  } catch {
    // 共有ダイアログでキャンセルされただけ。状態を戻すだけでよい。
    sharing.value = false
  }
}

async function setEffect(next) {
  effect.value = next
  effectError.value = false

  if (next === EFFECT_NONE) {
    if (effects) { await effects.stop(); effects = null }
    await session.replaceVideoTrack(localStream?.getVideoTracks()[0] || null)
    refreshPreview()
    return
  }

  if (!localStream?.getVideoTracks().length) return

  if (!effects) {
    effects = new VideoEffects()
    effects.onError = () => { effectError.value = true }
    try {
      const track = await effects.start(localStream.getVideoTracks()[0])
      await session.replaceVideoTrack(track)
    } catch {
      effectError.value = true
      effect.value = EFFECT_NONE
      effects = null
      return
    }
  }

  syncEffect()
  refreshPreview()
}

function syncEffect() {
  effects?.setEffect(effect.value, { blurPx: blurPx.value, backgroundImage })
}

function pickBackground() { bgInput.value?.click() }

function onBackgroundPicked(e) {
  const file = e.target.files?.[0]
  if (!file) return
  // 背景画像はこのブラウザから出ない。サーバーにも相手にも送らない。
  const image = new Image()
  image.onload = () => { backgroundImage = image; syncEffect() }
  image.src = URL.createObjectURL(file)
  e.target.value = ''
}

function useBackground(preset) {
  // プリセットはその場で canvas に描いて画像にする。画像アセットを同梱しないで済む。
  const canvas = document.createElement('canvas')
  canvas.width = 1280
  canvas.height = 720
  const ctx = canvas.getContext('2d')
  const gradient = ctx.createLinearGradient(0, 0, canvas.width, canvas.height)
  const stops = {
    paper: ['#fbf7ef', '#e7ddcb'],
    navy: ['#173a63', '#10233a'],
    gold: ['#d1b07f', '#8a6b3d'],
  }[preset.id]
  gradient.addColorStop(0, stops[0])
  gradient.addColorStop(1, stops[1])
  ctx.fillStyle = gradient
  ctx.fillRect(0, 0, canvas.width, canvas.height)

  const image = new Image()
  image.onload = () => { backgroundImage = image; syncEffect() }
  image.src = canvas.toDataURL()
}

// ------------------------------------------------------------ チャット

function pushMessage(m) {
  messages.value.push(m)
  if (!m.mine && !showChat.value) unread.value++
  nextTick(() => {
    if (chatScroll.value) chatScroll.value.scrollTop = chatScroll.value.scrollHeight
  })
}

function send() {
  if (!draft.value.trim()) return
  session.sendChat(draft.value)
  draft.value = ''
}

function pickFile() { fileInput.value?.click() }

async function onFilePicked(e) {
  const file = e.target.files?.[0]
  e.target.value = ''
  if (!file) return
  try {
    await session.sendFile(file)
  } catch {
    pushMessage({ mine: true, text: t('call.fileFailed'), at: new Date().toISOString() })
  }
}

watch(showChat, (open) => { if (open) unread.value = 0 })
</script>
