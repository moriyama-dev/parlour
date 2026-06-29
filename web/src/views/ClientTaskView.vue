<template>
  <div class="max-w-3xl mx-auto px-4 py-8">
    <div v-if="task" class="space-y-6">
      <div class="bg-white rounded-xl shadow p-6">
        <div class="flex items-start justify-between mb-4">
          <h1 class="text-xl font-bold text-gray-800">{{ task.title }}</h1>
          <StatusBadge :status="task.type" />
        </div>
        <p class="text-gray-600 mb-4">{{ task.description }}</p>
        <a v-if="task.staging_url" :href="task.staging_url" target="_blank"
          class="text-indigo-600 hover:underline text-sm">View on Staging</a>
      </div>

      <div class="bg-white rounded-xl shadow p-6">
        <h2 class="font-semibold text-gray-700 mb-3">Approval History</h2>
        <div v-if="!approvals.length" class="text-gray-400 text-sm">No history yet.</div>
        <div v-for="a in approvals" :key="a.id" class="py-3 border-b last:border-0">
          <div class="flex justify-between">
            <span class="font-medium text-sm">{{ a.user?.name }}</span>
            <StatusBadge :status="a.action" />
          </div>
          <div v-if="a.comment" class="text-sm text-gray-600 mt-1">{{ a.comment }}</div>
          <div class="text-xs text-gray-400 mt-1">{{ a.created_at }}</div>
        </div>
      </div>

      <div class="bg-white rounded-xl shadow p-6 space-y-4">
        <div class="flex gap-4">
          <button @click="approve"
            class="flex-1 bg-green-600 text-white py-3 rounded-xl font-semibold hover:bg-green-700">Approve</button>
          <button @click="showReject = !showReject"
            class="flex-1 bg-red-600 text-white py-3 rounded-xl font-semibold hover:bg-red-700">Send Back</button>
        </div>
        <div v-if="showReject" class="space-y-2">
          <textarea v-model="comment" placeholder="Please explain what needs to be changed (required)" rows="3"
            class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-red-400"></textarea>
          <button @click="reject" :disabled="!comment.trim()"
            class="w-full bg-red-500 text-white py-2 rounded-lg hover:bg-red-600 disabled:opacity-50">Confirm Send Back</button>
        </div>
        <p v-if="message" :class="msgClass">{{ message }}</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from "vue"
import { useRoute, useRouter } from "vue-router"
import api from "../api"
import StatusBadge from "../components/StatusBadge.vue"

const route = useRoute()
const router = useRouter()
const taskId = route.params.id
const task = ref(null)
const approvals = ref([])
const showReject = ref(false)
const comment = ref("")
const message = ref("")
const success = ref(false)
let projectId = null

const msgClass = computed(() =>
  success.value ? "text-sm text-center text-green-600" : "text-sm text-center text-red-600"
)

onMounted(async () => {
  const projRes = await api.get("/projects")
  const projects = projRes.data?.data || projRes.data || []
  for (const p of projects) {
    try {
      const tRes = await api.get(`/projects/${p.id}/tasks`)
      const tasks = tRes.data?.data || tRes.data || []
      const found = tasks.find(t => String(t.id) === String(taskId))
      if (found) {
        task.value = found
        projectId = p.id
        const aRes = await api.get(`/projects/${p.id}/tasks/${taskId}/approvals`).catch(() => ({ data: [] }))
        approvals.value = aRes.data?.data || aRes.data || []
        break
      }
    } catch {}
  }
})

async function approve() {
  try {
    await api.post(`/projects/${projectId}/tasks/${taskId}/approve`)
    success.value = true
    message.value = "Task approved!"
    setTimeout(() => router.push("/client/dashboard"), 1500)
  } catch (e) {
    message.value = e.response?.data?.message || "Failed to approve"
  }
}

async function reject() {
  if (!comment.value.trim()) return
  try {
    await api.post(`/projects/${projectId}/tasks/${taskId}/reject`, { comment: comment.value })
    success.value = true
    message.value = "Feedback sent!"
    setTimeout(() => router.push("/client/dashboard"), 1500)
  } catch (e) {
    message.value = e.response?.data?.message || "Failed to send back"
  }
}
</script>
