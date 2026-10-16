<template>
  <div>
    <div class="flex justify-between items-center mb-4 gap-3 flex-wrap">
      <h2 class="font-semibold text-gray-700">{{ t('projectDetail.tabCalls') }}</h2>
      <div class="flex gap-2">
        <button @click="startNow" :disabled="starting"
          class="bg-indigo-600 text-white px-3 py-1.5 rounded-lg text-sm hover:bg-indigo-700
                 disabled:opacity-50 cursor-pointer">
          {{ starting ? t('common.loading') : t('call.startNow') }}
        </button>
        <button @click="showForm = !showForm"
          class="border border-mist px-3 py-1.5 rounded-lg text-sm hover:bg-gray-50 cursor-pointer">
          {{ t('call.schedule') }}
        </button>
      </div>
    </div>

    <p v-if="error" class="mb-4 text-sm text-red-600">{{ error }}</p>

    <!-- 予約フォーム -->
    <form v-if="showForm" @submit.prevent="schedule"
      class="bg-white rounded-xl shadow p-5 mb-5 space-y-4">
      <div class="grid sm:grid-cols-2 gap-4">
        <label class="block">
          <span class="text-sm text-gray-600">{{ t('call.titleLabel') }}</span>
          <input v-model="form.title" :placeholder="t('call.titlePlaceholder')"
            class="mt-1 w-full border border-mist rounded-lg px-3 py-2 text-sm" />
        </label>
        <label class="block">
          <span class="text-sm text-gray-600">{{ t('call.whenLabel') }}</span>
          <input v-model="form.scheduled_at" type="datetime-local" required
            class="mt-1 w-full border border-mist rounded-lg px-3 py-2 text-sm" />
        </label>
      </div>

      <div v-if="invitees.length">
        <span class="text-sm text-gray-600">{{ t('call.participants') }}</span>
        <div class="mt-1.5 flex flex-wrap gap-2">
          <label v-for="u in invitees" :key="u.id"
            class="flex items-center gap-2 border border-mist rounded-lg px-3 py-1.5 text-sm cursor-pointer"
            :class="form.user_ids.includes(u.id) ? 'bg-indigo-50 border-indigo-200' : ''">
            <input type="checkbox" :value="u.id" v-model="form.user_ids" />
            {{ u.name }}
          </label>
        </div>
        <p class="text-xs text-gray-400 mt-1.5">{{ t('call.participantsHint', { n: maxParticipants }) }}</p>
      </div>

      <div class="flex gap-2">
        <button type="submit" :disabled="saving"
          class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-indigo-700
                 disabled:opacity-50 cursor-pointer">
          {{ t('call.scheduleSubmit') }}
        </button>
        <button type="button" @click="showForm = false"
          class="px-4 py-2 rounded-lg text-sm text-gray-500 hover:bg-gray-50 cursor-pointer">
          {{ t('common.cancel') }}
        </button>
      </div>
    </form>

    <div v-if="!calls.length" class="text-gray-400 text-sm py-8 text-center">{{ t('call.none') }}</div>

    <div class="space-y-2">
      <div v-for="c in calls" :key="c.id"
        class="bg-white rounded-xl shadow px-5 py-4 flex justify-between items-center gap-4 flex-wrap">
        <div class="min-w-0">
          <div class="font-medium truncate">{{ c.title || t('call.untitled') }}</div>
          <div class="text-sm text-gray-400">
            <span v-if="c.status === 'live'" class="text-indigo-600 font-medium">{{ t('call.liveNow') }}</span>
            <span v-else-if="c.status === 'scheduled'">{{ formatDate(c.scheduled_at) }}</span>
            <span v-else>{{ t('call.endedAt', { at: formatDate(c.ended_at) }) }}</span>
            · {{ names(c) }}
          </div>
        </div>

        <div class="flex items-center gap-2">
          <button v-if="c.status === 'live' || c.status === 'scheduled'" @click="join(c)"
            class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-indigo-700 cursor-pointer">
            {{ c.status === 'live' ? t('call.join') : t('call.open') }}
          </button>
          <button v-if="c.is_host && c.status === 'scheduled'" @click="cancel(c)"
            class="text-sm text-gray-400 hover:text-red-500 cursor-pointer">
            {{ t('common.cancel') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '../api'
import { activeDateLocale } from '../i18n'

const props = defineProps({ projectId: { type: [String, Number], required: true } })

const { t } = useI18n()
const router = useRouter()

const calls = ref([])
const invitees = ref([])
const showForm = ref(false)
const starting = ref(false)
const saving = ref(false)
const error = ref('')
const maxParticipants = ref(4)
const form = ref({ title: '', scheduled_at: '', user_ids: [] })

// 一覧は開いているあいだだけ緩く更新する。相手が通話を始めたら「参加」が出るように。
let timer = null

async function load() {
  try {
    const { data } = await api.get(`/projects/${props.projectId}/calls`)
    calls.value = data.data || data
  } catch {
    // 一時的な失敗は次の周期で拾う。
  }
}

async function startNow() {
  starting.value = true
  error.value = ''
  try {
    const { data } = await api.post(`/projects/${props.projectId}/calls`, {})
    router.push(`/projects/${props.projectId}/calls/${data.data.id}`)
  } catch (e) {
    error.value = e.response?.data?.message || t('call.startFailed')
  } finally {
    starting.value = false
  }
}

async function schedule() {
  saving.value = true
  error.value = ''
  try {
    await api.post(`/projects/${props.projectId}/calls`, {
      title: form.value.title || null,
      // datetime-local はローカル時刻の文字列なので、ISO に直してから送る。
      scheduled_at: new Date(form.value.scheduled_at).toISOString(),
      user_ids: form.value.user_ids.length ? form.value.user_ids : undefined,
    })
    showForm.value = false
    form.value = { title: '', scheduled_at: '', user_ids: [] }
    await load()
  } catch (e) {
    error.value = e.response?.data?.message
      || Object.values(e.response?.data?.errors || {})[0]?.[0]
      || t('call.startFailed')
  } finally {
    saving.value = false
  }
}

function join(call) {
  router.push(`/projects/${props.projectId}/calls/${call.id}`)
}

async function cancel(call) {
  try {
    await api.post(`/projects/${props.projectId}/calls/${call.id}/end`)
    await load()
  } catch (e) {
    error.value = e.response?.data?.message || t('call.startFailed')
  }
}

function names(call) {
  return (call.participants || []).map((p) => p.user?.name).filter(Boolean).join('、')
}

function formatDate(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleString(activeDateLocale(), {
    month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
  })
}

onMounted(async () => {
  await load()
  try {
    const [{ data: inv }, { data: ice }] = await Promise.all([
      api.get(`/projects/${props.projectId}/calls/invitees`),
      api.get('/calls/ice'),
    ])
    invitees.value = inv.data || inv
    maxParticipants.value = ice.max_participants || 4
  } catch {
    // 招待先が取れなくても、宛先未指定なら全員招待になるので予約自体はできる。
  }
  timer = setInterval(load, 15000)
})

onUnmounted(() => clearInterval(timer))
</script>
