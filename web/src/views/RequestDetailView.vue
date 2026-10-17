<template>
  <div class="max-w-5xl mx-auto px-4 py-8">
    <router-link :to="backLink" class="text-sm text-indigo-600 hover:underline">← {{ t('requests.back') }}</router-link>

    <div v-if="notFound" class="text-center text-gray-400 py-16">{{ t('requests.notFound') }}</div>
    <div v-else-if="!r" class="text-gray-400 text-sm py-10 text-center">{{ t('common.loading') }}</div>

    <template v-else>
      <div class="mt-3 mb-6">
        <div class="flex flex-wrap items-center gap-2">
          <span class="text-sm text-gray-400 tabular-nums">#{{ r.id }}</span>
          <RequestBadge kind="status" :value="r.status" />
          <RequestBadge kind="category" :value="r.category" />
          <RequestBadge v-if="r.priority !== 'normal'" kind="priority" :value="r.priority" />
          <span v-if="r.is_overdue" class="text-xs font-medium text-red-600">{{ t('requests.overdueTag') }}</span>
        </div>
        <h1 class="font-serif text-2xl font-semibold text-ink mt-2 break-words">{{ r.title }}</h1>
        <div class="text-sm text-gray-500 mt-1 flex flex-wrap gap-x-4 gap-y-1">
          <span>{{ r.company?.name }}</span>
          <router-link v-if="r.project" :to="`/projects/${r.project.id}`" class="text-indigo-600 hover:underline">{{ r.project.title }}</router-link>
          <span v-if="r.creator">{{ t('requests.createdBy', { name: r.creator.name }) }} · {{ formatDateTime(r.created_at) }}</span>
        </div>
      </div>

      <p v-if="actionError" class="text-sm text-red-500 mb-4">{{ actionError }}</p>

      <div class="grid gap-6 lg:grid-cols-[1fr_18rem] items-start">
        <div class="space-y-6 min-w-0">
          <!-- 顧客の確認（承認か修正依頼か） -->
          <section v-if="auth.isClient && r.status === 'client_review'" class="bg-yellow-50 border border-yellow-200 rounded-2xl p-5">
            <h2 class="font-semibold text-yellow-900">{{ t('requests.reviewTitle') }}</h2>
            <p class="text-sm text-yellow-800 mt-1">{{ t('requests.reviewLead') }}</p>
            <a v-if="r.delivery_url" :href="r.delivery_url" target="_blank" rel="noopener"
              class="inline-block mt-3 bg-white border border-yellow-300 rounded-lg px-3 py-1.5 text-sm text-ink hover:border-gold">↗ {{ t('requests.viewDelivery') }}</a>

            <div class="mt-4 bg-white rounded-xl p-4 space-y-3">
              <div>
                <div class="text-sm text-gray-600 mb-1">{{ t('requests.ratingLabel') }}</div>
                <div class="flex gap-1" role="radiogroup">
                  <button v-for="n in 5" :key="n" type="button" @click="rating = rating === n ? null : n"
                    :aria-checked="rating === n" role="radio" :aria-label="`${n}`"
                    :class="['text-2xl leading-none cursor-pointer', (rating || 0) >= n ? 'text-gold' : 'text-gray-300 hover:text-gold-soft']">★</button>
                </div>
              </div>
              <input v-model="approveComment" :placeholder="t('requests.approveComment')"
                class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
              <button @click="approve" :disabled="busy" class="bg-green-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-green-700 disabled:opacity-60 cursor-pointer">✓ {{ t('requests.approve') }}</button>
            </div>

            <div class="mt-3 bg-white rounded-xl p-4 space-y-2">
              <div class="text-sm font-medium text-gray-700">{{ t('requests.requestRevision') }}</div>
              <textarea v-model="revisionText" rows="3" :placeholder="t('requests.revisionPlaceholder')"
                class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
              <input v-model="revisionVideo" type="url" :placeholder="t('requests.videoUrl')"
                class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
              <button @click="requestRevision" :disabled="busy || !revisionText.trim()"
                class="border border-orange-500 text-orange-700 px-4 py-2 rounded-lg text-sm hover:bg-orange-50 disabled:opacity-50 cursor-pointer">↺ {{ t('requests.revisionSubmit') }}</button>
            </div>
          </section>

          <!-- 承認直後：推薦文のお願い（Conte は納品のたびに回収する） -->
          <section v-if="justApproved" class="bg-green-50 border border-green-200 rounded-2xl p-5 text-sm text-green-900">
            {{ t('requests.afterApprove') }}
            <router-link to="/feedback" class="ml-2 font-medium text-green-800 underline">{{ t('requests.writeTestimonial') }}</router-link>
          </section>

          <section class="bg-white rounded-2xl shadow p-5">
            <p v-if="r.description" class="text-sm text-gray-700 whitespace-pre-wrap break-words">{{ r.description }}</p>
            <p v-else class="text-sm text-gray-400">{{ t('requests.noDescription') }}</p>
            <div v-if="r.reference_url || r.video_url" class="flex flex-wrap gap-3 mt-4 text-sm">
              <a v-if="r.video_url" :href="r.video_url" target="_blank" rel="noopener" class="text-indigo-600 hover:underline">🎥 {{ t('requests.video') }}</a>
              <a v-if="r.reference_url" :href="r.reference_url" target="_blank" rel="noopener" class="text-indigo-600 hover:underline break-all">🔗 {{ t('requests.reference') }}</a>
            </div>
            <div v-if="auth.isClient && r.status === 'queued'" class="flex gap-3 mt-4 pt-4 border-t border-mist">
              <button @click="showEdit = true" class="text-sm text-indigo-600 hover:underline cursor-pointer">{{ t('requests.edit') }}</button>
              <button @click="cancel" class="text-sm text-gray-400 hover:text-red-500 cursor-pointer">{{ t('requests.cancel') }}</button>
            </div>
          </section>

          <!-- 作業の内訳（開発者が書き、顧客は進み具合として見る） -->
          <section v-if="auth.isDeveloper || checklist.length" class="bg-white rounded-2xl shadow p-5">
            <h2 class="font-semibold text-gray-700 mb-3">{{ t('requests.checklist') }}
              <span v-if="checklist.length" class="text-xs text-gray-400 font-normal ml-1">{{ checklist.filter(i => i.done).length }} / {{ checklist.length }}</span>
            </h2>
            <p v-if="!checklist.length" class="text-sm text-gray-400">{{ t('requests.checklistEmpty') }}</p>
            <ul class="space-y-1.5">
              <li v-for="(item, i) in checklist" :key="i" class="flex items-center gap-2 text-sm">
                <input type="checkbox" :checked="item.done" :disabled="!auth.isDeveloper" @change="toggleItem(i)" class="accent-indigo-600" />
                <span :class="item.done ? 'line-through text-gray-400' : 'text-gray-700'">{{ item.text }}</span>
                <button v-if="auth.isDeveloper" @click="removeItem(i)" class="ml-auto text-xs text-gray-300 hover:text-red-500 cursor-pointer">✕</button>
              </li>
            </ul>
            <form v-if="auth.isDeveloper" @submit.prevent="addItem" class="flex gap-2 mt-3">
              <input v-model="newItem" :placeholder="t('requests.itemPlaceholder')" maxlength="500"
                class="flex-1 border rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
              <button type="submit" class="text-sm text-indigo-600 hover:underline cursor-pointer">{{ t('requests.addItem') }}</button>
            </form>
          </section>

          <!-- 納品 -->
          <section v-if="r.delivery_note" class="bg-white rounded-2xl shadow p-5">
            <h2 class="font-semibold text-gray-700 mb-2">{{ t('requests.delivery') }} <span class="text-xs text-gray-400 font-normal">{{ formatDateTime(r.delivered_at) }}</span></h2>
            <p class="text-sm text-gray-700 whitespace-pre-wrap break-words">{{ r.delivery_note }}</p>
            <a v-if="r.delivery_url" :href="r.delivery_url" target="_blank" rel="noopener" class="inline-block mt-2 text-sm text-indigo-600 hover:underline break-all">↗ {{ r.delivery_url }}</a>
          </section>

          <!-- やり取りと履歴 -->
          <section class="bg-white rounded-2xl shadow p-5">
            <h2 class="font-semibold text-gray-700 mb-4">{{ t('requests.activity') }}</h2>
            <ol class="space-y-4">
              <li v-for="e in r.events" :key="e.id" class="flex gap-3">
                <div :class="['w-8 h-8 rounded-full shrink-0 flex items-center justify-center text-xs font-bold',
                  e.internal ? 'bg-purple-100 text-purple-700' : e.type === 'comment' ? 'bg-indigo-100 text-indigo-600' : 'bg-gray-100 text-gray-400']">
                  {{ eventIcon(e) }}
                </div>
                <div class="min-w-0 flex-1">
                  <div class="text-xs text-gray-400 flex flex-wrap gap-x-2">
                    <span class="font-medium text-gray-600">{{ e.type === 'comment' || e.type === 'note' ? (e.user?.name || t('requests.someone')) : eventLabel(e) }}</span>
                    <span>{{ formatDateTime(e.created_at) }}</span>
                    <span v-if="e.internal" class="text-purple-600">{{ t('requests.internalTag') }}</span>
                    <span v-if="e.meta?.rating" class="text-gold">{{ t('requests.rated', { n: e.meta.rating }) }}</span>
                  </div>
                  <p v-if="e.body" class="text-sm text-gray-700 whitespace-pre-wrap break-words mt-0.5">{{ e.body }}</p>
                  <a v-if="e.meta?.video_url" :href="e.meta.video_url" target="_blank" rel="noopener" class="text-xs text-indigo-600 hover:underline">🎥 {{ t('requests.video') }}</a>
                </div>
              </li>
            </ol>

            <form @submit.prevent="sendComment" class="mt-5 border-t border-mist pt-4 space-y-2">
              <textarea v-model="comment" rows="2" :placeholder="t('requests.commentPlaceholder')"
                class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
              <div class="flex flex-wrap items-center gap-3">
                <input v-model="commentVideo" type="url" :placeholder="t('requests.videoUrl')"
                  class="flex-1 min-w-[12rem] border rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                <label v-if="auth.isDeveloper" class="text-xs text-gray-500 flex items-center gap-1.5">
                  <input v-model="internal" type="checkbox" class="accent-purple-600" /> {{ t('requests.internal') }}
                </label>
                <button type="submit" :disabled="busy || !comment.trim()"
                  class="bg-indigo-600 text-white px-4 py-1.5 rounded-lg text-sm hover:bg-indigo-700 disabled:opacity-50 cursor-pointer">{{ t('requests.send') }}</button>
              </div>
            </form>
          </section>
        </div>

        <!-- 右列：状況と操作 -->
        <aside class="space-y-4">
          <section class="bg-white rounded-2xl shadow p-4 text-sm space-y-2">
            <div v-if="r.status === 'queued' && r.queue_ahead !== undefined" class="font-medium text-ink">
              {{ r.queue_ahead === 0 ? t('requests.nextUp') : t('requests.queueAhead', { n: r.queue_ahead }) }}
            </div>
            <div v-if="r.due_at" :class="r.is_overdue ? 'text-red-600' : 'text-gray-600'">{{ t('requests.dueAt', { date: formatDateTime(r.due_at) }) }}</div>
            <div v-if="r.requested_due_on" class="text-gray-600">{{ t('requests.requestedDueOn', { date: formatDate(r.requested_due_on) }) }}</div>
            <div v-if="r.revision_count" class="text-gray-600">{{ t('requests.revisions', { n: r.revision_count }) }}</div>
            <div v-if="r.assignee" class="text-gray-600">{{ t('requests.assignedTo', { name: r.assignee.name }) }}</div>
            <div v-if="r.estimate_hours" class="text-gray-600">{{ t('requests.estimate', { h: r.estimate_hours }) }}</div>
            <div v-if="r.spent_minutes" class="text-gray-600">{{ t('requests.spent', { h: hours(r.spent_minutes) }) }}</div>
            <div v-if="r.rating" class="text-gold">{{ '★'.repeat(r.rating) }}<span class="text-gray-300">{{ '★'.repeat(5 - r.rating) }}</span></div>
          </section>

          <section v-if="auth.isDeveloper && r.status !== 'done' && r.status !== 'cancelled'" class="bg-white rounded-2xl shadow p-4 space-y-3">
            <h2 class="text-sm font-semibold text-gray-700">{{ t('requests.actions') }}</h2>

            <template v-if="r.status === 'queued' || r.status === 'on_hold'">
              <button @click="start(false)" :disabled="busy" class="w-full bg-ink text-white px-3 py-2 rounded-lg text-sm hover:opacity-90 cursor-pointer">▶ {{ t('requests.start') }}</button>
              <button v-if="blockedByLimit" @click="start(true)" :disabled="busy" class="w-full border border-orange-400 text-orange-700 px-3 py-2 rounded-lg text-sm hover:bg-orange-50 cursor-pointer">{{ t('requests.startForce') }}</button>
            </template>

            <form v-if="r.status === 'in_progress' || r.status === 'revision'" @submit.prevent="deliver" class="space-y-2">
              <textarea v-model="deliveryNote" rows="3" required :placeholder="t('requests.deliverNotePlaceholder')"
                class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
              <input v-model="deliveryUrl" type="url" :placeholder="t('requests.deliveryUrl')"
                class="w-full border rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
              <button type="submit" :disabled="busy" class="w-full bg-green-600 text-white px-3 py-2 rounded-lg text-sm hover:bg-green-700 cursor-pointer">✓ {{ t('requests.deliver') }}</button>
            </form>

            <div class="flex gap-3 text-sm">
              <button v-if="['queued', 'in_progress', 'revision'].includes(r.status)" @click="hold" :disabled="busy" class="text-gray-500 hover:text-purple-700 cursor-pointer">{{ t('requests.hold') }}</button>
              <button @click="cancel" :disabled="busy" class="text-gray-400 hover:text-red-500 cursor-pointer">{{ t('requests.cancel') }}</button>
            </div>

            <form @submit.prevent="saveDetails" class="border-t border-mist pt-3 space-y-2">
              <label class="block text-xs text-gray-500">{{ t('requests.estimateLabel') }}
                <input v-model="estimate" type="number" min="0" max="999" step="0.25" class="mt-1 w-full border rounded-lg px-2 py-1.5 text-sm text-ink" />
              </label>
              <label class="block text-xs text-gray-500">{{ t('requests.assignee') }}
                <select v-model="assigneeId" class="mt-1 w-full border rounded-lg px-2 py-1.5 text-sm text-ink">
                  <option :value="null">{{ t('requests.unassigned') }}</option>
                  <option v-if="r.assignee && r.assignee.id !== auth.user?.id" :value="r.assignee.id">{{ r.assignee.name }}</option>
                  <option :value="auth.user?.id">{{ auth.user?.name }}</option>
                </select>
              </label>
              <button type="submit" :disabled="busy" class="text-sm text-indigo-600 hover:underline cursor-pointer">{{ t('requests.saveDetails') }}</button>
            </form>

            <form @submit.prevent="logTime" class="border-t border-mist pt-3 space-y-2">
              <div class="text-xs text-gray-500">{{ t('requests.logTime') }}</div>
              <div class="flex gap-2 items-center">
                <input v-model.number="minutes" type="number" min="1" max="1440" required class="w-20 border rounded-lg px-2 py-1.5 text-sm" />
                <span class="text-xs text-gray-500">{{ t('requests.minutes') }}</span>
                <button type="submit" :disabled="busy" class="ml-auto text-sm text-indigo-600 hover:underline cursor-pointer">{{ t('requests.record') }}</button>
              </div>
              <input v-model="timeNote" :placeholder="t('requests.timeNote')" class="w-full border rounded-lg px-2 py-1.5 text-sm" />
            </form>
          </section>
        </aside>
      </div>
    </template>

    <RequestFormModal v-if="showEdit && r" :initial="r" :companies="[r.company]" @close="showEdit = false" @saved="onEdited" />
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '../api'
import { useAuthStore } from '../stores/auth'
import RequestBadge from '../components/RequestBadge.vue'
import RequestFormModal from '../components/RequestFormModal.vue'
import { formatDate, formatDateTime, hours } from '../services/requests'

const { t } = useI18n()
const auth = useAuthStore()
const route = useRoute()

const r = ref(null)
const notFound = ref(false)
const busy = ref(false)
const actionError = ref('')
const blockedByLimit = ref(false)
const justApproved = ref(false)
const showEdit = ref(false)

const rating = ref(null)
const approveComment = ref('')
const revisionText = ref('')
const revisionVideo = ref('')
const comment = ref('')
const commentVideo = ref('')
const internal = ref(false)
const deliveryNote = ref('')
const deliveryUrl = ref('')
const estimate = ref('')
const assigneeId = ref(null)
const minutes = ref(30)
const timeNote = ref('')
const newItem = ref('')

const checklist = computed(() => r.value?.checklist || [])
const backLink = computed(() => (r.value ? `/requests?company=${r.value.company_id}` : '/requests'))

function eventLabel(e) {
  const name = e.user?.name || t('requests.someone')
  return t(`requests.events.${e.type}`, { name, m: e.minutes })
}

function eventIcon(e) {
  return { created: '＋', started: '▶', delivered: '✓', revision_requested: '↺', approved: '★', on_hold: '‖', cancelled: '✕', time: '⏱', note: '✎' }[e.type]
    || (e.user?.name || '?').slice(0, 1).toUpperCase()
}

function setRequest(data) {
  r.value = data
  estimate.value = data.estimate_hours ?? ''
  assigneeId.value = data.assignee?.id ?? null
}

async function load() {
  try {
    const res = await api.get(`/requests/${route.params.id}`)
    setRequest(res.data.data)
    notFound.value = false
  } catch (e) {
    if (e.response?.status === 404) notFound.value = true
    else actionError.value = t('requests.loadFailed')
  }
}

async function run(fn) {
  busy.value = true
  actionError.value = ''
  try {
    await fn()
  } catch (e) {
    actionError.value = e.response?.data?.message || t('requests.actionFailed')
    if (e.response?.data?.reason === 'limit') blockedByLimit.value = true
  } finally {
    busy.value = false
  }
}

const post = async (path, body = {}) => setRequest((await api.post(`/requests/${r.value.id}/${path}`, body)).data.data)

const start = (force) => run(async () => {
  await post('start', { force })
  blockedByLimit.value = false
})

const deliver = () => run(async () => {
  await post('deliver', { delivery_note: deliveryNote.value, delivery_url: deliveryUrl.value || null })
  deliveryNote.value = ''
  deliveryUrl.value = ''
})

const approve = () => run(async () => {
  await post('approve', { rating: rating.value, comment: approveComment.value || null })
  justApproved.value = true
})

const requestRevision = () => run(async () => {
  await post('revision', { comment: revisionText.value, video_url: revisionVideo.value || null })
  revisionText.value = ''
  revisionVideo.value = ''
})

const hold = () => run(async () => {
  const reason = window.prompt(t('requests.holdPrompt'), '')
  if (reason === null) return
  await post('hold', { reason: reason || null })
})

const cancel = () => run(async () => {
  if (!window.confirm(t('requests.cancelConfirm'))) return
  await post('cancel')
})

const sendComment = () => run(async () => {
  await post('comments', { body: comment.value, video_url: commentVideo.value || null, internal: internal.value })
  comment.value = ''
  commentVideo.value = ''
  internal.value = false
})

const logTime = () => run(async () => {
  await post('time', { minutes: minutes.value, note: timeNote.value || null })
  timeNote.value = ''
})

const saveDetails = () => run(async () => {
  const res = await api.put(`/requests/${r.value.id}`, {
    estimate_hours: estimate.value === '' ? null : Number(estimate.value),
    assignee_id: assigneeId.value,
  })
  setRequest(res.data.data)
})

const saveChecklist = (items) => run(async () => {
  const res = await api.put(`/requests/${r.value.id}`, { checklist: items })
  setRequest(res.data.data)
})

function toggleItem(i) {
  saveChecklist(checklist.value.map((item, j) => (j === i ? { ...item, done: !item.done } : item)))
}

function removeItem(i) {
  saveChecklist(checklist.value.filter((_, j) => j !== i))
}

function addItem() {
  const text = newItem.value.trim()
  if (!text) return
  saveChecklist([...checklist.value, { text, done: false }])
  newItem.value = ''
}

function onEdited(data) {
  showEdit.value = false
  setRequest({ ...r.value, ...data })
  load()
}

// 通知から別の依頼へ移ったとき（同じ画面のまま id だけ変わる）。
watch(() => route.params.id, (id) => { if (id) { r.value = null; justApproved.value = false; load() } })

onMounted(load)
</script>
