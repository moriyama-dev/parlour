<template>
  <nav class="bg-white shadow-sm border-b px-6 py-3 flex justify-between items-center">
    <div class="flex items-center gap-8">
      <span class="text-xl font-bold text-indigo-600">Parlour</span>
      <div class="flex gap-5">
        <template v-if="auth.isDeveloper">
          <router-link to="/dashboard" active-class="text-indigo-600 font-medium"
            class="text-sm text-gray-600 hover:text-indigo-600 transition">Dashboard</router-link>
          <router-link to="/companies" active-class="text-indigo-600 font-medium"
            class="text-sm text-gray-600 hover:text-indigo-600 transition">Companies</router-link>
          <router-link to="/clients" active-class="text-indigo-600 font-medium"
            class="text-sm text-gray-600 hover:text-indigo-600 transition">Clients</router-link>
          <router-link to="/projects" active-class="text-indigo-600 font-medium"
            class="text-sm text-gray-600 hover:text-indigo-600 transition">Projects</router-link>
          <router-link to="/messages" active-class="text-indigo-600 font-medium"
            class="text-sm text-gray-600 hover:text-indigo-600 transition">Messages</router-link>
        </template>
        <template v-if="auth.isClient">
          <router-link to="/client/dashboard" active-class="text-indigo-600 font-medium"
            class="text-sm text-gray-600 hover:text-indigo-600 transition">Dashboard</router-link>
          <router-link to="/messages" active-class="text-indigo-600 font-medium"
            class="text-sm text-gray-600 hover:text-indigo-600 transition">Messages</router-link>
        </template>
      </div>
    </div>
    <div class="flex items-center gap-4">
      <!-- Notification Bell -->
      <div class="relative" ref="bellRef">
        <button @click="toggleNotifications" class="relative cursor-pointer focus:outline-none">
          <span class="text-lg">🔔</span>
          <span v-if="unreadCount > 0"
            class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full w-4 h-4 flex items-center justify-center leading-none">
            {{ unreadCount > 9 ? '9+' : unreadCount }}
          </span>
        </button>

        <!-- Dropdown -->
        <div v-if="showNotifications"
          class="absolute right-0 top-8 w-80 bg-white rounded-xl shadow-lg border z-50 overflow-hidden">
          <div class="flex justify-between items-center px-4 py-3 border-b">
            <span class="font-semibold text-sm text-gray-700">Notifications</span>
            <button v-if="unreadCount > 0" @click="markAllRead"
              class="text-xs text-indigo-500 hover:text-indigo-700">Mark all read</button>
          </div>
          <div class="max-h-80 overflow-y-auto">
            <div v-if="notifications.length === 0" class="px-4 py-6 text-center text-sm text-gray-400">
              No notifications
            </div>
            <div v-for="n in notifications" :key="n.id"
              @click="markRead(n)"
              class="px-4 py-3 border-b last:border-0 cursor-pointer hover:bg-gray-50 transition"
              :class="{ 'bg-indigo-50': !n.read_at }">
              <div class="text-sm text-gray-800">{{ notifMessage(n) }}</div>
              <div class="text-xs text-gray-400 mt-1">{{ formatDate(n.created_at) }}</div>
            </div>
          </div>
        </div>
      </div>

      <span class="text-sm text-gray-700 font-medium">{{ auth.user?.name }}</span>
      <button @click="logout" class="text-sm text-gray-400 hover:text-red-500 transition">Logout</button>
    </div>
  </nav>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../api'

const auth = useAuthStore()
const router = useRouter()
const unreadCount = ref(0)
const notifications = ref([])
const showNotifications = ref(false)
const bellRef = ref(null)

async function loadNotifications() {
  if (!auth.isAuthenticated) return
  try {
    const res = await api.get('/notifications')
    const notifs = res.data?.data || res.data || []
    notifications.value = notifs
    unreadCount.value = notifs.filter(n => !n.read_at).length
  } catch {}
}

function toggleNotifications() {
  showNotifications.value = !showNotifications.value
}

async function markRead(n) {
  if (!n.read_at) {
    try {
      await api.patch(`/notifications/${n.id}/read`)
      n.read_at = new Date().toISOString()
      unreadCount.value = notifications.value.filter(x => !x.read_at).length
    } catch {}
  }
  const path = notifPath(n)
  if (path) {
    showNotifications.value = false
    router.push(path)
  }
}

function notifPath(n) {
  const d = n.data || {}
  if (n.type === 'task_pending_review') {
    return auth.isClient
      ? '/client/tasks/' + d.task_id
      : '/projects/' + d.project_id
  }
  if (n.type === 'task_approved' || n.type === 'task_rejected') {
    return '/projects/' + d.project_id
  }
  if (n.type === 'invoice_sent') {
    return auth.isClient
      ? '/client/invoices/' + d.invoice_id
      : '/projects/' + d.project_id
  }
  return null
}

async function markAllRead() {
  try {
    await api.post('/notifications/read-all')
    notifications.value.forEach(n => { if (!n.read_at) n.read_at = new Date().toISOString() })
    unreadCount.value = 0
  } catch {}
}

function notifMessage(n) {
  const d = n.data || {}
  if (n.type === 'task_pending_review') return 'Task "' + d.task_title + '" is ready for your review.'
  if (n.type === 'task_approved') return 'Task "' + d.task_title + '" has been approved.'
  if (n.type === 'task_rejected') return 'Task "' + d.task_title + '" was rejected' + (d.comment ? ': ' + d.comment : '') + '.'
  if (n.type === 'invoice_sent') return 'Invoice #' + d.invoice_number + ' has been sent to you.'
  return d.message || d.body || n.type
}

function formatDate(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleString()
}

function handleClickOutside(e) {
  if (bellRef.value && !bellRef.value.contains(e.target)) {
    showNotifications.value = false
  }
}

onMounted(async () => {
  await loadNotifications()
  document.addEventListener('click', handleClickOutside)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
})

async function logout() {
  await auth.logout()
  router.push('/login')
}
</script>
