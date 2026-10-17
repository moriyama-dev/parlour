<template>
  <div class="max-w-6xl mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
      <div>
        <h1 class="font-serif text-2xl font-semibold text-ink">{{ t("projects.title") }}</h1>
        <p class="text-sm text-gray-500 mt-1">{{ auth.isClient ? t("projects.leadClient") : t("projects.leadDeveloper") }}</p>
      </div>
      <div class="flex gap-2">
        <button v-if="auth.isDeveloper" @click="showInviteModal = true"
          class="border border-indigo-600 text-indigo-600 px-4 py-2 rounded-lg hover:bg-indigo-50 cursor-pointer">
          {{ t("projects.inviteClient") }}
        </button>
        <button @click="showModal = true"
          class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700">
          {{ t("projects.newProject") }}
        </button>
      </div>
    </div>

    <div v-for="(group, company) in grouped" :key="company" class="mb-6">
      <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3">{{ company }}</h2>
      <div class="space-y-2">
        <div v-for="p in group" :key="p.id"
          @click="$router.push(`/projects/${p.id}`)"
          class="bg-white rounded-xl shadow px-5 py-4 flex flex-wrap items-center gap-x-6 gap-y-3 cursor-pointer hover:shadow-md transition">
          <div class="min-w-[12rem] flex-1">
            <div class="font-medium text-gray-800">{{ p.title || p.name }}</div>
            <div class="text-sm text-gray-400">{{ t("projects.tasksCount", { count: p.task_stats?.total || 0 }) }}</div>
          </div>
          <!-- 進捗（完了タスクの割合）。案件を開かなくても進み具合が分かるように。 -->
          <div class="w-full sm:w-56 shrink-0" @click.stop>
            <ProgressMeter :stats="p.task_stats || {}" />
          </div>
          <StatusBadge :status="p.status" />
        </div>
      </div>
    </div>

    <div v-if="projects.length === 0" class="text-center text-gray-400 py-16">
      <div class="text-5xl mb-4">🏗️</div>
      <p class="text-lg font-medium">{{ t("projects.emptyTitle") }}</p>
      <p class="text-sm mt-1">{{ t("projects.emptyHint") }}</p>
    </div>

    <!-- New Project Modal -->
    <div v-if="showModal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-lg max-h-[92vh] overflow-y-auto">
        <h2 class="text-lg font-semibold mb-4">{{ t("projects.newProject") }}</h2>
        <form @submit.prevent="createProject" class="space-y-3">
          <input v-model="form.title" :placeholder="t('projects.name')" required
            class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          <select v-model="form.company_id" required
            class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
            <option value="">{{ t("projects.selectCompany") }}</option>
            <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
          <textarea v-model="form.description" :placeholder="t('projects.descriptionOptional')" rows="3"
            class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
          <input v-model="form.staging_url" :placeholder="t('projects.stagingUrlOptional')"
            class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          <input v-model="form.production_url" :placeholder="t('projects.productionUrlOptional')"
            class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />

          <!-- ヒアリング（最初の打ち合わせで聞くことを先に書いてもらう） -->
          <details class="border border-mist rounded-lg px-3 py-2" :open="auth.isClient">
            <summary class="text-sm font-medium text-gray-700 cursor-pointer">{{ t("projects.brief.heading") }}</summary>
            <p class="text-xs text-gray-400 mt-1 mb-3">{{ t("projects.brief.lead") }}</p>
            <div class="space-y-3">
              <label v-for="f in BRIEF_TEXT_FIELDS" :key="f" class="block text-xs text-gray-500">{{ t(`projects.brief.${f}`) }}
                <textarea v-model="form.brief[f]" rows="2" maxlength="5000"
                  class="mt-1 w-full border rounded-lg px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
              </label>
              <div class="grid grid-cols-2 gap-3">
                <label class="block text-xs text-gray-500">{{ t("projects.brief.budget") }}
                  <select v-model="form.brief.budget" class="mt-1 w-full border rounded-lg px-3 py-2 text-sm text-ink">
                    <option value="">{{ t("projects.brief.selectBudget") }}</option>
                    <option v-for="b in BUDGET_BANDS" :key="b" :value="b">{{ t(`projects.brief.budgetBands.${b}`) }}</option>
                  </select>
                </label>
                <label class="block text-xs text-gray-500">{{ t("projects.brief.deadline") }}
                  <input v-model="form.brief.deadline" type="date" class="mt-1 w-full border rounded-lg px-3 py-2 text-sm text-ink" />
                </label>
              </div>
            </div>
          </details>
          <p v-if="projectError" class="text-red-500 text-sm">{{ projectError }}</p>
          <div class="flex gap-3 justify-end pt-2">
            <button type="button" @click="showModal = false" class="px-4 py-2 border rounded-lg">{{ t("common.cancel") }}</button>
            <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">{{ t("common.create") }}</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Invite Client Modal -->
    <div v-if="showInviteModal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
      <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-md">
        <h2 class="text-lg font-semibold mb-4">{{ t("projects.inviteClient") }}</h2>

        <div v-if="!inviteUrl" class="space-y-3">
          <select v-model="inviteCompanyId" required
            class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
            <option value="">{{ t("projects.inviteSelectCompany") }}</option>
            <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
          <input v-model="inviteEmail" type="email" :placeholder="t('projects.inviteEmailOptional')"
            class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          <p v-if="inviteError" class="text-red-500 text-sm">{{ inviteError }}</p>
          <div class="flex gap-3 justify-end pt-2">
            <button type="button" @click="closeInviteModal" class="px-4 py-2 border rounded-lg">{{ t("common.cancel") }}</button>
            <button @click="generateInvite" :disabled="!inviteCompanyId || inviteLoading"
              class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50">
              {{ inviteLoading ? t('projects.generating') : t('projects.generateLink') }}
            </button>
          </div>
        </div>

        <div v-else class="space-y-4">
          <p class="text-sm text-gray-600">{{ t("projects.shareLink") }} <strong>{{ t("projects.expires") }}</strong></p>
          <div class="bg-gray-50 border rounded-lg p-3 break-all text-sm text-gray-800 font-mono">{{ inviteUrl }}</div>
          <div class="flex gap-3">
            <button @click="copyInviteUrl"
              class="flex-1 border border-indigo-600 text-indigo-600 py-2 rounded-lg hover:bg-indigo-50">
              {{ copied ? t('projects.copied') : t('projects.copyLink') }}
            </button>
            <button @click="closeInviteModal"
              class="flex-1 bg-indigo-600 text-white py-2 rounded-lg hover:bg-indigo-700">
              {{ t("projects.done") }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from "vue"
import { useI18n } from "vue-i18n"
import api from "../api"
import { useAuthStore } from "../stores/auth"
import StatusBadge from "../components/StatusBadge.vue"
import ProgressMeter from "../components/ProgressMeter.vue"

const { t } = useI18n()
const auth = useAuthStore()

const projects = ref([])
const companies = ref([])
const showModal = ref(false)
const showInviteModal = ref(false)
// ヒアリング項目。API の Project::BRIEF_FIELDS / BUDGET_BANDS と揃える。
const BRIEF_TEXT_FIELDS = ["business", "goals", "audience", "references", "competitors", "must_haves"]
const BUDGET_BANDS = ["under_2500", "2500_5000", "5000_10000", "10000_plus", "undecided"]
const blankBrief = () => ({ business: "", goals: "", audience: "", budget: "", deadline: "", references: "", competitors: "", must_haves: "" })
const form = ref({ title: "", company_id: "", description: "", staging_url: "", production_url: "", brief: blankBrief() })
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
    const c = p.company?.name || t("common.none")
    if (!g[c]) g[c] = []
    g[c].push(p)
  }
  return g
})

async function loadCompanies() {
  try {
    // /companies は開発者専用。顧客も案件を作れるので、自分が作れる会社はこちらで引く。
    const res = await api.get("/me/companies")
    companies.value = res.data?.data || res.data || []
    // 所属が1社だけの顧客に会社を選ばせる意味は無いので、はじめから入れておく。
    if (companies.value.length === 1) form.value.company_id = companies.value[0].id
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
    // 空欄は送らない（brief が空なら null、url の空文字は検証で落ちる）。
    const brief = Object.fromEntries(Object.entries(form.value.brief).filter(([, v]) => v))
    const payload = Object.fromEntries(Object.entries({ ...form.value, brief: Object.keys(brief).length ? brief : null })
      .filter(([, v]) => v !== ""))
    await api.post("/projects", payload)
    showModal.value = false
    await loadProjects()
    form.value = { title: "", company_id: companies.value.length === 1 ? companies.value[0].id : "", description: "", staging_url: "", production_url: "", brief: blankBrief() }
  } catch (e) {
    projectError.value = e.response?.data?.message || t("projects.createFailed")
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
    inviteError.value = e.response?.data?.message || t("projects.inviteFailed")
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
