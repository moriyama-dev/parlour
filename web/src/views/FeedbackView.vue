<template>
  <div class="max-w-5xl mx-auto px-4 py-8">
    <h1 class="font-serif text-2xl font-semibold text-ink">{{ t('feedback.title') }}</h1>
    <p class="text-sm text-gray-500 mt-1 mb-6 max-w-2xl">{{ auth.isClient ? t('feedback.leadClient') : t('feedback.leadDeveloper') }}</p>

    <!-- 顧客：推薦文と紹介の2つのフォーム -->
    <div v-if="auth.isClient" class="grid gap-6 md:grid-cols-2 items-start mb-8">
      <section class="bg-white rounded-2xl shadow p-5">
        <h2 class="font-semibold text-ink">{{ t('feedback.testimonialTitle') }}</h2>
        <p class="text-xs text-gray-500 mt-1 mb-4">{{ t('feedback.testimonialLead') }}</p>
        <form @submit.prevent="sendTestimonial" class="space-y-3">
          <select v-if="companies.length > 1" v-model="tForm.company_id" class="w-full border rounded-lg px-3 py-2 text-sm">
            <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
          <div>
            <div class="text-sm text-gray-600 mb-1">{{ t('feedback.rating') }}</div>
            <div class="flex gap-1" role="radiogroup">
              <button v-for="n in 5" :key="n" type="button" @click="tForm.rating = n" role="radio" :aria-checked="tForm.rating === n" :aria-label="`${n}`"
                :class="['text-2xl leading-none cursor-pointer', tForm.rating >= n ? 'text-gold' : 'text-gray-300 hover:text-gold-soft']">★</button>
            </div>
          </div>
          <textarea v-model="tForm.body" rows="4" required maxlength="3000" :placeholder="t('feedback.bodyPlaceholder')"
            class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
          <select v-model="tForm.project_id" class="w-full border rounded-lg px-3 py-2 text-sm">
            <option :value="null">{{ t('feedback.project') }}</option>
            <option v-for="p in companyProjects" :key="p.id" :value="p.id">{{ p.title }}</option>
          </select>
          <input v-model="tForm.display_name" maxlength="255" :placeholder="t('feedback.displayNamePlaceholder')" :aria-label="t('feedback.displayName')"
            class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          <label class="flex items-center gap-2 text-sm text-gray-600">
            <input v-model="tForm.allow_publish" type="checkbox" class="accent-indigo-600" /> {{ t('feedback.allowPublish') }}
          </label>
          <p v-if="tMessage" :class="['text-sm', tOk ? 'text-green-600' : 'text-red-500']">{{ tMessage }}</p>
          <button type="submit" :disabled="busy" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-indigo-700 disabled:opacity-60 cursor-pointer">{{ t('feedback.submitTestimonial') }}</button>
        </form>
      </section>

      <section class="bg-white rounded-2xl shadow p-5">
        <h2 class="font-semibold text-ink">{{ t('feedback.referralTitle') }}</h2>
        <p class="text-xs text-gray-500 mt-1 mb-4">{{ t('feedback.referralLead') }}</p>
        <form @submit.prevent="sendReferral" class="space-y-3">
          <select v-if="companies.length > 1" v-model="rForm.company_id" class="w-full border rounded-lg px-3 py-2 text-sm">
            <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
          <input v-model="rForm.referred_name" required maxlength="255" :placeholder="t('feedback.referredName')"
            class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          <input v-model="rForm.contact_name" maxlength="255" :placeholder="t('feedback.contactName')"
            class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          <div class="grid grid-cols-2 gap-2">
            <input v-model="rForm.contact_email" type="email" maxlength="255" :placeholder="t('feedback.contactEmail')"
              class="border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
            <input v-model="rForm.contact_phone" maxlength="50" :placeholder="t('feedback.contactPhone')"
              class="border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          </div>
          <textarea v-model="rForm.note" rows="2" maxlength="2000" :placeholder="t('feedback.note')"
            class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
          <p v-if="rMessage" :class="['text-sm', rOk ? 'text-green-600' : 'text-red-500']">{{ rMessage }}</p>
          <button type="submit" :disabled="busy" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-indigo-700 disabled:opacity-60 cursor-pointer">{{ t('feedback.submitReferral') }}</button>
        </form>
      </section>
    </div>

    <!-- 一覧（開発者は全社、顧客は自社が出したもの） -->
    <div class="grid gap-6 md:grid-cols-2 items-start">
      <section>
        <h2 class="text-sm font-semibold text-gray-500 mb-2">{{ auth.isClient ? t('feedback.yourTestimonials') : t('feedback.listTestimonials') }}</h2>
        <div v-if="testimonials.length === 0" class="text-sm text-gray-400 bg-white rounded-xl border border-dashed border-mist px-4 py-3">{{ t('feedback.none') }}</div>
        <div class="space-y-2">
          <article v-for="x in testimonials" :key="x.id" class="bg-white rounded-xl shadow-sm px-4 py-3">
            <div class="flex items-center gap-2 text-xs text-gray-400">
              <span class="text-gold text-sm">{{ '★'.repeat(x.rating) }}</span>
              <span>{{ x.company?.name }}<template v-if="x.project"> · {{ x.project.title }}</template></span>
              <span :class="['ml-auto px-2 py-0.5 rounded-full', x.allow_publish ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-500']">
                {{ x.allow_publish ? t('feedback.published') : t('feedback.private') }}
              </span>
            </div>
            <p class="text-sm text-gray-700 whitespace-pre-wrap break-words mt-1">{{ x.body }}</p>
            <div class="text-xs text-gray-400 mt-1">— {{ x.display_name || x.user?.name }} · {{ formatDate(x.created_at) }}</div>
          </article>
        </div>
      </section>

      <section>
        <h2 class="text-sm font-semibold text-gray-500 mb-2">{{ auth.isClient ? t('feedback.yourReferrals') : t('feedback.listReferrals') }}</h2>
        <div v-if="referrals.length === 0" class="text-sm text-gray-400 bg-white rounded-xl border border-dashed border-mist px-4 py-3">{{ t('feedback.none') }}</div>
        <div class="space-y-2">
          <article v-for="x in referrals" :key="x.id" class="bg-white rounded-xl shadow-sm px-4 py-3">
            <div class="flex items-start gap-2">
              <div class="min-w-0">
                <div class="font-medium text-ink">{{ x.referred_name }}</div>
                <div class="text-xs text-gray-500 break-words">
                  {{ [x.contact_name, x.contact_email, x.contact_phone].filter(Boolean).join(' · ') }}
                </div>
              </div>
              <select v-if="auth.isDeveloper" :value="x.status" @change="setStatus(x, $event.target.value)"
                class="ml-auto border rounded-lg px-2 py-1 text-xs">
                <option v-for="s in REFERRAL_STATUSES" :key="s" :value="s">{{ t(`feedback.referralStatus.${s}`) }}</option>
              </select>
              <span v-else class="ml-auto text-xs px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-700">{{ t(`feedback.referralStatus.${x.status}`) }}</span>
            </div>
            <p v-if="x.note" class="text-sm text-gray-600 whitespace-pre-wrap break-words mt-1">{{ x.note }}</p>
            <div class="text-xs text-gray-400 mt-1">{{ x.company?.name }} · {{ x.user?.name }} · {{ formatDate(x.created_at) }}</div>
          </article>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../api'
import { useAuthStore } from '../stores/auth'
import { formatDate } from '../services/requests'

const REFERRAL_STATUSES = ['new', 'contacted', 'won', 'lost']

const { t } = useI18n()
const auth = useAuthStore()

const companies = ref([])
const projects = ref([])
const testimonials = ref([])
const referrals = ref([])
const busy = ref(false)
const tMessage = ref('')
const tOk = ref(false)
const rMessage = ref('')
const rOk = ref(false)

const blankT = () => ({ company_id: companies.value[0]?.id ?? null, rating: 5, body: '', project_id: null, display_name: '', allow_publish: true })
const blankR = () => ({ company_id: companies.value[0]?.id ?? null, referred_name: '', contact_name: '', contact_email: '', contact_phone: '', note: '' })
const tForm = ref(blankT())
const rForm = ref(blankR())

const companyProjects = computed(() => projects.value.filter((p) => Number(p.company_id) === Number(tForm.value.company_id)))

async function loadLists() {
  const [tRes, rRes] = await Promise.all([api.get('/testimonials'), api.get('/referrals')])
  testimonials.value = tRes.data?.data || []
  referrals.value = rRes.data?.data || []
}

const nullify = (o) => Object.fromEntries(Object.entries(o).map(([k, v]) => [k, v === '' ? null : v]))

async function sendTestimonial() {
  busy.value = true
  tMessage.value = ''
  try {
    await api.post('/testimonials', nullify(tForm.value))
    tOk.value = true
    tMessage.value = t('feedback.testimonialThanks')
    tForm.value = blankT()
    await loadLists()
  } catch {
    tOk.value = false
    tMessage.value = t('feedback.failed')
  } finally {
    busy.value = false
  }
}

async function sendReferral() {
  busy.value = true
  rMessage.value = ''
  try {
    await api.post('/referrals', nullify(rForm.value))
    rOk.value = true
    rMessage.value = t('feedback.referralThanks')
    rForm.value = blankR()
    await loadLists()
  } catch {
    rOk.value = false
    rMessage.value = t('feedback.failed')
  } finally {
    busy.value = false
  }
}

async function setStatus(referral, status) {
  try {
    const res = await api.patch(`/referrals/${referral.id}`, { status })
    Object.assign(referral, res.data.data)
  } catch {
    await loadLists()
  }
}

onMounted(async () => {
  try {
    if (auth.isClient) {
      const [cRes, pRes] = await Promise.all([api.get('/me/companies'), api.get('/projects')])
      companies.value = cRes.data?.data || []
      projects.value = pRes.data?.data || []
      tForm.value = blankT()
      rForm.value = blankR()
    }
    await loadLists()
  } catch {}
})
</script>
