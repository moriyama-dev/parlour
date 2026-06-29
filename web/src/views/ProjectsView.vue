<template>
  <div class="max-w-6xl mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
      <h1 class="text-2xl font-bold text-gray-800">Projects</h1>
      <div class="flex gap-2">
        <button @click="showInviteModal = true"
          class="border border-indigo-600 text-indigo-600 px-4 py-2 rounded-lg hover:bg-indigo-50">
          Invite Client
        </button>
        <button @click="showModal = true"
          class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700">
          New Project
        </button>
      </div>
    </div>

    <div v-for="(group, company) in grouped" :key="company" class="mb-6">
      <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3">{{ company }}</h2>
      <div class="space-y-2">
        <div v-for="p in group" :key="p.id"
          @click="$router.push(`/projects/${p.id}`)"
          class="bg-white rounded-xl shadow px-5 py-4 flex justify-between items-center cursor-pointer hover:shadow-md transition">
          <div>
            <div class="font-medium text-gray-800">{{ p.title || p.name }}</div>
            <div class="text-sm text-gray-400">{{ p.tasks_count || 0 }} tasks</div>
          </div>
          <StatusBadge :status="p.status" />
        </div>
      </div>
    </div>

    <div v-if="projects.length === 0" class="text-center text-gray-400 py-16">
      <div class="text-5xl mb-4">🏗️</div>
      <p class="text-lg font-medium">No projects yet</p>
      <p class="text-sm mt-1">Create your first project to get started.</p>
    </div>

    <!-- New Project Modal -->
    <div v-if="showModal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
      <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-md">
        <h2 class="text-lg font-semibold mb-4">New Project</h2>
        <form @submit.prevent="createProject" class="space-y-3">
          <input v-model="form.title" placeholder="Project name" required
            class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          <select v-model="form.company_id" required
            class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
            <option value="">Select company...</option>
            <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
          <input v-model="form.staging_url" placeholder="Staging URL (optional)"
            class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          <input v-model="form.production_url" placeholder="Production URL (optional)"
            class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          <p v-if="projectError" class="text-red-500 text-sm">{{ projectError }}</p>
          <div class="flex gap-3 justify-end pt-2">
            <button type="button" @click="showModal = false" class="px-4 py-2 border rounded-lg">Cancel</button>
            <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">Create</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Invite Client Modal -->
    <div v-if="showInviteModal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
      <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-md">
        <h2 class="text-lg font-semibold mb-4">Invite Client</h2>

        <div v-if="!inviteUrl" class="space-y-3">
          <select v-model="inviteCompanyId" required
            class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
            <option value="">Select company to invite for...</option>
            <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
          <input v-model="inviteEmail" type="email" placeholder="Client email (optional — pre-fills the form)"
            class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          <p v-if="inviteError" class="text-red-500 text-sm">{{ inviteError }}</p>
          <div class="flex gap-3 justify-end pt-2">
            <button type="button" @click="closeInviteModal" class="px-4 py-2 border rounded-lg">Cancel</button>
            <button @click="generateInvite" :disabled="!inviteCompanyId || inviteLoading"
              class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50">
              {{ inviteLoading ? 'Generating...' : 'Generate Invite Link' }}
            </button>
          </div>
        </div>

        <div v-else class="space-y-4">
          <p class="text-sm text-gray-600">Share this link with your client. It expires in <strong>7 days</strong>.</p>
          <div class="bg-gray-50 border rounded-lg p-3 break-all text-sm text-gray-800 font-mono">{{ inviteUrl }}</div>
          <div class="flex gap-3">
            <button @click="copyInviteUrl"
              class="flex-1 border border-indigo-600 text-indigo-600 py-2 rounded-lg hover:bg-indigo-50">
              {{ copied ? '✓ Copied!' : 'Copy Link' }}
            </button>
            <button @click="closeInviteModal"
              class="flex-1 bg-indigo-600 text-white py-2 rounded-lg hover:bg-indigo-700">
              Done
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from "vue"
import api from "../api"
import StatusBadge from "../components/StatusBadge.vue"

const projects = ref([])
const companies = ref([])
const showModal = ref(false)
const showInviteModal = ref(false)
const form = ref({ title: "", company_id: "", staging_url: "", production_url: "" })
const projectError = ref("")
const inviteCompanyId = ref("")
const inviteEmail = ref("")
const inviteUrl = ref("")
const inviteLoading = ref(false)
const inviteError = ref("")
const copied = ref(false)

const grouped = computed(() => {
  const g = {}
  for (const p of projects.value) {
    const c = p.company?.name || "Unknown"
    if (!g[c]) g[c] = []
    g[c].push(p)
  }
  return g
})

async function loadCompanies() {
  try {
    const res = await api.get("/companies")
    companies.value = res.data?.data || res.data || []
  } catch (e) {
    console.error("Failed to load companies", e)
  }
}

async function loadProjects() {
  try {
    const res = await api.get("/projects")
    projects.value = res.data?.data || res.data || []
  } catch (e) {
    console.error("Failed to load projects", e)
  }
}

onMounted(() => {
  loadCompanies()
  loadProjects()
})

async function createProject() {
  projectError.value = ""
  try {
    await api.post("/projects", form.value)
    showModal.value = false
    const res = await api.get("/projects")
    projects.value = res.data?.data || res.data || []
    form.value = { title: "", company_id: "", staging_url: "", production_url: "" }
  } catch (e) {
    projectError.value = e.response?.data?.message || "Failed to create project"
  }
}

async function generateInvite() {
  inviteError.value = ""
  inviteLoading.value = true
  try {
    const res = await api.post(`/companies/${inviteCompanyId.value}/invitations`, {
      email: inviteEmail.value || undefined,
    })
    inviteUrl.value = window.location.origin + "/invite/" + res.data.token
  } catch (e) {
    inviteError.value = e.response?.data?.message || "Failed to generate invite"
  } finally {
    inviteLoading.value = false
  }
}

async function copyToClipboard(text) {
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text)
      return true
    }
  } catch (e) { /* fall through */ }
  try {
    const ta = document.createElement('textarea')
    ta.value = text
    ta.style.position = 'fixed'
    ta.style.opacity = '0'
    document.body.appendChild(ta)
    ta.focus(); ta.select()
    const ok = document.execCommand('copy')
    document.body.removeChild(ta)
    return ok
  } catch (e) { return false }
}

async function copyInviteUrl() {
  await copyToClipboard(inviteUrl.value)
  copied.value = true
  setTimeout(() => { copied.value = false }, 2000)
}

function closeInviteModal() {
  showInviteModal.value = false
  inviteUrl.value = ""
  inviteEmail.value = ""
  inviteCompanyId.value = ""
  inviteError.value = ""
  copied.value = false
}
</script>
