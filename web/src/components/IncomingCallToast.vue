<template>
  <transition name="fade">
    <div v-if="incoming"
      class="fixed bottom-5 right-5 z-50 w-[min(22rem,calc(100vw-2.5rem))] bg-white rounded-2xl border border-mist
             overflow-hidden"
      style="box-shadow: 0 18px 40px rgba(16,35,58,0.22)">
      <div class="px-4 pt-4 pb-3">
        <div class="flex items-center gap-2 text-xs text-gray-500 mb-1.5">
          <span class="relative flex h-2 w-2">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-indigo-400 opacity-75"></span>
            <span class="relative inline-flex rounded-full h-2 w-2 bg-indigo-600"></span>
          </span>
          {{ scheduled ? t('call.incomingScheduled') : t('call.incoming') }}
        </div>

        <div class="font-serif text-lg text-ink leading-tight truncate">
          {{ incoming.creator?.name || t('call.guest') }}
        </div>
        <div class="text-sm text-gray-500 truncate">
          {{ incoming.title || incoming.project?.title || t('call.untitled') }}
        </div>
        <div v-if="scheduled" class="text-xs text-gray-400 mt-1">
          {{ formatTime(incoming.scheduled_at) }}
        </div>
      </div>

      <div class="flex border-t border-mist">
        <button @click="decline"
          class="flex-1 py-3 text-sm text-gray-500 hover:bg-gray-50 cursor-pointer transition">
          {{ t('call.decline') }}
        </button>
        <button @click="accept"
          class="flex-1 py-3 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 cursor-pointer transition">
          {{ t('call.accept') }}
        </button>
      </div>
    </div>
  </transition>
</template>

<script setup>
/*
 * 着信の受け口。
 *
 * 共有ホスティングでは WebSocket で着信を押し込めないので、ログイン中は数秒おきに
 * /calls/ringing を引く。返るのは 0〜1 件で、鳴らすべきかどうかの判断は全てサーバー側
 * （断った・退出した通話は返らない）。ここは表示と応答だけを持つ。
 */
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '../api'
import { useAuthStore } from '../stores/auth'
import { activeDateLocale } from '../i18n'

const { t } = useI18n()
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const incoming = ref(null)
const scheduled = computed(() => incoming.value?.status === 'scheduled')

let timer = null
let intervalMs = 5000
let audioContext = null
let lastRungId = null

// 通話画面にいるあいだは鳴らさない。自分が今出ている通話の話なので邪魔にしかならない。
const inCall = computed(() => route.path.includes('/calls/'))

async function poll() {
  if (!auth.isAuthenticated || inCall.value) {
    incoming.value = null
    return
  }

  try {
    const { data } = await api.get('/calls/ringing')
    if (typeof data.poll_interval_ms === 'number') intervalMs = data.poll_interval_ms

    const next = data.data?.[0] || null
    incoming.value = next

    // 同じ通話で鳴り続けないよう、着信が切り替わったときだけ鳴らす。
    if (next && next.id !== lastRungId) {
      lastRungId = next.id
      chime()
    }
    if (!next) lastRungId = null
  } catch {
    // 一時的な通信断。次の周期で拾い直す。
  }
}

/**
 * 呼び出し音。音声ファイルを同梱したくないので WebAudio で 2 音鳴らす。
 * ブラウザの自動再生制限で鳴らないことがあるが、着信表示自体は出るので実害はない。
 */
function chime() {
  try {
    audioContext = audioContext || new (window.AudioContext || window.webkitAudioContext)()
    if (audioContext.state === 'suspended') audioContext.resume()

    const now = audioContext.currentTime
    for (const [index, frequency] of [880, 1174].entries()) {
      const osc = audioContext.createOscillator()
      const gain = audioContext.createGain()
      osc.frequency.value = frequency
      osc.type = 'sine'
      gain.gain.setValueAtTime(0.0001, now + index * 0.18)
      gain.gain.exponentialRampToValueAtTime(0.12, now + index * 0.18 + 0.02)
      gain.gain.exponentialRampToValueAtTime(0.0001, now + index * 0.18 + 0.16)
      osc.connect(gain).connect(audioContext.destination)
      osc.start(now + index * 0.18)
      osc.stop(now + index * 0.18 + 0.18)
    }
  } catch {
    // 音が出せない環境は無視。
  }
}

function schedule() {
  clearTimeout(timer)
  timer = setTimeout(async () => {
    await poll()
    schedule()
  }, intervalMs)
}

async function accept() {
  const call = incoming.value
  incoming.value = null
  if (call) router.push(`/projects/${call.project_id}/calls/${call.id}`)
}

async function decline() {
  const call = incoming.value
  incoming.value = null
  if (!call) return
  try {
    await api.post(`/projects/${call.project_id}/calls/${call.id}/decline`)
  } catch {
    // 断りが届かなくても、こちらの表示は消えているので実害はない。
  }
}

function formatTime(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleString(activeDateLocale(), {
    month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
  })
}

onMounted(async () => {
  if (auth.isAuthenticated) await poll()
  schedule()
})

onUnmounted(() => clearTimeout(timer))

// ログイン直後にも取りに行く（起動時は未認証で空振りしているため）。
watch(() => auth.isAuthenticated, (on) => { if (on) poll() })
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity .2s, transform .2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; transform: translateY(8px); }
</style>
