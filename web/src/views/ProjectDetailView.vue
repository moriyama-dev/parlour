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
            class="text-sm text-indigo-600 hover:underline">Staging</a>
          <a v-if="project.production_url" :href="project.production_url" target="_blank"
            class="text-sm text-indigo-600 hover:underline">Production</a>
          <StatusBadge :status="project.status" />
        </div>
      </div>
    </div>

    <div class="flex gap-4 border-b mb-6">
      <button v-for="tab in tabs" :key="tab" @click="activeTab = tab" :class="tabClass(tab)">{{ tab }}</button>
    </div>

    <!-- Tasks -->
    <div v-if="activeTab === 'Tasks'">
      <div class="flex justify-between items-center mb-4">
        <h2 class="font-semibold text-gray-700">Tasks</h2>
        <button v-if="auth.isDeveloper" @click="$router.push(`/projects/${id}/tasks/create`)"
          class="bg-indigo-600 text-white px-3 py-1.5 rounded-lg text-sm hover:bg-indigo-700">New Task</button>
      </div>
      <div v-if="tasks.length === 0" class="text-gray-400 text-sm py-8 text-center">No tasks yet.</div>
      <div class="space-y-2">
        <TaskCard v-for="task in tasks" :key="task.id" :task="task" />
      </div>
    </div>

    <!-- Messages (threaded) -->
    <div v-if="activeTab === 'Messages'" class="flex flex-col">
      <div class="space-y-4 mb-6">
        <div v-if="messages.length === 0" class="text-gray-400 text-sm py-8 text-center">
          No messages yet. Start the conversation below.
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
            <button @click="toggleReply(msg.id)" class="text-xs text-indigo-500 hover:text-indigo-700">Reply</button>
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
                <button @click="toggleReply(msg.id)" class="text-xs text-indigo-400 hover:text-indigo-600">Reply</button>
              </div>
            </div>
          </div>

          <!-- Reply input -->
          <div v-if="replyingTo === msg.id" class="pl-10 mt-3 flex gap-2">
            <input v-model="replyBody" :placeholder="`Reply to ${msg.user?.name}...`" @keyup.enter="sendReply(msg.id)"
              class="flex-1 border rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
            <button @click="sendReply(msg.id)"
              class="bg-indigo-600 text-white px-3 py-1.5 rounded-lg text-sm hover:bg-indigo-700">Reply</button>
            <button @click="cancelReply" class="px-3 py-1.5 border rounded-lg text-sm text-gray-500">Cancel</button>
          </div>
        </div>
      </div>

      <form @submit.prevent="sendMessage" class="flex gap-2 sticky bottom-4">
        <input v-model="newMessage" placeholder="Type a message..."
          class="flex-1 border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
        <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700">Send</button>
      </form>
    </div>

    <!-- Invoices -->
    <div v-if="activeTab === 'Invoices'">
      <div class="flex justify-between items-center mb-4">
        <h2 class="font-semibold text-gray-700">Invoices</h2>
        <button v-if="auth.isDeveloper" @click="$router.push(`/projects/${id}/invoices/create`)"
          class="bg-indigo-600 text-white px-3 py-1.5 rounded-lg text-sm hover:bg-indigo-700">New Invoice</button>
      </div>
      <div v-if="invoices.length === 0" class="text-gray-400 text-sm py-8 text-center">No invoices yet.</div>
      <div class="space-y-2">
        <div v-for="inv in invoices" :key="inv.id"
          class="bg-white rounded-xl shadow px-5 py-4 flex justify-between items-center">
          <div>
            <div class="font-medium">{{ inv.invoice_number || ('Invoice #' + inv.id) }}</div>
            <div class="text-sm text-gray-400">Due: {{ formatDate(inv.due_at) }}</div>
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
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import api from '../api'
import { useAuthStore } from '../stores/auth'
import StatusBadge from '../components/StatusBadge.vue'
import TaskCard from '../components/TaskCard.vue'

const route = useRoute()
const id = route.params.id
const auth = useAuthStore()

const project = ref(null)
const tasks = ref([])
const messages = ref([])
const invoices = ref([])
const activeTab = ref('Tasks')
const tabs = ['Tasks', 'Messages', 'Invoices']
const newMessage = ref('')
const replyingTo = ref(null)
const replyBody = ref('')

function tabClass(tab) {
  return tab === activeTab.value
    ? 'pb-2 px-1 font-medium border-b-2 border-indigo-600 text-indigo-600'
    : 'pb-2 px-1 font-medium text-gray-500 hover:text-gray-700'
}

function formatMoney(amount) {
  return '$' + Number(amount || 0).toLocaleString('en-CA', { minimumFractionDigits: 2 })
}

function formatDate(d) {
  if (!d) return ''
  return new Date(d).toLocaleString('en-CA', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

function initials(name) {
  if (!name) return '?'
  return name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase()
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
