<template>
  <nav ref="navRef"
    class="bg-white/85 backdrop-blur border-b border-mist px-4 sm:px-6 py-3 flex justify-between items-center gap-3 sm:gap-4 sticky top-0 z-40"
    style="box-shadow: 0 8px 20px rgba(16,35,58,0.05)">
    <div class="flex items-center gap-6 xl:gap-8 min-w-0">
      <router-link :to="auth.isClient ? '/client/dashboard' : '/dashboard'" class="flex items-center gap-2.5 shrink-0">
        <img src="/logo.png" alt="Takumi" class="h-8 w-8 object-contain shrink-0" />
        <span class="font-serif text-xl font-semibold tracking-tight text-ink leading-none whitespace-nowrap">Takumi<span class="text-gold hidden sm:inline"> Web Services</span></span>
      </router-link>
      <!-- Inline menu: only when there is room (≥ xl). Narrower widths use the hamburger. -->
      <div class="hidden xl:flex gap-5">
        <router-link v-for="l in navLinks" :key="l.to" :to="l.to" active-class="text-indigo-600 font-medium"
          class="text-sm text-gray-600 hover:text-indigo-600 transition whitespace-nowrap">{{ t(l.key) }}</router-link>
      </div>
    </div>
    <div class="flex items-center gap-3 sm:gap-4 shrink-0">
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
            <span class="font-semibold text-sm text-gray-700">{{ t("notif.title") }}</span>
            <button v-if="unreadCount > 0" @click="markAllRead"
              class="text-xs text-indigo-500 hover:text-indigo-700">{{ t("notif.markAllRead") }}</button>
          </div>
          <div class="max-h-80 overflow-y-auto">
            <div v-if="notifications.length === 0" class="px-4 py-6 text-center text-sm text-gray-400">
              {{ t("notif.empty") }}
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

      <div class="hidden sm:block"><LangSwitch /></div>
      <span class="hidden sm:block text-sm text-gray-700 font-medium truncate max-w-[8rem] lg:max-w-[12rem]">{{ auth.user?.name }}</span>
      <button @click="logout" class="text-sm text-gray-400 hover:text-red-500 transition whitespace-nowrap shrink-0 cursor-pointer">{{ t("nav.logout") }}</button>

      <!-- Hamburger: collapses the menu below xl so the bar never overflows -->
      <button v-if="navLinks.length" @click="toggleMenu"
        class="xl:hidden text-2xl leading-none text-gray-600 hover:text-indigo-600 transition shrink-0 cursor-pointer focus:outline-none"
        :aria-expanded="showMenu" aria-label="Menu">☰</button>
    </div>

    <!-- Collapsed menu panel (below xl) -->
    <div v-if="showMenu"
      class="xl:hidden absolute top-full left-0 right-0 bg-white border-b border-mist flex flex-col px-4 sm:px-6 py-2"
      style="box-shadow: 0 8px 20px rgba(16,35,58,0.05)">
      <router-link v-for="l in navLinks" :key="l.to" :to="l.to" @click="showMenu = false"
        active-class="text-indigo-600 font-medium"
        class="text-sm text-gray-600 hover:text-indigo-600 transition py-2">{{ t(l.key) }}</router-link>
      <!-- Language switch for very narrow widths where it is hidden from the bar -->
      <div class="sm:hidden pt-2 mt-1 border-t border-mist"><LangSwitch /></div>
    </div>
  </nav>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../stores/auth'
import { activeDateLocale } from '../i18n'
import LangSwitch from './LangSwitch.vue'
import api from '../api'

const { t } = useI18n()
const auth = useAuthStore()
const router = useRouter()
const unreadCount = ref(0)
const notifications = ref([])
const showNotifications = ref(false)
const bellRef = ref(null)
const navRef = ref(null)
const showMenu = ref(false)

// メニュー項目はロールごとに1か所で定義し、横並びと折りたたみの両方で使う。
const navLinks = computed(() => {
  if (auth.isDeveloper) return [
    { to: '/dashboard', key: 'nav.dashboard' },
    { to: '/companies', key: 'nav.companies' },
    { to: '/clients', key: 'nav.clients' },
    { to: '/projects', key: 'nav.projects' },
    { to: '/messages', key: 'nav.messages' },
  ]
  if (auth.isClient) return [
    { to: '/client/dashboard', key: 'nav.dashboard' },
    { to: '/messages', key: 'nav.messages' },
  ]
  return []
})

function toggleMenu() {
  showMenu.value = !showMenu.value
}

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
  if (n.type === 'task_pending_review') return t('notif.taskPendingReview', { title: d.task_title })
  if (n.type === 'task_approved') return t('notif.taskApproved', { title: d.task_title })
  if (n.type === 'task_rejected') {
    return d.comment
      ? t('notif.taskRejectedComment', { title: d.task_title, comment: d.comment })
      : t('notif.taskRejected', { title: d.task_title })
  }
  if (n.type === 'invoice_sent') return t('notif.invoiceSent', { number: d.invoice_number })
  return d.message || d.body || n.type
}

function formatDate(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleString(activeDateLocale())
}

function handleClickOutside(e) {
  if (bellRef.value && !bellRef.value.contains(e.target)) {
    showNotifications.value = false
  }
  if (navRef.value && !navRef.value.contains(e.target)) {
    showMenu.value = false
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
