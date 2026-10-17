<template>
  <div class="max-w-6xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-800 mb-6">{{ t("dashboard.title") }}</h1>

    <div class="grid grid-cols-3 gap-4 mb-8">
      <div class="bg-white rounded-xl shadow p-5">
        <div class="text-3xl font-bold text-yellow-500">{{ pendingTasks.length }}</div>
        <div class="text-sm text-gray-500 mt-1">{{ t("dashboard.pendingApprovals") }}</div>
      </div>
      <div class="bg-white rounded-xl shadow p-5">
        <div class="text-3xl font-bold text-blue-500">{{ unreadMessages }}</div>
        <div class="text-sm text-gray-500 mt-1">{{ t("dashboard.unreadMessages") }}</div>
      </div>
      <div class="bg-white rounded-xl shadow p-5">
        <div class="text-3xl font-bold text-red-500">{{ unpaidInvoices }}</div>
        <div class="text-sm text-gray-500 mt-1">{{ t("dashboard.unpaidInvoices") }}</div>
      </div>
    </div>

    <!-- 依頼キュー：顧客ごとの作業中・順番待ち・確認待ち -->
    <div class="bg-white rounded-xl shadow p-6 mb-6">
      <h2 class="text-lg font-semibold text-gray-700">{{ t("dashboard.queueTitle") }}</h2>
      <p class="text-xs text-gray-400 mt-1 mb-4">{{ t("dashboard.queueLead") }}</p>
      <div v-if="queues.length === 0" class="text-gray-400 text-sm">{{ t("dashboard.noQueues") }}</div>
      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs text-gray-400 border-b">
              <th class="py-2 pr-3 font-medium">{{ t("dashboard.colCompany") }}</th>
              <th class="py-2 px-2 font-medium text-right">{{ t("dashboard.colActive") }}</th>
              <th class="py-2 px-2 font-medium text-right">{{ t("dashboard.colQueued") }}</th>
              <th class="py-2 px-2 font-medium text-right">{{ t("dashboard.colReview") }}</th>
              <th class="py-2 px-2 font-medium text-right">{{ t("dashboard.colOverdue") }}</th>
              <th class="py-2 px-2 font-medium text-right">{{ t("dashboard.colMonth") }}</th>
              <th class="py-2 pl-2"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="q in queues" :key="q.company_id" class="border-b last:border-0">
              <td class="py-2.5 pr-3">
                <router-link :to="`/requests?company=${q.company_id}`" class="font-medium text-gray-800 hover:text-indigo-600">{{ q.company_name }}</router-link>
                <span v-if="q.paused_at" class="ml-2 text-xs px-2 py-0.5 rounded-full bg-purple-100 text-purple-800">{{ t("requests.paused") }}</span>
              </td>
              <td class="py-2.5 px-2 text-right tabular-nums">{{ q.active }} / {{ q.active_limit }}</td>
              <td class="py-2.5 px-2 text-right tabular-nums">{{ q.counts.queued }}</td>
              <td class="py-2.5 px-2 text-right tabular-nums">{{ q.counts.client_review }}</td>
              <td class="py-2.5 px-2 text-right tabular-nums" :class="q.overdue ? 'text-red-600 font-semibold' : ''">{{ q.overdue }}</td>
              <td class="py-2.5 px-2 text-right tabular-nums text-gray-500">
                {{ hours(q.minutes_this_month) }}<template v-if="q.monthly_hours != null"> / {{ q.monthly_hours }}</template> h
              </td>
              <td class="py-2.5 pl-2 text-right whitespace-nowrap">
                <button v-if="q.open_slots > 0 && q.counts.queued > 0" @click="startNext(q)"
                  class="text-xs bg-ink text-white px-2.5 py-1 rounded-lg hover:opacity-90 cursor-pointer">▶ {{ t("requests.startNext") }}</button>
                <router-link v-else :to="`/requests?company=${q.company_id}`" class="text-xs text-indigo-600 hover:underline">{{ t("dashboard.openQueue") }}</router-link>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow p-6 mb-6">
      <h2 class="text-lg font-semibold text-gray-700 mb-4">{{ t("dashboard.pendingApprovals") }}</h2>
      <div v-if="pendingTasks.length === 0" class="text-gray-400 text-sm">{{ t("dashboard.noPendingTasks") }}</div>
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
      <h2 class="text-lg font-semibold text-gray-700 mb-4">{{ t("dashboard.recentActivity") }}</h2>
      <div v-if="notifications.length === 0" class="text-gray-400 text-sm">{{ t("dashboard.noActivity") }}</div>
      <div v-for="n in notifications" :key="n.id" class="py-3 border-b last:border-0">
        <div class="text-sm text-gray-800">{{ n.body }}</div>
        <div class="text-xs text-gray-400 mt-1">{{ n.created_at }}</div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from "vue"
import { useRouter } from "vue-router"
import { useI18n } from "vue-i18n"
import api from "../api"
import StatusBadge from "../components/StatusBadge.vue"
import { hours } from "../services/requests"

const { t } = useI18n()
const pendingTasks = ref([])
const unreadMessages = ref(0)
const unpaidInvoices = ref(0)
const notifications = ref([])
const queues = ref([])
const router = useRouter()

// 空き枠がある会社で、順番待ちの先頭に着手してその依頼を開く。
async function startNext(q) {
  try {
    const res = await api.post(`/request-queues/${q.company_id}/next`)
    router.push(`/requests/${res.data.data.id}`)
  } catch {
    router.push(`/requests?company=${q.company_id}`)
  }
}

onMounted(async () => {
  api.get("/request-queues").then((res) => { queues.value = res.data?.data || [] }).catch(() => {})
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
