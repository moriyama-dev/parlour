<template>
  <div class="max-w-4xl mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
      <h1 class="text-2xl font-bold text-gray-800">Companies</h1>
      <button @click="openCreate" class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700">
        + Add Company
      </button>
    </div>

    <div v-if="loading" class="text-center text-gray-400 py-16">Loading...</div>

    <div v-else-if="companies.length === 0" class="text-center text-gray-400 py-16">
      <div class="text-5xl mb-4">🏢</div>
      <p class="text-lg font-medium">No companies yet</p>
    </div>

    <div v-else class="space-y-3">
      <div v-for="c in companies" :key="c.id"
        class="bg-white rounded-xl shadow px-6 py-4 flex justify-between items-center">
        <div class="cursor-pointer flex-1" @click="openDetail(c)">
          <div class="font-semibold text-gray-800 hover:text-indigo-600 transition">{{ c.name }}</div>
          <div class="flex items-center gap-3 text-sm mt-1">
            <span class="text-gray-500">
              <span class="font-medium text-gray-700">{{ c.users_count ?? 0 }}</span> client{{ (c.users_count ?? 0) !== 1 ? 's' : '' }}
            </span>
            <span v-if="(c.pending_invites_count ?? 0) > 0"
              class="inline-flex items-center gap-1 text-amber-600 bg-amber-50 px-2 py-0.5 rounded-full text-xs font-medium">
              ✉ {{ c.pending_invites_count }} invite{{ c.pending_invites_count !== 1 ? 's' : '' }} pending
            </span>
            <span v-if="c.website" class="text-gray-400">
              <a :href="c.website" target="_blank" @click.stop class="hover:text-indigo-500">{{ c.website }}</a>
            </span>
          </div>
        </div>
        <div class="flex gap-2 ml-4">
          <button @click="openEdit(c)" class="text-sm border border-gray-300 px-3 py-1.5 rounded-lg hover:bg-gray-50">Edit</button>
          <button @click="openInvite(c)" class="text-sm border border-indigo-300 text-indigo-600 px-3 py-1.5 rounded-lg hover:bg-indigo-50">Invite</button>
        </div>
      </div>
    </div>

    <!-- Company Detail Panel -->
    <div v-if="detail" class="fixed inset-0 bg-black/40 flex justify-end z-50" @click.self="detail = null">
      <div class="bg-white w-full max-w-lg h-full overflow-y-auto shadow-xl flex flex-col">
        <div class="px-6 py-4 border-b flex justify-between items-center">
          <div>
            <h2 class="text-lg font-bold text-gray-800">{{ detail.name }}</h2>
            <a v-if="detail.website" :href="detail.website" target="_blank"
              class="text-sm text-indigo-500 hover:underline">{{ detail.website }}</a>
          </div>
          <button @click="detail = null" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
        </div>

        <div v-if="detailLoading" class="flex-1 flex items-center justify-center text-gray-400">Loading...</div>
        <div v-else class="flex-1 px-6 py-5 space-y-8">

          <!-- Registered Clients -->
          <div>
            <div class="flex justify-between items-center mb-3">
              <h3 class="font-semibold text-gray-700">Registered Clients</h3>
              <span class="text-xs bg-gray-100 text-gray-500 px-2 py-1 rounded-full">{{ detail.users?.length ?? 0 }}</span>
            </div>
            <div v-if="!detail.users?.length" class="text-sm text-gray-400 bg-gray-50 rounded-lg px-4 py-6 text-center">
              No clients yet. Send an invite link to get started.
            </div>
            <div v-else class="space-y-2">
              <div v-for="u in detail.users" :key="u.id"
                class="flex items-center justify-between bg-gray-50 rounded-lg px-4 py-3">
                <div>
                  <div class="font-medium text-gray-800 text-sm">{{ u.name }}</div>
                  <div class="text-xs text-gray-400">{{ u.email }}</div>
                </div>
                <span v-if="u.is_primary"
                  class="text-xs bg-indigo-100 text-indigo-600 px-2 py-0.5 rounded-full">Primary</span>
              </div>
            </div>
          </div>

          <!-- Invitations -->
          <div>
            <div class="flex justify-between items-center mb-3">
              <h3 class="font-semibold text-gray-700">Invitations</h3>
              <button @click="openInvite(detail)"
                class="text-xs text-indigo-600 border border-indigo-300 px-3 py-1 rounded-lg hover:bg-indigo-50">
                + New Invite
              </button>
            </div>
            <div v-if="!detail.invitations?.length" class="text-sm text-gray-400 bg-gray-50 rounded-lg px-4 py-6 text-center">
              No invitations sent yet.
            </div>
            <div v-else class="space-y-2">
              <div v-for="inv in detail.invitations" :key="inv.id"
                class="bg-gray-50 rounded-lg px-4 py-3">
                <div class="flex justify-between items-start">
                  <div>
                    <div class="text-sm font-medium text-gray-800">{{ inv.email || 'No email specified' }}</div>
                    <div class="text-xs text-gray-400 mt-0.5">Sent by {{ inv.created_by }}</div>
                  </div>
                  <span :class="inviteStatusClass(inv)" class="text-xs px-2 py-0.5 rounded-full font-medium">
                    {{ inviteStatusLabel(inv) }}
                  </span>
                </div>
                <div v-if="!inv.accepted_at && !inv.is_expired" class="mt-2 flex items-center gap-2">
                  <span class="text-xs text-gray-400 font-mono truncate flex-1">{{ inviteUrlFor(inv) }}</span>
                  <button @click="copyText(inviteUrlFor(inv), inv.id)"
                    class="text-xs text-indigo-600 hover:underline whitespace-nowrap">
                    {{ copiedId === inv.id ? 'Copied!' : 'Copy' }}
                  </button>
                </div>
                <div class="text-xs text-gray-400 mt-1">
                  Expires: {{ formatDate(inv.expires_at) }}
                  <span v-if="inv.accepted_at"> · Accepted: {{ formatDate(inv.accepted_at) }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Create / Edit Modal -->
    <div v-if="showFormModal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
      <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-md">
        <h2 class="text-lg font-semibold mb-4">{{ editingId ? 'Edit Company' : 'Add Company' }}</h2>
        <form @submit.prevent="saveCompany" class="space-y-3">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Name <span class="text-red-400">*</span></label>
            <input v-model="form.name" required placeholder="e.g. Toronto Shokokai / Acme Corp"
              class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Slug <span class="text-red-400">*</span></label>
            <input v-model="form.slug" required placeholder="e.g. toronto-shokokai"
              class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
            <p class="text-xs text-gray-400 mt-1">Auto-filled. Lowercase, hyphens only.</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Website</label>
            <input v-model="form.website" type="url" placeholder="https://example.com"
              class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Timezone</label>
            <select v-model="form.timezone"
              class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
              <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
            </select>
          </div>
          <p v-if="formError" class="text-red-500 text-sm">{{ formError }}</p>
          <div class="flex gap-3 justify-end pt-2">
            <button type="button" @click="showFormModal = false" class="px-4 py-2 border rounded-lg">Cancel</button>
            <button type="submit" :disabled="saving"
              class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50">
              {{ saving ? 'Saving...' : (editingId ? 'Save Changes' : 'Add Company') }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Invite Modal -->
    <div v-if="showInviteModal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
      <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-md">
        <h2 class="text-lg font-semibold mb-1">Invite Client</h2>
        <p class="text-sm text-gray-500 mb-4">Company: <span class="font-medium text-gray-700">{{ inviteCompanyName }}</span></p>
        <div v-if="!inviteUrl" class="space-y-3">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Client Email (optional)</label>
            <input v-model="inviteEmail" type="email" placeholder="client@example.com"
              class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
            <p class="text-xs text-gray-400 mt-1">Pre-fills the signup form for the client.</p>
          </div>
          <p v-if="inviteError" class="text-red-500 text-sm">{{ inviteError }}</p>
          <div class="flex gap-3 justify-end pt-2">
            <button type="button" @click="closeInviteModal" class="px-4 py-2 border rounded-lg">Cancel</button>
            <button @click="generateInvite" :disabled="inviteLoading"
              class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50">
              {{ inviteLoading ? 'Generating...' : 'Generate Invite Link' }}
            </button>
          </div>
        </div>
        <div v-else class="space-y-4">
          <p class="text-sm text-gray-600">Share this link. Expires in <strong>7 days</strong>.</p>
          <div class="bg-gray-50 border rounded-lg p-3 break-all text-sm font-mono text-gray-800">{{ inviteUrl }}</div>
          <div class="flex gap-3">
            <button @click="copyInviteUrl"
              class="flex-1 border border-indigo-600 text-indigo-600 py-2 rounded-lg hover:bg-indigo-50">
              {{ copied ? '✓ Copied!' : 'Copy Link' }}
            </button>
            <button @click="closeInviteModal"
              class="flex-1 bg-indigo-600 text-white py-2 rounded-lg hover:bg-indigo-700">Done</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue'
import api from '../api'

const companies = ref([])
const loading = ref(true)
const detail = ref(null)
const detailLoading = ref(false)
const showFormModal = ref(false)
const showInviteModal = ref(false)
const editingId = ref(null)
const saving = ref(false)
const formError = ref('')
const inviteCompanyId = ref(null)
const inviteCompanyName = ref('')
const inviteEmail = ref('')
const inviteUrl = ref('')
const inviteLoading = ref(false)
const inviteError = ref('')
const copied = ref(false)
const copiedId = ref(null)

const form = ref({ name: '', slug: '', website: '', timezone: 'America/Toronto' })
const timezones = [
  'America/Toronto','America/New_York','America/Chicago','America/Denver',
  'America/Los_Angeles','America/Vancouver','Europe/London','Europe/Paris',
  'Asia/Tokyo','Asia/Seoul','Australia/Sydney','Pacific/Auckland',
]

watch(() => form.value.name, (val) => {
  if (!editingId.value) {
    form.value.slug = val.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')
  }
})

onMounted(fetchCompanies)

async function fetchCompanies() {
  loading.value = true
  try {
    const res = await api.get('/companies')
    companies.value = res.data?.data || res.data || []
  } finally {
    loading.value = false
  }
}

async function openDetail(c) {
  detail.value = { ...c }
  detailLoading.value = true
  try {
    const res = await api.get(`/companies/${c.id}`)
    detail.value = res.data
  } finally {
    detailLoading.value = false
  }
}

function openCreate() {
  editingId.value = null
  form.value = { name: '', slug: '', website: '', timezone: 'America/Toronto' }
  formError.value = ''
  showFormModal.value = true
}

function openEdit(c) {
  editingId.value = c.id
  form.value = { name: c.name, slug: c.slug, website: c.website || '', timezone: c.timezone }
  formError.value = ''
  showFormModal.value = true
}

async function saveCompany() {
  formError.value = ''
  saving.value = true
  try {
    if (editingId.value) {
      await api.put(`/companies/${editingId.value}`, form.value)
    } else {
      await api.post('/companies', form.value)
    }
    showFormModal.value = false
    await fetchCompanies()
  } catch (e) {
    formError.value = e.response?.data?.message ||
      Object.values(e.response?.data?.errors || {}).flat().join(' ') || 'Failed to save'
  } finally {
    saving.value = false
  }
}

function openInvite(c) {
  inviteCompanyId.value = c.id
  inviteCompanyName.value = c.name
  inviteEmail.value = ''
  inviteUrl.value = ''
  inviteError.value = ''
  copied.value = false
  showInviteModal.value = true
}

async function generateInvite() {
  inviteError.value = ''
  inviteLoading.value = true
  try {
    const res = await api.post(`/companies/${inviteCompanyId.value}/invitations`, {
      email: inviteEmail.value || undefined,
    })
    inviteUrl.value = inviteUrlForToken(res.data.token)
    if (detail.value?.id === inviteCompanyId.value) {
      const r = await api.get(`/companies/${inviteCompanyId.value}`)
      detail.value = r.data
    }
    await fetchCompanies()
  } catch (e) {
    inviteError.value = e.response?.data?.message || 'Failed to generate invite'
  } finally {
    inviteLoading.value = false
  }
}

function inviteUrlForToken(token) {
  return window.location.origin + '/invite/' + token
}

function inviteUrlFor(inv) {
  return inviteUrlForToken(inv.token)
}

async function copyToClipboard(text) {
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text)
      return true
    }
  } catch (e) { /* fall through */ }
  // Fallback for non-secure (http) contexts
  try {
    const ta = document.createElement('textarea')
    ta.value = text
    ta.style.position = 'fixed'
    ta.style.opacity = '0'
    document.body.appendChild(ta)
    ta.focus()
    ta.select()
    const ok = document.execCommand('copy')
    document.body.removeChild(ta)
    return ok
  } catch (e) {
    return false
  }
}

async function copyInviteUrl() {
  await copyToClipboard(inviteUrl.value)
  copied.value = true
  setTimeout(() => { copied.value = false }, 2000)
}

async function copyText(text, id) {
  await copyToClipboard(text)
  copiedId.value = id
  setTimeout(() => { copiedId.value = null }, 2000)
}

function closeInviteModal() {
  showInviteModal.value = false
  inviteUrl.value = ''
  inviteEmail.value = ''
  inviteError.value = ''
  copied.value = false
}

function inviteStatusLabel(inv) {
  if (inv.accepted_at) return 'Accepted'
  if (inv.is_expired) return 'Expired'
  return 'Pending'
}

function inviteStatusClass(inv) {
  if (inv.accepted_at) return 'bg-green-100 text-green-700'
  if (inv.is_expired) return 'bg-gray-100 text-gray-500'
  return 'bg-amber-100 text-amber-700'
}

function formatDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('en-CA', { year: 'numeric', month: 'short', day: 'numeric' })
}
</script>
