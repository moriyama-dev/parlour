<template>
  <div class="max-w-6xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-800 mb-6">Dashboard</h1>

    <div class="grid grid-cols-3 gap-4 mb-8">
      <div class="bg-white rounded-xl shadow p-5">
        <div class="text-3xl font-bold text-yellow-500">{{ pendingTasks.length }}</div>
        <div class="text-sm text-gray-500 mt-1">Pending Approvals</div>
      </div>
      <div class="bg-white rounded-xl shadow p-5">
        <div class="text-3xl font-bold text-blue-500">{{ unreadMessages }}</div>
        <div class="text-sm text-gray-500 mt-1">Unread Messages</div>
      </div>
      <div class="bg-white rounded-xl shadow p-5">
        <div class="text-3xl font-bold text-red-500">{{ unpaidInvoices }}</div>
        <div class="text-sm text-gray-500 mt-1">Unpaid Invoices</div>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow p-6 mb-6">
      <h2 class="text-lg font-semibold text-gray-700 mb-4">Pending Approvals</h2>
      <div v-if="pendingTasks.length === 0" class="text-gray-400 text-sm">No pending tasks.</div>
      <div v-for="task in pendingTasks" :key="task.id" class="flex items-center justify-between py-3 border-b last:border-0">
        <div>
          <div class="font-medium text-gray-800">{{ task.title }}</div>
          <div class="text-sm text-gray-500">{{ task.projectName }}</div>
        </div>
        <div class="flex items-center gap-3">
          <StatusBadge :status="task.type" />
          <span class="text-sm text-gray-400">{{ task.due_date }}</span>
        </div>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow p-6">
      <h2 class="text-lg font-semibold text-gray-700 mb-4">Recent Activity</h2>
      <div v-if="notifications.length === 0" class="text-gray-400 text-sm">No recent activity.</div>
      <div v-for="n in notifications" :key="n.id" class="py-3 border-b last:border-0">
        <div class="text-sm text-gray-800">{{ n.body }}</div>
        <div class="text-xs text-gray-400 mt-1">{{ n.created_at }}</div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from "vue"
import api from "../api"
import StatusBadge from "../components/StatusBadge.vue"

const pendingTasks = ref([])
const unreadMessages = ref(0)
const unpaidInvoices = ref(0)
const notifications = ref([])

onMounted(async () => {
  try {
    const [projRes, notifRes] = await Promise.all([
      api.get("/projects"),
      api.get("/notifications").catch(() => ({ data: [] })),
    ])
    const projects = projRes.data?.data || projRes.data || []
    notifications.value = (notifRes.data?.data || notifRes.data || []).slice(0, 5)

    for (const p of projects) {
      try {
        const tasksRes = await api.get(`/projects/${p.id}/tasks`)
        const tasks = tasksRes.data?.data || tasksRes.data || []
        tasks.filter(t => t.status === "pending_review").forEach(t => {
          pendingTasks.value.push({ ...t, projectName: p.name })
        })
        const invRes = await api.get(`/projects/${p.id}/invoices`).catch(() => ({ data: [] }))
        const invs = invRes.data?.data || invRes.data || []
        unpaidInvoices.value += invs.filter(i => i.status !== "paid").length
        const msgRes = await api.get(`/projects/${p.id}/messages`).catch(() => ({ data: [] }))
        const msgs = msgRes.data?.data || msgRes.data || []
        unreadMessages.value += msgs.filter(m => !m.read_at).length
      } catch {}
    }
  } catch (e) {
    console.error(e)
  }
})
</script>
