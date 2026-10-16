<template>
  <div class="max-w-6xl mx-auto px-4 py-8">
    <div v-if="project" class="mb-6">
      <div class="flex justify-between items-start">
        <div>
          <h1 class="text-2xl font-bold text-gray-800">{{ project.title || project.name }}</h1>
          <div class="text-gray-500 mt-1">{{ project.company?.name }}</div>
        </div>
        <div class="flex items-center gap-3">
          <a v-if="project.staging_url" :href="project.staging_url" target="_blank"
            class="text-sm text-indigo-600 hover:underline">{{ t("projectDetail.staging") }}</a>
          <a v-if="project.production_url" :href="project.production_url" target="_blank"
            class="text-sm text-indigo-600 hover:underline">{{ t("projectDetail.production") }}</a>
          <StatusBadge :status="project.status" />
        </div>
      </div>
    </div>

    <div class="flex gap-4 border-b mb-6">
      <button v-for="tab in tabs" :key="tab" @click="activeTab = tab" :class="tabClass(tab)">{{ t("projectDetail.tab" + tab) }}</button>
    </div>

    <!-- 概要：進捗の見える化 -->
    <div v-if="activeTab === 'Overview'" class="grid gap-6 lg:grid-cols-[1.4fr_1fr] items-start">
      <section class="bg-white rounded-2xl shadow p-6">
        <h2 class="font-serif text-lg font-semibold text-ink mb-4">{{ t("projectDetail.progressTitle") }}</h2>
        <TaskProgressChart :stats="project?.task_stats || {}" />
      </section>

      <section class="bg-white rounded-2xl shadow p-6">
        <h2 class="font-serif text-lg font-semibold text-ink mb-4">{{ t("projectDetail.summaryTitle") }}</h2>
        <p v-if="project?.description" class="text-sm text-gray-600 whitespace-pre-line mb-4">{{ project.description }}</p>
        <dl class="text-sm divide-y divide-mist">
          <div v-for="row in summaryRows" :key="row.key" class="flex justify-between gap-4 py-2">
            <dt class="text-gray-500">{{ t("projectDetail." + row.key) }}</dt>
            <dd class="text-ink font-medium tabular-nums">{{ row.value }}</dd>
          </div>
        </dl>
      </section>
    </div>

    <!-- イメージ：この案件にリンクされたホワイトボード -->
    <div v-if="activeTab === 'Boards'">
      <div class="flex flex-wrap justify-between items-center gap-2 mb-4">
        <h2 class="font-semibold text-gray-700">{{ t("projectDetail.tabBoards") }}</h2>
        <div class="flex gap-2">
          <button @click="openLinkBoard"
            class="border border-indigo-600 text-indigo-600 px-3 py-1.5 rounded-lg text-sm hover:bg-indigo-50 cursor-pointer">
            {{ t("projectDetail.linkBoard") }}
          </button>
          <button @click="createBoard" :disabled="creatingBoard"
            class="bg-indigo-600 text-white px-3 py-1.5 rounded-lg text-sm hover:bg-indigo-700 disabled:opacity-60 cursor-pointer">
            {{ t("projectDetail.newBoard") }}
          </button>
        </div>
      </div>
      <p class="text-sm text-gray-500 mb-4">{{ t("projectDetail.boardsLead") }}</p>
      <p v-if="boardError" class="text-sm text-red-500 mb-3">{{ boardError }}</p>

      <div v-if="boards.length === 0" class="text-gray-400 text-sm py-8 text-center">{{ t("projectDetail.noBoards") }}</div>
      <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="b in boards" :key="b.id" @click="$router.push(`/boards/${b.id}`)"
          class="group rounded-2xl border border-mist bg-white shadow-sm hover:shadow-md hover:border-gold transition cursor-pointer overflow-hidden">
          <div class="aspect-[16/10] bg-sand flex items-center justify-center overflow-hidden border-b border-mist">
            <img v-if="b.thumbnail" :src="thumbUrl(b.thumbnail)" alt="" class="w-full h-full object-contain p-3" />
            <span v-else class="text-3xl opacity-30" aria-hidden="true">🗒️</span>
          </div>
          <div class="px-4 py-3 flex items-start gap-2">
            <div class="min-w-0 flex-1">
              <div class="text-sm font-medium text-ink truncate">{{ b.title }}</div>
              <div class="text-xs text-gray-400 truncate">{{ b.owner?.name }}</div>
            </div>
            <button v-if="b.can_delete" @click.stop="unlinkBoard(b)" :title="t('projectDetail.unlinkBoard')"
              class="text-xs text-gray-400 hover:text-red-500 cursor-pointer px-1">✕</button>
          </div>
        </div>
      </div>

      <!-- 既存ボードのリンク -->
      <div v-if="showLinkBoard" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4" @click.self="showLinkBoard = false">
        <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-md">
          <h3 class="font-semibold mb-1">{{ t("projectDetail.linkBoard") }}</h3>
          <p class="text-xs text-gray-500 mb-4">{{ t("projectDetail.linkBoardHint") }}</p>
          <div v-if="linkableBoards.length === 0" class="text-sm text-gray-400 py-6 text-center">{{ t("projectDetail.noLinkableBoards") }}</div>
          <div v-else class="max-h-72 overflow-y-auto divide-y divide-mist border border-mist rounded-lg">
            <button v-for="b in linkableBoards" :key="b.id" @click="linkBoard(b)"
              class="w-full text-left px-3 py-2.5 hover:bg-sand cursor-pointer">
              <div class="text-sm text-ink truncate">{{ b.title }}</div>
              <div class="text-xs text-gray-400">{{ b.project ? t("projectDetail.boardLinkedTo", { project: b.project.title }) : t("projectDetail.boardPersonal") }}</div>
            </button>
          </div>
          <div class="flex justify-end mt-4">
            <button @click="showLinkBoard = false" class="px-4 py-2 border rounded-lg text-sm cursor-pointer">{{ t("common.close") }}</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Tasks -->
    <div v-if="activeTab === 'Tasks'">
      <div class="flex justify-between items-center mb-4">
        <h2 class="font-semibold text-gray-700">{{ t("projectDetail.tabTasks") }}</h2>
        <button v-if="auth.isDeveloper" @click="$router.push(`/projects/${id}/tasks/create`)"
          class="bg-indigo-600 text-white px-3 py-1.5 rounded-lg text-sm hover:bg-indigo-700">{{ t("projectDetail.newTask") }}</button>
      </div>
      <div v-if="tasks.length === 0" class="text-gray-400 text-sm py-8 text-center">{{ t("projectDetail.noTasks") }}</div>
      <div class="space-y-2">
        <TaskCard v-for="task in tasks" :key="task.id" :task="task" />
      </div>
    </div>

    <!-- Messages (threaded) -->
    <div v-if="activeTab === 'Messages'" class="flex flex-col">
      <div class="space-y-4 mb-6">
        <div v-if="messages.length === 0" class="text-gray-400 text-sm py-8 text-center">
          {{ t("messages.empty") }}
        </div>

        <div v-for="msg in messages" :key="msg.id" class="bg-white rounded-xl shadow px-4 py-3">
          <!-- Top-level comment -->
          <div class="flex justify-between items-start">
            <div class="flex items-center gap-2">
              <div class="w-8 h-8 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600 text-xs font-bold">
                {{ initials(msg.user?.name) }}
              </div>
              <span class="font-medium text-gray-800 text-sm">{{ msg.user?.name }}</span>
            </div>
            <span class="text-xs text-gray-400">{{ formatDate(msg.created_at) }}</span>
          </div>
          <div class="mt-2 text-gray-700 text-sm whitespace-pre-wrap pl-10">{{ msg.body }}</div>
          <div class="pl-10 mt-1">
            <button @click="toggleReply(msg.id)" class="text-xs text-indigo-500 hover:text-indigo-700">{{ t("messages.reply") }}</button>
          </div>

          <!-- Replies -->
          <div v-if="msg.replies && msg.replies.length" class="pl-10 mt-3 space-y-3 border-l-2 border-gray-100 ml-4">
            <div v-for="r in msg.replies" :key="r.id" class="pl-4">
              <div class="flex justify-between items-start">
                <div class="flex items-center gap-2">
                  <div class="w-6 h-6 rounded-full bg-gray-100 flex items-center justify-center text-gray-500 text-[10px] font-bold">
                    {{ initials(r.user?.name) }}
                  </div>
                  <span class="font-medium text-gray-700 text-xs">{{ r.user?.name }}</span>
                </div>
                <span class="text-xs text-gray-400">{{ formatDate(r.created_at) }}</span>
              </div>
              <div class="mt-1 text-gray-600 text-sm whitespace-pre-wrap pl-8">{{ r.body }}</div>
              <div class="pl-8 mt-1">
                <button @click="toggleReply(msg.id)" class="text-xs text-indigo-400 hover:text-indigo-600">{{ t("messages.reply") }}</button>
              </div>
            </div>
          </div>

          <!-- Reply input -->
          <div v-if="replyingTo === msg.id" class="pl-10 mt-3 flex gap-2">
            <input v-model="replyBody" :placeholder="t('messages.replyTo', { name: msg.user?.name })" @keyup.enter="sendReply(msg.id)"
              class="flex-1 border rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
            <button @click="sendReply(msg.id)"
              class="bg-indigo-600 text-white px-3 py-1.5 rounded-lg text-sm hover:bg-indigo-700">{{ t("messages.reply") }}</button>
            <button @click="cancelReply" class="px-3 py-1.5 border rounded-lg text-sm text-gray-500">{{ t("common.cancel") }}</button>
          </div>
        </div>
      </div>

      <form @submit.prevent="sendMessage" class="flex gap-2 sticky bottom-4">
        <input v-model="newMessage" :placeholder="t('messages.typePlaceholder')"
          class="flex-1 border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
        <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700">{{ t("messages.send") }}</button>
      </form>
    </div>

    <!-- Calls -->
    <div v-if="activeTab === 'Calls'">
      <CallsPanel :project-id="id" />
    </div>

    <!-- Invoices -->
    <div v-if="activeTab === 'Invoices'">
      <div class="flex justify-between items-center mb-4">
        <h2 class="font-semibold text-gray-700">{{ t("projectDetail.tabInvoices") }}</h2>
        <button v-if="auth.isDeveloper" @click="$router.push(`/projects/${id}/invoices/create`)"
          class="bg-indigo-600 text-white px-3 py-1.5 rounded-lg text-sm hover:bg-indigo-700">{{ t("projectDetail.newInvoice") }}</button>
      </div>
      <div v-if="invoices.length === 0" class="text-gray-400 text-sm py-8 text-center">{{ t("projectDetail.noInvoices") }}</div>
      <div class="space-y-2">
        <div v-for="inv in invoices" :key="inv.id"
          class="bg-white rounded-xl shadow px-5 py-4 flex justify-between items-center">
          <div>
            <div class="font-medium">{{ inv.invoice_number || t("projectDetail.invoiceFallback", { id: inv.id }) }}</div>
            <div class="text-sm text-gray-400">{{ t("common.due") }}: {{ formatDate(inv.due_at) }}</div>
          </div>
          <div class="flex items-center gap-3">
            <span class="font-semibold">{{ formatMoney(inv.total) }}</span>
            <StatusBadge :status="inv.status" />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '../api'
import { activeDateLocale } from '../i18n'
import { useAuthStore } from '../stores/auth'
import StatusBadge from '../components/StatusBadge.vue'
import TaskCard from '../components/TaskCard.vue'
import CallsPanel from '../components/CallsPanel.vue'
import TaskProgressChart from '../components/TaskProgressChart.vue'
import { formatMoney } from '../services/money'
import { thumbnailDataUrl } from '../services/board'

const { t } = useI18n()

const route = useRoute()
const router = useRouter()
const id = route.params.id
const auth = useAuthStore()

const project = ref(null)
const tasks = ref([])
const messages = ref([])
const invoices = ref([])
const activeTab = ref('Overview')
// 「請求書・イメージ・タスク・メッセージ」は案件ごとに揃える。通話も同じ部屋の道具。
const tabs = ['Overview', 'Tasks', 'Boards', 'Messages', 'Calls', 'Invoices']
const boards = ref([])
const allBoards = ref([])
const showLinkBoard = ref(false)
const creatingBoard = ref(false)
const boardError = ref('')
const newMessage = ref('')
const replyingTo = ref(null)
const replyBody = ref('')

function tabClass(tab) {
  return tab === activeTab.value
    ? 'pb-2 px-1 font-medium border-b-2 border-indigo-600 text-indigo-600'
    : 'pb-2 px-1 font-medium text-gray-500 hover:text-gray-700'
}

function formatDate(d) {
  if (!d) return ''
  return new Date(d).toLocaleString(activeDateLocale(), { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

function initials(name) {
  if (!name) return '?'
  return name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase()
}

const summaryRows = computed(() => [
  { key: 'summaryTasks', value: String(project.value?.task_stats?.total ?? tasks.value.length) },
  { key: 'summaryBoards', value: String(boards.value.length) },
  { key: 'summaryMessages', value: String(messages.value.length) },
  { key: 'summaryInvoices', value: String(invoices.value.length) },
  { key: 'summaryBilled', value: formatMoney(invoices.value.reduce((n, i) => n + Number(i.total || 0), 0)) },
])

// 自分がこの案件にリンクできるボード（まだこの案件のものではないもの）。
const linkableBoards = computed(() => allBoards.value.filter((b) => String(b.project_id || '') !== String(id)))

function thumbUrl(svg) {
  return thumbnailDataUrl(svg)
}

async function loadBoards() {
  try {
    const res = await api.get('/boards', { params: { project_id: id } })
    boards.value = res.data?.data || []
  } catch {
    boards.value = []
  }
}

async function openLinkBoard() {
  boardError.value = ''
  showLinkBoard.value = true
  try {
    const res = await api.get('/boards')
    allBoards.value = res.data?.data || []
  } catch {
    allBoards.value = []
  }
}

async function linkBoard(board) {
  try {
    await api.put(`/boards/${board.id}`, { project_id: Number(id) })
    showLinkBoard.value = false
    await loadBoards()
  } catch (e) {
    boardError.value = e.response?.status === 403 ? t('projectDetail.linkBoardForbidden') : t('projectDetail.linkBoardFailed')
    showLinkBoard.value = false
  }
}

async function unlinkBoard(board) {
  try {
    await api.put(`/boards/${board.id}`, { project_id: null })
    await loadBoards()
  } catch (e) {
    boardError.value = e.response?.status === 403 ? t('projectDetail.linkBoardForbidden') : t('projectDetail.linkBoardFailed')
  }
}

async function createBoard() {
  creatingBoard.value = true
  boardError.value = ''
  try {
    const res = await api.post('/boards', {
      title: project.value?.title ? t('projectDetail.boardNamed', { project: project.value.title }) : t('boards.untitled'),
      project_id: Number(id),
    })
    router.push(`/boards/${res.data.data.id}`)
  } catch {
    boardError.value = t('boards.createFailed')
    creatingBoard.value = false
  }
}

onMounted(async () => {
  const [pRes, tRes, mRes, iRes] = await Promise.all([
    api.get(`/projects/${id}`),
    api.get(`/projects/${id}/tasks`),
    api.get(`/projects/${id}/messages`),
    api.get(`/projects/${id}/invoices`).catch(() => ({ data: [] })),
  ])
  project.value = pRes.data?.data || pRes.data
  tasks.value = tRes.data?.data || tRes.data || []
  messages.value = mRes.data?.data || mRes.data || []
  invoices.value = iRes.data?.data || iRes.data || []
  await loadBoards()
})

async function reloadMessages() {
  const res = await api.get(`/projects/${id}/messages`)
  messages.value = res.data?.data || res.data || []
}

async function sendMessage() {
  if (!newMessage.value.trim()) return
  await api.post(`/projects/${id}/messages`, { body: newMessage.value })
  newMessage.value = ''
  await reloadMessages()
}

function toggleReply(msgId) {
  replyingTo.value = replyingTo.value === msgId ? null : msgId
  replyBody.value = ''
}

function cancelReply() {
  replyingTo.value = null
  replyBody.value = ''
}

async function sendReply(parentId) {
  if (!replyBody.value.trim()) return
  await api.post(`/projects/${id}/messages`, { body: replyBody.value, parent_id: parentId })
  replyBody.value = ''
  replyingTo.value = null
  await reloadMessages()
}
</script>
