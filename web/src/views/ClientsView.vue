<template>
  <div class="max-w-4xl mx-auto px-4 py-8">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
      <h1 class="text-2xl font-bold text-gray-800">Clients</h1>
      <button @click="openInviteModal"
        class="flex items-center gap-2 bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-indigo-700 transition">
        <span class="text-lg leading-none">+</span> Invite Client
      </button>
    </div>

    <!-- Filter Tabs -->
    <div class="flex gap-1 bg-gray-100 p-1 rounded-xl mb-5 w-fit">
      <button v-for="tab in tabs" :key="tab.key" @click="activeTab = tab.key"
        :class="[
          'px-4 py-1.5 rounded-lg text-sm font-medium transition',
          activeTab === tab.key
            ? 'bg-white text-gray-800 shadow-sm'
            : 'text-gray-500 hover:text-gray-700'
        ]">
        {{ tab.label }}
        <span class="ml-1.5 text-xs bg-gray-200 px-1.5 py-0.5 rounded-full"
          :class="activeTab === tab.key ? 'bg-indigo-100 text-indigo-700' : ''">
          {{ tab.count }}
        </span>
      </button>
    </div>

    <!-- Search (active & all tabs) -->
    <div v-if="activeTab !== 'pending'" class="mb-5">
      <input v-model="search" placeholder="Search by name, email, or company..."
        class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm" />
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center text-gray-400 py-16">Loading...</div>

    <!-- Active / All Clients List -->
    <template v-else-if="activeTab !== 'pending'">
      <div v-if="filteredClients.length === 0" class="text-center text-gray-400 py-16">
        <div class="text-5xl mb-4">👤</div>
        <p class="text-lg font-medium">{{ search ? 'No results found' : 'No clients yet' }}</p>
        <p class="text-sm mt-1">{{ search ? 'Try a different search term.' : 'Invite clients using the button above.' }}</p>
      </div>
      <div v-else class="space-y-3">
        <div v-for="c in filteredClients" :key="c.id"
          class="bg-white rounded-xl shadow px-6 py-4 flex items-center justify-between">
          <div class="flex items-center gap-4">
            <div class="w-10 h-10 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600 font-bold text-sm flex-shrink-0">
              {{ initials(c.name) }}
            </div>
            <div>
              <div class="font-semibold text-gray-800">{{ c.name }}</div>
              <div class="text-sm text-gray-400">{{ c.email }}</div>
              <div class="flex flex-wrap gap-1.5 mt-1">
                <span v-for="comp in c.companies" :key="comp.id"
                  class="inline-flex items-center text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full">
                  {{ comp.name }}
                  <span v-if="comp.is_primary" class="ml-1 text-indigo-500">★</span>
                </span>
              </div>
            </div>
          </div>
          <div class="flex items-center gap-3 text-right flex-shrink-0">
            <div class="text-sm text-gray-500 hidden sm:block">
              <div class="font-medium text-gray-700">{{ totalProjects(c) }}</div>
              <div class="text-xs">project{{ totalProjects(c) !== 1 ? 's' : '' }}</div>
            </div>
            <div class="text-xs text-gray-400 hidden sm:block">
              Joined<br>{{ formatDate(c.created_at) }}
            </div>
            <button @click="openEditModal(c)"
              class="text-xs text-gray-500 hover:text-indigo-600 border border-gray-200 hover:border-indigo-300 px-2 py-1.5 rounded-lg transition">
              Edit
            </button>
            <button @click="confirmRemove(c)"
              class="text-xs text-gray-400 hover:text-red-500 border border-gray-200 hover:border-red-300 px-2 py-1.5 rounded-lg transition">
              Remove
            </button>
          </div>
        </div>
      </div>
    </template>

    <!-- Pending Invites List -->
    <template v-else>
      <div v-if="pendingInvites.length === 0" class="text-center text-gray-400 py-16">
        <div class="text-5xl mb-4">✉️</div>
        <p class="text-lg font-medium">No pending invitations</p>
        <p class="text-sm mt-1">Use "Invite Client" to generate a registration link.</p>
      </div>
      <div v-else class="space-y-3">
        <div v-for="inv in pendingInvites" :key="inv.id"
          class="bg-white rounded-xl shadow px-6 py-4 flex items-center justify-between">
          <div class="flex items-center gap-4">
            <div class="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0"
              :class="inv.is_expired ? 'bg-red-50 text-red-400' : 'bg-amber-50 text-amber-500'">
              <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
              </svg>
            </div>
            <div>
              <div class="font-semibold text-gray-800">{{ inv.email || 'No email specified' }}</div>
              <div class="text-sm text-gray-500">{{ inv.company.name }}</div>
              <div class="flex items-center gap-2 mt-1">
                <span :class="[
                  'text-xs px-2 py-0.5 rounded-full font-medium',
                  inv.is_expired
                    ? 'bg-red-100 text-red-600'
                    : 'bg-amber-100 text-amber-700'
                ]">
                  {{ inv.is_expired ? 'Expired' : 'Pending' }}
                </span>
                <span class="text-xs text-gray-400">
                  {{ inv.is_expired ? 'Expired' : 'Expires' }} {{ formatDate(inv.expires_at) }}
                </span>
              </div>
            </div>
          </div>
          <div class="flex items-center gap-2 flex-shrink-0">
            <button v-if="!inv.is_expired" @click="copyInviteUrl(inv.token)"
              class="text-xs text-gray-500 hover:text-indigo-600 border border-gray-200 hover:border-indigo-300 px-2 py-1.5 rounded-lg transition">
              {{ copiedToken === inv.token ? 'Copied!' : 'Copy URL' }}
            </button>
            <button @click="confirmCancelInvite(inv)"
              class="text-xs text-gray-400 hover:text-red-500 border border-gray-200 hover:border-red-300 px-2 py-1.5 rounded-lg transition">
              Cancel
            </button>
          </div>
        </div>
      </div>
    </template>

    <!-- ── Invite Modal ── -->
    <div v-if="inviteModal.open" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
        <div class="px-6 py-4 border-b flex justify-between items-center">
          <h2 class="text-lg font-semibold text-gray-800">Invite Client</h2>
          <button @click="closeInviteModal" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
        </div>
        <div class="px-6 py-5 space-y-4">
          <div v-if="!inviteModal.generatedToken">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Company <span class="text-red-500">*</span></label>
              <select v-model="inviteModal.companyId"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <option value="">Select company...</option>
                <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Email <span class="text-xs text-gray-400">(optional)</span></label>
              <input v-model="inviteModal.email" type="email" placeholder="client@example.com"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
              <p class="text-xs text-gray-400 mt-1">Pre-fills the email field on the registration form.</p>
            </div>
            <div v-if="inviteModal.error" class="text-sm text-red-500 bg-red-50 rounded-lg p-3">{{ inviteModal.error }}</div>
          </div>

          <!-- Generated URL display -->
          <div v-else class="space-y-3">
            <p class="text-sm text-gray-600">Share this link with your client. It expires in 7 days.</p>
            <div class="bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 flex items-center gap-2">
              <span class="text-xs text-gray-700 break-all flex-1 font-mono">{{ inviteUrl(inviteModal.generatedToken) }}</span>
              <button @click="copyInviteUrl(inviteModal.generatedToken)"
                class="flex-shrink-0 text-xs bg-indigo-600 text-white px-2 py-1 rounded hover:bg-indigo-700 transition">
                {{ copiedToken === inviteModal.generatedToken ? 'Copied!' : 'Copy' }}
              </button>
            </div>
            <p class="text-xs text-gray-400">Once copied, you can invite another client or close this dialog.</p>
            <button @click="resetInviteForm"
              class="text-sm text-indigo-600 hover:underline">+ Invite another client</button>
          </div>
        </div>
        <div class="px-6 py-4 border-t flex justify-end gap-3">
          <button @click="closeInviteModal"
            class="px-4 py-2 text-sm border rounded-lg hover:bg-gray-50">Close</button>
          <button v-if="!inviteModal.generatedToken" @click="generateInvite"
            :disabled="!inviteModal.companyId || inviteModal.loading"
            class="px-4 py-2 text-sm bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition">
            {{ inviteModal.loading ? 'Generating...' : 'Generate Invite Link' }}
          </button>
        </div>
      </div>
    </div>

    <!-- ── Edit Modal ── -->
    <div v-if="editModal.open" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
        <div class="px-6 py-4 border-b flex justify-between items-center">
          <h2 class="text-lg font-semibold text-gray-800">Edit Client</h2>
          <button @click="editModal.open = false" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
        </div>
        <div class="px-6 py-5 space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
            <input v-model="editModal.name" type="text"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
            <input v-model="editModal.email" type="email"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          </div>
          <div v-if="editModal.error" class="text-sm text-red-500 bg-red-50 rounded-lg p-3">{{ editModal.error }}</div>
        </div>
        <div class="px-6 py-4 border-t flex justify-end gap-3">
          <button @click="editModal.open = false" class="px-4 py-2 text-sm border rounded-lg hover:bg-gray-50">Cancel</button>
          <button @click="saveEdit" :disabled="editModal.loading"
            class="px-4 py-2 text-sm bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition">
            {{ editModal.loading ? 'Saving...' : 'Save Changes' }}
          </button>
        </div>
      </div>
    </div>

    <!-- ── Remove Confirmation ── -->
    <div v-if="removing" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-sm">
        <h2 class="text-lg font-semibold text-gray-800 mb-2">Remove Client?</h2>
        <p class="text-sm text-gray-500 mb-5">
          <strong>{{ removing.name }}</strong> will lose access to all projects. This cannot be undone.
        </p>
        <div class="flex gap-3">
          <button @click="removing = null" class="flex-1 border rounded-lg py-2 text-sm">Cancel</button>
          <button @click="removeClient" :disabled="removeLoading"
            class="flex-1 bg-red-500 text-white rounded-lg py-2 text-sm hover:bg-red-600 disabled:opacity-50">
            {{ removeLoading ? 'Removing...' : 'Yes, Remove' }}
          </button>
        </div>
      </div>
    </div>

    <!-- ── Cancel Invite Confirmation ── -->
    <div v-if="cancelingInvite" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-sm">
        <h2 class="text-lg font-semibold text-gray-800 mb-2">Cancel Invitation?</h2>
        <p class="text-sm text-gray-500 mb-5">
          The invite link for <strong>{{ cancelingInvite.email || 'this invitation' }}</strong> will be permanently deleted.
        </p>
        <div class="flex gap-3">
          <button @click="cancelingInvite = null" class="flex-1 border rounded-lg py-2 text-sm">Keep</button>
          <button @click="cancelInvite" :disabled="cancelLoading"
            class="flex-1 bg-red-500 text-white rounded-lg py-2 text-sm hover:bg-red-600 disabled:opacity-50">
            {{ cancelLoading ? 'Canceling...' : 'Yes, Cancel' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, reactive } from 'vue'
import api from '../api'

const clients = ref([])
const pendingInvites = ref([])
const companies = ref([])
const loading = ref(true)
const activeTab = ref('active')
const search = ref('')
const removing = ref(null)
const removeLoading = ref(false)
const cancelingInvite = ref(null)
const cancelLoading = ref(false)
const copiedToken = ref(null)

const inviteModal = reactive({
  open: false,
  companyId: '',
  email: '',
  loading: false,
  error: null,
  generatedToken: null,
})

const editModal = reactive({
  open: false,
  client: null,
  name: '',
  email: '',
  loading: false,
  error: null,
})

const tabs = computed(() => [
  { key: 'active', label: 'Active Clients', count: clients.value.length },
  { key: 'pending', label: 'Pending Invites', count: pendingInvites.value.length },
])

const filteredClients = computed(() => {
  const q = search.value.toLowerCase()
  if (!q) return clients.value
  return clients.value.filter(c =>
    c.name.toLowerCase().includes(q) ||
    c.email.toLowerCase().includes(q) ||
    c.companies.some(comp => comp.name.toLowerCase().includes(q))
  )
})

onMounted(() => {
  fetchAll()
})

async function fetchAll() {
  loading.value = true
  try {
    const [clientsRes, invitesRes, companiesRes] = await Promise.all([
      api.get('/clients'),
      api.get('/invitations'),
      api.get('/companies'),
    ])
    clients.value = clientsRes.data?.data || clientsRes.data || []
    pendingInvites.value = invitesRes.data?.data || invitesRes.data || []
    companies.value = companiesRes.data?.data || companiesRes.data || []
  } finally {
    loading.value = false
  }
}

function totalProjects(c) {
  return c.companies.reduce((sum, comp) => sum + (comp.projects_count ?? 0), 0)
}

function initials(name) {
  return name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase()
}

function formatDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('en-CA', { year: 'numeric', month: 'short', day: 'numeric' })
}

function inviteUrl(token) {
  return window.location.origin + '/invite/' + token
}

function copyInviteUrl(token) {
  const url = inviteUrl(token)
  if (navigator.clipboard) {
    navigator.clipboard.writeText(url).then(() => flashCopied(token))
  } else {
    const ta = document.createElement('textarea')
    ta.value = url
    ta.style.position = 'fixed'
    ta.style.opacity = '0'
    document.body.appendChild(ta)
    ta.focus()
    ta.select()
    document.execCommand('copy')
    document.body.removeChild(ta)
    flashCopied(token)
  }
}

function flashCopied(token) {
  copiedToken.value = token
  setTimeout(() => { if (copiedToken.value === token) copiedToken.value = null }, 2000)
}

// Invite modal
function openInviteModal() {
  resetInviteForm()
  inviteModal.open = true
}

function closeInviteModal() {
  inviteModal.open = false
  if (inviteModal.generatedToken) fetchAll()
}

function resetInviteForm() {
  inviteModal.companyId = ''
  inviteModal.email = ''
  inviteModal.loading = false
  inviteModal.error = null
  inviteModal.generatedToken = null
}

async function generateInvite() {
  if (!inviteModal.companyId) return
  inviteModal.loading = true
  inviteModal.error = null
  try {
    const res = await api.post(`/companies/${inviteModal.companyId}/invitations`, {
      email: inviteModal.email || undefined,
    })
    inviteModal.generatedToken = res.data.token
    pendingInvites.value = [] // will refresh on close
  } catch (e) {
    inviteModal.error = e.response?.data?.message || 'Failed to generate invite.'
  } finally {
    inviteModal.loading = false
  }
}

// Edit modal
function openEditModal(c) {
  editModal.client = c
  editModal.name = c.name
  editModal.email = c.email
  editModal.error = null
  editModal.loading = false
  editModal.open = true
}

async function saveEdit() {
  editModal.loading = true
  editModal.error = null
  try {
    await api.put(`/clients/${editModal.client.id}`, {
      name: editModal.name,
      email: editModal.email,
    })
    const idx = clients.value.findIndex(c => c.id === editModal.client.id)
    if (idx !== -1) {
      clients.value[idx].name = editModal.name
      clients.value[idx].email = editModal.email
    }
    editModal.open = false
  } catch (e) {
    editModal.error = e.response?.data?.message || 'Failed to save changes.'
  } finally {
    editModal.loading = false
  }
}

// Remove client
function confirmRemove(c) {
  removing.value = c
}

async function removeClient() {
  removeLoading.value = true
  try {
    await api.delete(`/clients/${removing.value.id}`)
    clients.value = clients.value.filter(c => c.id !== removing.value.id)
    removing.value = null
  } catch (e) {
    alert(e.response?.data?.message || 'Failed to remove client')
  } finally {
    removeLoading.value = false
  }
}

// Cancel invitation
function confirmCancelInvite(inv) {
  cancelingInvite.value = inv
}

async function cancelInvite() {
  cancelLoading.value = true
  try {
    await api.delete(`/invitations/${cancelingInvite.value.id}`)
    pendingInvites.value = pendingInvites.value.filter(i => i.id !== cancelingInvite.value.id)
    cancelingInvite.value = null
  } catch (e) {
    alert(e.response?.data?.message || 'Failed to cancel invitation')
  } finally {
    cancelLoading.value = false
  }
}
</script>
