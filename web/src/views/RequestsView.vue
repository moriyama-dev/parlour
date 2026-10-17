<template>
  <div class="max-w-5xl mx-auto px-4 py-8">
    <div class="flex flex-wrap justify-between items-start gap-3 mb-6">
      <div>
        <h1 class="font-serif text-2xl font-semibold text-ink">{{ t('requests.title') }}</h1>
        <p class="text-sm text-gray-500 mt-1 max-w-2xl">{{ auth.isClient ? t('requests.leadClient') : t('requests.leadDeveloper') }}</p>
      </div>
      <button v-if="companyId" @click="openNew()"
        class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 cursor-pointer">+ {{ t('requests.new') }}</button>
    </div>

    <!-- 会社の切り替え（開発者は全社、顧客は所属が複数のときだけ） -->
    <div v-if="queues.length > 1" class="flex flex-wrap gap-2 mb-5">
      <button v-for="q in queues" :key="q.company_id" @click="selectCompany(q.company_id)"
        :class="['px-3 py-1.5 rounded-full text-sm border cursor-pointer transition',
          q.company_id === companyId ? 'bg-ink text-white border-ink' : 'bg-white text-gray-600 border-mist hover:border-gold']">
        {{ q.company_name }}
        <span v-if="q.counts.client_review + q.counts.queued + q.active" class="ml-1 text-xs opacity-70">
          {{ q.active }}/{{ q.counts.queued }}
        </span>
      </button>
    </div>

    <p v-if="loadError" class="text-sm text-red-500 mb-4">{{ loadError }}</p>
    <div v-if="!loaded" class="text-gray-400 text-sm py-10 text-center">{{ t('common.loading') }}</div>

    <template v-else-if="queue">
      <!-- キューの約束事（Conte の VIP：同時◯件・納期◯時間・平日のみ） -->
      <section class="bg-white rounded-2xl shadow p-5 mb-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-gray-600">
            <span class="font-medium text-ink">{{ t('requests.slots', { active: queue.active, limit: queue.active_limit }) }}</span>
            <span>{{ turnaroundText(t, queue.turnaround_hours) }}</span>
            <span v-if="queue.monthly_hours != null">{{ t('requests.monthlyUsage', { used: hours(queue.minutes_this_month), limit: queue.monthly_hours }) }}</span>
            <span v-else-if="queue.minutes_this_month">{{ t('requests.monthlyUsageNoLimit', { used: hours(queue.minutes_this_month) }) }}</span>
            <span v-if="queue.overdue" class="text-red-600 font-medium">{{ t('requests.overdueCount', { n: queue.overdue }) }}</span>
          </div>
          <div class="flex flex-wrap gap-2">
            <button v-if="auth.isDeveloper" @click="startNext" :disabled="!canStartNext || busy" :title="startNextBlocker"
              class="bg-ink text-white px-3 py-1.5 rounded-lg text-sm hover:opacity-90 disabled:opacity-40 cursor-pointer">
              ▶ {{ t('requests.startNext') }}
            </button>
            <button v-if="queue.paused_at" @click="resume" :disabled="busy"
              class="border border-indigo-600 text-indigo-600 px-3 py-1.5 rounded-lg text-sm hover:bg-indigo-50 cursor-pointer">{{ t('requests.resume') }}</button>
            <button v-else @click="pause" :disabled="busy" :title="t('requests.pauseHint')"
              class="border border-mist text-gray-600 px-3 py-1.5 rounded-lg text-sm hover:border-gold cursor-pointer">{{ t('requests.pause') }}</button>
            <button v-if="auth.isDeveloper" @click="showSettings = !showSettings"
              class="border border-mist text-gray-600 px-3 py-1.5 rounded-lg text-sm hover:border-gold cursor-pointer">⚙ {{ t('requests.settings') }}</button>
          </div>
        </div>
        <p class="text-xs text-gray-400 mt-2">{{ t('requests.weekdaysOnly') }}</p>
        <p v-if="auth.isDeveloper && !canStartNext && startNextBlocker" class="text-xs text-gray-400 mt-1">{{ startNextBlocker }}</p>

        <!-- 休止中（解約ではなく休止） -->
        <div v-if="queue.paused_at" class="mt-4 bg-purple-50 border border-purple-200 rounded-lg px-4 py-3 text-sm text-purple-900">
          <span class="font-medium">{{ t('requests.paused') }}</span> —
          {{ t('requests.pausedBanner', { date: formatDate(queue.paused_at) }) }}
          <div v-if="queue.pause_reason" class="text-xs mt-1">{{ t('requests.pauseReason', { reason: queue.pause_reason }) }}</div>
        </div>

        <form v-if="showSettings && auth.isDeveloper" @submit.prevent="saveSettings"
          class="mt-4 grid sm:grid-cols-[repeat(3,minmax(0,1fr))_auto] gap-3 items-end border-t border-mist pt-4">
          <label class="text-xs text-gray-500">{{ t('requests.activeLimit') }}
            <input v-model.number="settings.active_limit" type="number" min="1" max="10" required class="mt-1 w-full border rounded-lg px-2 py-1.5 text-sm text-ink" />
          </label>
          <label class="text-xs text-gray-500">{{ t('requests.turnaroundLabel') }}
            <input v-model.number="settings.turnaround_hours" type="number" min="1" max="720" required class="mt-1 w-full border rounded-lg px-2 py-1.5 text-sm text-ink" />
          </label>
          <label class="text-xs text-gray-500">{{ t('requests.monthlyHours') }}
            <input v-model="settings.monthly_hours" type="number" min="0" max="999" step="0.5" class="mt-1 w-full border rounded-lg px-2 py-1.5 text-sm text-ink" />
          </label>
          <button type="submit" :disabled="busy" class="bg-indigo-600 text-white px-4 py-1.5 rounded-lg text-sm hover:bg-indigo-700 cursor-pointer">{{ t('common.save') }}</button>
          <p v-if="settingsSaved" class="text-xs text-green-600 sm:col-span-4">{{ t('requests.settingsSaved') }}</p>
        </form>
      </section>

      <p v-if="actionError" class="text-sm text-red-500 mb-4">{{ actionError }}</p>

      <div v-if="requests.length === 0" class="text-center text-gray-400 py-14">
        <div class="text-4xl mb-3">📝</div>
        <p class="font-medium">{{ t('requests.empty') }}</p>
        <p class="text-sm mt-1">{{ t('requests.emptyHint') }}</p>
      </div>

      <template v-else>
        <!-- 確認待ち（顧客の手番）を一番上に -->
        <section v-if="byStatus.client_review.length" class="mb-6">
          <h2 :class="['text-sm font-semibold mb-2', auth.isClient ? 'text-yellow-800' : 'text-gray-500']">
            {{ auth.isClient ? t('requests.sections.reviewClient') : t('requests.sections.reviewDeveloper') }}
          </h2>
          <div :class="['space-y-2', auth.isClient ? 'bg-yellow-50 border border-yellow-200 rounded-xl p-2' : '']">
            <RequestRow v-for="r in byStatus.client_review" :key="r.id" :r="r" />
          </div>
        </section>

        <section class="mb-6">
          <h2 class="text-sm font-semibold text-gray-500 mb-2">{{ t('requests.sections.active') }}</h2>
          <div v-if="active.length === 0" class="text-sm text-gray-400 bg-white rounded-xl border border-dashed border-mist px-4 py-3">—</div>
          <div class="space-y-2"><RequestRow v-for="r in active" :key="r.id" :r="r" /></div>
        </section>

        <section class="mb-6">
          <div class="flex flex-wrap items-baseline justify-between gap-2 mb-2">
            <h2 class="text-sm font-semibold text-gray-500">{{ t('requests.sections.queued') }}（{{ queued.length }}）</h2>
            <span v-if="queued.length > 1" class="text-xs text-gray-400">{{ t('requests.reorderHint') }}</span>
          </div>
          <div v-if="queued.length === 0" class="text-sm text-gray-400 bg-white rounded-xl border border-dashed border-mist px-4 py-3">{{ t('requests.queueEmpty') }}</div>
          <ol class="space-y-2">
            <li v-for="(r, i) in queued" :key="r.id"
              draggable="true" @dragstart="dragFrom = i" @dragend="dragFrom = null" @dragover.prevent @drop.prevent="dropOn(i)"
              :class="['flex items-stretch gap-2', dragFrom === i ? 'opacity-50' : '']">
              <div class="w-8 shrink-0 flex items-center justify-center text-sm font-semibold text-gray-400 cursor-grab select-none" aria-hidden="true">{{ i + 1 }}</div>
              <div class="flex-1 min-w-0"><RequestRow :r="r" :rank="i" /></div>
              <div class="flex flex-col justify-center gap-0.5 shrink-0">
                <button @click="move(i, 0)" :disabled="i === 0" :title="t('requests.moveTop')" class="text-xs text-gray-400 hover:text-indigo-600 disabled:opacity-20 cursor-pointer px-1">⤒</button>
                <button @click="move(i, i - 1)" :disabled="i === 0" :title="t('requests.moveUp')" class="text-xs text-gray-400 hover:text-indigo-600 disabled:opacity-20 cursor-pointer px-1">▲</button>
                <button @click="move(i, i + 1)" :disabled="i === queued.length - 1" :title="t('requests.moveDown')" class="text-xs text-gray-400 hover:text-indigo-600 disabled:opacity-20 cursor-pointer px-1">▼</button>
              </div>
            </li>
          </ol>
        </section>

        <section v-if="byStatus.on_hold.length" class="mb-6">
          <h2 class="text-sm font-semibold text-gray-500 mb-2">{{ t('requests.sections.onHold') }}</h2>
          <div class="space-y-2"><RequestRow v-for="r in byStatus.on_hold" :key="r.id" :r="r" /></div>
        </section>

        <section v-if="closed.length">
          <button @click="showClosed = !showClosed" class="text-sm text-indigo-600 hover:underline cursor-pointer mb-2">
            {{ showClosed ? t('requests.hideClosed') : t('requests.showClosed', { n: closed.length }) }}
          </button>
          <div v-if="showClosed" class="space-y-2"><RequestRow v-for="r in closed" :key="r.id" :r="r" /></div>
        </section>
      </template>
    </template>

    <RequestFormModal v-if="showForm" :companies="companyOptions" :company-id="companyId" :project-id="prefillProject"
      @close="showForm = false" @saved="onSaved" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, h } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '../api'
import { useAuthStore } from '../stores/auth'
import RequestBadge from '../components/RequestBadge.vue'
import RequestFormModal from '../components/RequestFormModal.vue'
import { formatDate, formatDateTime, hours, turnaroundText } from '../services/requests'

const { t } = useI18n()
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const queues = ref([])
const companyId = ref(null)
const requests = ref([])
const loaded = ref(false)
const loadError = ref('')
const actionError = ref('')
const busy = ref(false)
const showForm = ref(false)
const showClosed = ref(false)
const showSettings = ref(false)
const settingsSaved = ref(false)
const settings = ref({ active_limit: 1, turnaround_hours: 72, monthly_hours: '' })
const dragFrom = ref(null)
const prefillProject = ref(null)

const queue = computed(() => queues.value.find((q) => q.company_id === companyId.value) || null)
const companyOptions = computed(() => queues.value.map((q) => ({ id: q.company_id, name: q.company_name })))

const byStatus = computed(() => {
  const g = { queued: [], in_progress: [], client_review: [], revision: [], on_hold: [], done: [], cancelled: [] }
  for (const r of requests.value) (g[r.status] || (g[r.status] = [])).push(r)
  g.queued.sort((a, b) => a.position - b.position || a.id - b.id)
  return g
})
const active = computed(() => [...byStatus.value.revision, ...byStatus.value.in_progress])
const queued = computed(() => byStatus.value.queued)
const closed = computed(() => [...byStatus.value.done, ...byStatus.value.cancelled]
  .sort((a, b) => new Date(b.completed_at || b.updated_at) - new Date(a.completed_at || a.updated_at)))

const canStartNext = computed(() => queue.value && !queue.value.paused_at && queue.value.open_slots > 0 && queued.value.length > 0)
const startNextBlocker = computed(() => {
  if (!queue.value) return ''
  if (queue.value.paused_at) return t('requests.pausedCannotStart')
  if (queue.value.open_slots < 1) return t('requests.limitReached')
  if (queued.value.length === 0) return t('requests.nothingQueued')
  return ''
})

/*
 * 1行分。ここだけで使うので同じファイルに置く（render 関数で書いて SFC を増やさない）。
 */
const RequestRow = (props) => {
  const r = props.r
  const meta = []
  if (r.project) meta.push(r.project.title)
  if (r.status === 'queued' && props.rank === 0) meta.push(t('requests.nextUp'))
  if (r.due_at && ['in_progress', 'revision'].includes(r.status)) meta.push(t('requests.dueAt', { date: formatDateTime(r.due_at) }))
  if (r.requested_due_on && r.status === 'queued') meta.push(t('requests.requestedDueOn', { date: formatDate(r.requested_due_on) }))
  if (r.revision_count) meta.push(t('requests.revisions', { n: r.revision_count }))
  if (r.assignee && auth.isDeveloper) meta.push(t('requests.assignedTo', { name: r.assignee.name }))

  return h(RouterLink, {
    to: `/requests/${r.id}`,
    class: 'block bg-white rounded-xl shadow-sm border border-transparent hover:border-gold hover:shadow transition px-4 py-3',
  }, () => [
    h('div', { class: 'flex flex-wrap items-center gap-2' }, [
      h('span', { class: 'text-xs text-gray-400 tabular-nums' }, `#${r.id}`),
      h('span', { class: 'font-medium text-ink truncate max-w-full' }, r.title),
      h(RequestBadge, { kind: 'category', value: r.category }),
      r.priority !== 'normal' ? h(RequestBadge, { kind: 'priority', value: r.priority }) : null,
      r.status !== 'queued' ? h(RequestBadge, { kind: 'status', value: r.status }) : null,
      r.is_overdue ? h('span', { class: 'text-xs font-medium text-red-600' }, t('requests.overdueTag')) : null,
      r.comments_count ? h('span', { class: 'ml-auto text-xs text-gray-400' }, `💬 ${r.comments_count}`) : null,
    ]),
    meta.length ? h('div', { class: 'text-xs text-gray-400 mt-1' }, meta.join(' · ')) : null,
  ])
}
RequestRow.props = ['r', 'rank']

async function loadQueues() {
  const res = await api.get('/request-queues/mine')
  queues.value = res.data?.data || []
}

async function loadRequests() {
  if (!companyId.value) {
    requests.value = []
    return
  }
  const res = await api.get('/requests', { params: { company_id: companyId.value } })
  requests.value = res.data?.data || []
}

function syncSettings() {
  if (!queue.value) return
  settings.value = {
    active_limit: queue.value.active_limit,
    turnaround_hours: queue.value.turnaround_hours,
    monthly_hours: queue.value.monthly_hours ?? '',
  }
}

async function refresh() {
  try {
    await Promise.all([loadQueues(), loadRequests()])
    loadError.value = ''
  } catch {
    loadError.value = t('requests.loadFailed')
  }
}

function replaceQueue(summary) {
  queues.value = queues.value.map((q) => (q.company_id === summary.company_id ? summary : q))
}

function selectCompany(id) {
  companyId.value = id
  router.replace({ query: { ...route.query, company: id } })
  syncSettings()
  loadRequests().catch(() => { loadError.value = t('requests.loadFailed') })
}

function openNew(projectId = null) {
  prefillProject.value = projectId
  showForm.value = true
}

async function onSaved(r) {
  showForm.value = false
  if (r.company_id !== companyId.value) selectCompany(r.company_id)
  await refresh()
}

// 並べ替え。画面を先に動かし、失敗したら読み直す。
async function reorderTo(list) {
  const ids = list.map((r) => r.id)
  const positions = new Map(ids.map((id, i) => [id, i + 1]))
  requests.value = requests.value.map((r) => (positions.has(r.id) ? { ...r, position: positions.get(r.id) } : r))
  try {
    await api.post('/requests/reorder', { company_id: companyId.value, ids })
    actionError.value = ''
  } catch {
    actionError.value = t('requests.reorderFailed')
    await loadRequests()
  }
}

function move(from, to) {
  if (to < 0 || to >= queued.value.length || from === to) return
  const list = [...queued.value]
  const [item] = list.splice(from, 1)
  list.splice(to, 0, item)
  reorderTo(list)
}

function dropOn(i) {
  if (dragFrom.value !== null) move(dragFrom.value, i)
  dragFrom.value = null
}

async function run(fn) {
  busy.value = true
  actionError.value = ''
  try {
    await fn()
  } catch (e) {
    actionError.value = e.response?.data?.message || t('requests.actionFailed')
  } finally {
    busy.value = false
  }
}

const startNext = () => run(async () => {
  const res = await api.post(`/request-queues/${companyId.value}/next`)
  router.push(`/requests/${res.data.data.id}`)
})

const pause = () => run(async () => {
  const reason = window.prompt(t('requests.pausePrompt') + '\n\n' + t('requests.pauseHint'), '')
  if (reason === null) return
  const res = await api.post(`/request-queues/${companyId.value}/pause`, { reason: reason || null })
  replaceQueue(res.data.data)
})

const resume = () => run(async () => {
  const res = await api.post(`/request-queues/${companyId.value}/resume`)
  replaceQueue(res.data.data)
})

const saveSettings = () => run(async () => {
  const res = await api.put(`/request-queues/${companyId.value}`, {
    ...settings.value,
    monthly_hours: settings.value.monthly_hours === '' ? null : Number(settings.value.monthly_hours),
  })
  replaceQueue(res.data.data)
  settingsSaved.value = true
  setTimeout(() => { settingsSaved.value = false }, 2000)
})

onMounted(async () => {
  try {
    await loadQueues()
    const wanted = Number(route.query.company)
    const fallback = queues.value.find((q) => q.active || q.counts.queued || q.counts.client_review) || queues.value[0]
    companyId.value = queues.value.some((q) => q.company_id === wanted) ? wanted : fallback?.company_id ?? null
    syncSettings()
    await loadRequests()
    // 案件ページの「この案件で依頼する」から来たとき（?new=1&project=ID）。
    if (route.query.new) openNew(Number(route.query.project) || null)
  } catch {
    loadError.value = t('requests.loadFailed')
  } finally {
    loaded.value = true
  }
})
</script>
