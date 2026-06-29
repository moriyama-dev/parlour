<template>
  <div class="max-w-4xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-800 mb-6">Client Dashboard</h1>

    <!-- My Projects -->
    <div class="bg-white rounded-xl shadow p-6 mb-6">
      <h2 class="text-lg font-semibold text-gray-700 mb-4">My Projects</h2>
      <div v-if="projects.length === 0" class="text-gray-400 text-sm">No projects assigned.</div>
      <div v-for="p in projects" :key="p.id"
        class="flex justify-between items-center py-3 border-b last:border-0">
        <div>
          <div class="font-medium text-gray-800">{{ p.title }}</div>
          <div class="text-sm text-gray-500">{{ p.company?.name }}</div>
        </div>
        <span class="text-xs px-2 py-1 rounded-full font-medium"
          :class="statusClass(p.status)">{{ p.status }}</span>
      </div>
    </div>

    <!-- Pending Review -->
    <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6 mb-6">
      <h2 class="text-lg font-semibold text-yellow-800 mb-4">Pending Your Review</h2>
      <div v-if="pendingTasks.length === 0" class="text-yellow-600 text-sm">No tasks pending review.</div>
      <div v-for="task in pendingTasks" :key="task.id"
        class="bg-white rounded-lg shadow px-4 py-3 mb-2 flex justify-between items-center">
        <div>
          <div class="font-medium text-gray-800">{{ task.title }}</div>
          <div class="text-sm text-gray-500">{{ task.projectName }}</div>
        </div>
        <button @click="$router.push(`/client/tasks/${task.id}`)"
          class="bg-yellow-500 text-white px-3 py-1.5 rounded-lg text-sm hover:bg-yellow-600">Review Now</button>
      </div>
    </div>

    <!-- All Tasks -->
    <div class="bg-white rounded-xl shadow p-6 mb-6">
      <h2 class="text-lg font-semibold text-gray-700 mb-4">All Tasks</h2>
      <div v-if="allTasks.length === 0" class="text-gray-400 text-sm">No tasks.</div>
      <div v-for="task in allTasks" :key="task.id"
        class="flex justify-between items-center py-3 border-b last:border-0">
        <div>
          <div class="font-medium text-gray-800">{{ task.title }}</div>
          <div class="text-sm text-gray-500">{{ task.projectName }}</div>
        </div>
        <span class="text-xs px-2 py-1 rounded-full font-medium"
          :class="taskStatusClass(task.status)">{{ task.status }}</span>
      </div>
    </div>

    <!-- Recent Messages -->
    <div class="bg-white rounded-xl shadow p-6">
      <h2 class="text-lg font-semibold text-gray-700 mb-4">Recent Messages</h2>
      <div v-if="recentMessages.length === 0" class="text-gray-400 text-sm">No messages.</div>
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
  </div>
</template>

<script setup>
import { ref, onMounted } from "vue"
import api from "../api"

const projects = ref([])
const pendingTasks = ref([])
const allTasks = ref([])
const recentMessages = ref([])

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
  return new Date(iso).toLocaleString()
}

onMounted(async () => {
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
          const item = { ...t, projectName: p.name }
          allTasks.value.push(item)
          if (t.status === "pending_review") pendingTasks.value.push(item)
        })
        const msgs = mRes.data?.data || mRes.data || []
        msgs.forEach(m => allMsgs.push({ ...m, projectName: p.name }))
      } catch {}
    }

    recentMessages.value = allMsgs
      .sort((a, b) => new Date(b.created_at) - new Date(a.created_at))
      .slice(0, 5)
  } catch {}
})
</script>
