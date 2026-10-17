<template>
  <div class="max-w-4xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-800 mb-6">{{ t("clientDashboard.title") }}</h1>

    <!-- ご依頼（依頼キュー）。確認待ちがあれば一番目立つ場所に -->
    <div class="bg-white rounded-xl shadow p-6 mb-6">
      <div class="flex flex-wrap justify-between items-start gap-3">
        <div>
          <h2 class="text-lg font-semibold text-gray-700">{{ t("clientDashboard.requestsTitle") }}</h2>
          <p class="text-xs text-gray-400 mt-1">{{ t("clientDashboard.requestsLead") }}</p>
        </div>
        <div class="flex gap-2">
          <router-link to="/requests" class="border border-indigo-600 text-indigo-600 px-3 py-1.5 rounded-lg text-sm hover:bg-indigo-50">{{ t("clientDashboard.viewRequests") }}</router-link>
          <router-link to="/requests?new=1" class="bg-indigo-600 text-white px-3 py-1.5 rounded-lg text-sm hover:bg-indigo-700">+ {{ t("clientDashboard.newRequest") }}</router-link>
        </div>
      </div>
      <div v-for="q in queues" :key="q.company_id" class="mt-3 text-sm text-gray-600">
        <span v-if="queues.length > 1" class="font-medium text-gray-700 mr-2">{{ q.company_name }}</span>
        {{ t("clientDashboard.inQueue", { active: q.active, queued: q.counts.queued }) }}
        <span v-if="q.paused_at" class="ml-2 text-xs px-2 py-0.5 rounded-full bg-purple-100 text-purple-800">{{ t("requests.paused") }}</span>
      </div>
      <div v-if="toReview.length" class="mt-4 bg-yellow-50 border border-yellow-200 rounded-lg p-3">
        <div class="text-sm font-medium text-yellow-800 mb-2">{{ t("clientDashboard.yourReview", { n: toReview.length }) }}</div>
        <router-link v-for="r in toReview" :key="r.id" :to="`/requests/${r.id}`"
          class="flex justify-between items-center bg-white rounded-lg px-3 py-2 mb-1 last:mb-0 text-sm hover:shadow">
          <span class="text-gray-800 truncate">{{ r.title }}</span>
          <span class="text-yellow-700 shrink-0 ml-2">{{ t("clientDashboard.reviewNow") }} →</span>
        </router-link>
      </div>
    </div>

    <!-- My Projects -->
    <div class="bg-white rounded-xl shadow p-6 mb-6">
      <h2 class="text-lg font-semibold text-gray-700 mb-4">{{ t("clientDashboard.myProjects") }}</h2>
      <div v-if="projects.length === 0" class="text-gray-400 text-sm">{{ t("clientDashboard.noProjects") }}</div>
      <div v-for="p in projects" :key="p.id"
        class="flex justify-between items-center py-3 border-b last:border-0">
        <div>
          <div class="font-medium text-gray-800">{{ p.title }}</div>
          <div class="text-sm text-gray-500">{{ p.company?.name }}</div>
        </div>
        <span class="text-xs px-2 py-1 rounded-full font-medium"
          :class="statusClass(p.status)">{{ badgeLabel(p.status) }}</span>
      </div>
    </div>

    <!-- Pending Review -->
    <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6 mb-6">
      <h2 class="text-lg font-semibold text-yellow-800 mb-4">{{ t("clientDashboard.pendingYourReview") }}</h2>
      <div v-if="pendingTasks.length === 0" class="text-yellow-600 text-sm">{{ t("clientDashboard.noPendingReview") }}</div>
      <div v-for="task in pendingTasks" :key="task.id"
        class="bg-white rounded-lg shadow px-4 py-3 mb-2 flex justify-between items-center">
        <div>
          <div class="font-medium text-gray-800">{{ task.title }}</div>
          <div class="text-sm text-gray-500">{{ task.projectName }}</div>
        </div>
        <button @click="reviewTask(task.id)"
          class="bg-yellow-500 text-white px-3 py-1.5 rounded-lg text-sm hover:bg-yellow-600">{{ t("clientDashboard.reviewNow") }}</button>
      </div>
    </div>

    <!-- All Tasks -->
    <div class="bg-white rounded-xl shadow p-6 mb-6">
      <h2 class="text-lg font-semibold text-gray-700 mb-4">{{ t("clientDashboard.allTasks") }}</h2>
      <div v-if="allTasks.length === 0" class="text-gray-400 text-sm">{{ t("clientDashboard.noTasks") }}</div>
      <div v-for="task in allTasks" :key="task.id"
        class="flex justify-between items-center py-3 border-b last:border-0">
        <div>
          <div class="font-medium text-gray-800">{{ task.title }}</div>
          <div class="text-sm text-gray-500">{{ task.projectName }}</div>
        </div>
        <span class="text-xs px-2 py-1 rounded-full font-medium"
          :class="taskStatusClass(task.status)">{{ badgeLabel(task.status) }}</span>
      </div>
    </div>

    <!-- Recent Messages -->
    <div class="bg-white rounded-xl shadow p-6">
      <h2 class="text-lg font-semibold text-gray-700 mb-4">{{ t("clientDashboard.recentMessages") }}</h2>
      <div v-if="recentMessages.length === 0" class="text-gray-400 text-sm">{{ t("clientDashboard.noMessages") }}</div>
      <div v-for="msg in recentMessages" :key="msg.id" class="py-3 border-b last:border-0">
        <div class="flex justify-between text-sm">
          <div>
            <span class="font-medium">{{ msg.user?.name }}</span>
            <span class="text-gray-400 text-xs ml-2">{{ msg.projectName }}</span>
          </div>
          <span class="text-gray-400">{{ formatDate(msg.created_at) }}</span>
        </div>
        <div class="text-gray-700 text-sm mt-1">{{ msg.body }}</div>
      </div>
    </div>

    <!-- スマホにアプリとして追加 -->
    <InstallAppCard />
  </div>
</template>

<script setup>
import InstallAppCard from "../components/InstallAppCard.vue"
import { ref, onMounted } from "vue"
import { useRouter } from "vue-router"
import { useI18n } from "vue-i18n"
import api from "../api"
import { activeDateLocale } from "../i18n"

const { t, te } = useI18n()
const router = useRouter()

function reviewTask(id) {
  router.push(`/client/tasks/${id}`)
}
const projects = ref([])
const pendingTasks = ref([])
const allTasks = ref([])
const recentMessages = ref([])
const queues = ref([])
const toReview = ref([])

// 生ステータス（active / pending_review 等）を badge 辞書で両言語化。未定義は生値。
function badgeLabel(status) {
  const key = `badge.${status}`
  return te(key) ? t(key) : status
}

function statusClass(status) {
  const map = {
    active: 'bg-green-100 text-green-700',
    completed: 'bg-gray-100 text-gray-600',
    paused: 'bg-yellow-100 text-yellow-700',
  }
  return map[status] || 'bg-indigo-100 text-indigo-700'
}

function taskStatusClass(status) {
  const map = {
    pending_review: 'bg-yellow-100 text-yellow-700',
    approved: 'bg-green-100 text-green-700',
    rejected: 'bg-red-100 text-red-700',
    in_progress: 'bg-blue-100 text-blue-700',
    deployed: 'bg-purple-100 text-purple-700',
  }
  return map[status] || 'bg-gray-100 text-gray-600'
}

function formatDate(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleString(activeDateLocale())
}

onMounted(async () => {
  api.get("/request-queues/mine").then((res) => { queues.value = res.data?.data || [] }).catch(() => {})
  api.get("/requests", { params: { status: "client_review" } })
    .then((res) => { toReview.value = res.data?.data || [] }).catch(() => {})
  try {
    const projRes = await api.get("/projects")
    const projs = projRes.data?.data || projRes.data || []
    projects.value = projs

    const allMsgs = []

    for (const p of projs) {
      try {
        const [tRes, mRes] = await Promise.all([
          api.get(`/projects/${p.id}/tasks`),
          api.get(`/projects/${p.id}/messages`).catch(() => ({ data: [] })),
        ])
        const tasks = tRes.data?.data || tRes.data || []
        tasks.forEach(t => {
          const item = { ...t, projectName: p.title }
          allTasks.value.push(item)
          if (t.status === "pending_review") pendingTasks.value.push(item)
        })
        const msgs = mRes.data?.data || mRes.data || []
        msgs.forEach(m => allMsgs.push({ ...m, projectName: p.title }))
      } catch {}
    }

    recentMessages.value = allMsgs
      .sort((a, b) => new Date(b.created_at) - new Date(a.created_at))
      .slice(0, 5)
  } catch {}
})
</script>
