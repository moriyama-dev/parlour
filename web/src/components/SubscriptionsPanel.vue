<template>
  <!--
    「料金」ページのサブスクタブ。開発者と顧客で同じ部品を使い、出す操作だけ変える。
      開発者: 会社ごとに契約を作る・金額や周期を変える・解約する
      顧客  : 自社の契約の状態と次回の請求日を見る・カードを登録して開始する・カードを変える
    課金そのものは Stripe が行い、状態は Webhook でサーバーに届いたものを表示しているだけ。
  -->
  <div>
    <div v-if="notice" class="mb-6 rounded-xl px-4 py-3 text-sm" :class="notice.ok ? 'bg-green-50 text-green-800' : 'bg-amber-50 text-amber-800'">
      {{ notice.text }}
    </div>

    <div v-if="loading" class="text-sm text-gray-400 py-12 text-center">{{ t('common.loading') }}</div>
    <p v-else-if="error" class="text-sm text-red-500 py-6">{{ error }}</p>

    <template v-else>
      <!-- 集計 -->
      <div class="grid gap-4 sm:grid-cols-3 mb-6">
        <div v-for="card in summary" :key="card.key" class="rounded-2xl border border-mist bg-white shadow-sm px-5 py-4">
          <div class="text-xs text-gray-500 mb-1">{{ t('subscriptions.' + card.key) }}</div>
          <div class="font-serif text-2xl font-semibold text-ink tabular-nums">{{ card.value }}</div>
        </div>
      </div>

      <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <p class="text-sm text-gray-500">{{ isDeveloper ? t('subscriptions.leadDeveloper') : t('subscriptions.leadClient') }}</p>
        <button v-if="isDeveloper && !form" @click="openCreate"
          class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700 cursor-pointer">
          {{ t('subscriptions.new') }}
        </button>
      </div>

      <p v-if="!stripeEnabled" class="mb-4 text-xs text-gray-500">{{ t('subscriptions.stripeDisabled') }}</p>

      <!-- 作成・編集フォーム（開発者） -->
      <form v-if="form" @submit.prevent="save" class="rounded-2xl border border-indigo-200 bg-white shadow-sm p-5 mb-6 space-y-4">
        <h3 class="font-serif text-base font-semibold text-ink">
          {{ form.id ? t('subscriptions.editTitle') : t('subscriptions.newTitle') }}
        </h3>
        <div class="grid gap-4 sm:grid-cols-2">
          <label v-if="!form.id" class="block text-sm sm:col-span-2">
            <span class="text-gray-600">{{ t('subscriptions.company') }}</span>
            <select v-model="form.company_id" required class="mt-1 w-full border border-gray-300 rounded-lg px-3 py-2">
              <option value="" disabled>{{ t('projects.selectCompany') }}</option>
              <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </label>
          <label class="block text-sm sm:col-span-2">
            <span class="text-gray-600">{{ t('subscriptions.name') }}</span>
            <input v-model="form.name" required maxlength="120" :placeholder="t('subscriptions.namePlaceholder')"
              class="mt-1 w-full border border-gray-300 rounded-lg px-3 py-2" />
          </label>
          <label class="block text-sm">
            <span class="text-gray-600">{{ t('subscriptions.amount') }}</span>
            <div class="mt-1 flex items-center gap-2">
              <input v-model="form.amount" type="number" min="0.5" max="100000" step="0.01" required
                class="w-full border border-gray-300 rounded-lg px-3 py-2 tabular-nums" />
              <span class="text-sm text-gray-500 shrink-0">CAD</span>
            </div>
          </label>
          <fieldset class="text-sm">
            <legend class="text-gray-600">{{ t('subscriptions.interval') }}</legend>
            <div class="mt-2 flex gap-4">
              <label v-for="iv in ['month', 'year']" :key="iv" class="flex items-center gap-2 cursor-pointer">
                <input type="radio" v-model="form.interval" :value="iv" />{{ t('subscriptions.intervals.' + iv) }}
              </label>
            </div>
          </fieldset>
          <label class="block text-sm sm:col-span-2">
            <span class="text-gray-600">{{ t('subscriptions.description') }}</span>
            <textarea v-model="form.description" rows="2" maxlength="1000"
              class="mt-1 w-full border border-gray-300 rounded-lg px-3 py-2"></textarea>
          </label>
        </div>
        <p v-if="form.id && form.live" class="text-xs text-amber-700">{{ t('subscriptions.priceChangeNote') }}</p>
        <p v-if="formError" class="text-sm text-red-500">{{ formError }}</p>
        <div class="flex gap-2">
          <button type="submit" :disabled="busy"
            class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700 disabled:opacity-50 cursor-pointer">
            {{ form.id ? t('subscriptions.saveChanges') : t('subscriptions.create') }}
          </button>
          <button type="button" @click="form = null" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800 cursor-pointer">
            {{ t('common.cancel') }}
          </button>
        </div>
      </form>

      <div v-if="subscriptions.length === 0" class="py-16 text-center text-gray-400">
        <p class="font-medium text-ink">{{ t('subscriptions.empty') }}</p>
        <p class="text-sm mt-1">{{ isDeveloper ? t('subscriptions.emptyHintDeveloper') : t('subscriptions.emptyHintClient') }}</p>
      </div>

      <!-- 契約ごとのカード -->
      <div class="space-y-4">
        <article v-for="s in subscriptions" :key="s.id"
          class="rounded-2xl border bg-white shadow-sm"
          :class="needsAttention(s) ? 'border-red-300' : 'border-mist'">
          <div class="p-5 flex flex-wrap gap-4 justify-between">
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2 mb-1">
                <h3 class="font-serif text-base font-semibold text-ink">{{ s.name }}</h3>
                <span :class="badgeClass(s)">{{ statusLabel(s) }}</span>
              </div>
              <div v-if="isDeveloper && s.company" class="text-sm text-gray-500">{{ s.company.name }}</div>
              <p v-if="s.description" class="text-sm text-gray-600 mt-2 whitespace-pre-line">{{ s.description }}</p>
            </div>
            <div class="text-right">
              <div class="font-serif text-2xl font-semibold text-ink tabular-nums">{{ money(s.amount, s.currency) }}</div>
              <div class="text-sm text-gray-500">/ {{ t('subscriptions.per.' + s.interval) }}</div>
            </div>
          </div>

          <div class="px-5 pb-4 text-sm text-gray-600 space-y-1">
            <p v-if="s.status === 'canceled'">{{ t('subscriptions.endedOn', { date: formatDate(s.canceled_at) }) }}</p>
            <p v-else-if="s.cancel_at_period_end">{{ t('subscriptions.endsOn', { date: formatDate(s.current_period_end) }) }}</p>
            <p v-else-if="s.current_period_end">{{ t('subscriptions.nextCharge', { date: formatDate(s.current_period_end) }) }}</p>
            <p v-if="s.started_at" class="text-xs text-gray-400">{{ t('subscriptions.startedOn', { date: formatDate(s.started_at) }) }}</p>
            <p v-if="needsAttention(s)" class="text-red-600">
              {{ isDeveloper ? t('subscriptions.attentionDeveloper') : t('subscriptions.attentionClient') }}
            </p>
            <p v-if="s.awaits_checkout && isDeveloper" class="text-amber-700">{{ t('subscriptions.awaitingClient') }}</p>
          </div>

          <!-- 操作 -->
          <div class="px-5 pb-5 flex flex-wrap gap-2">
            <template v-if="!isDeveloper">
              <button v-if="s.awaits_checkout" @click="startCheckout(s)" :disabled="busy || !stripeEnabled"
                class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700 disabled:opacity-50 cursor-pointer">
                {{ t('subscriptions.startCheckout') }}
              </button>
              <button v-else-if="s.is_live" @click="openPortal(s.company_id)" :disabled="busy || !stripeEnabled"
                class="px-4 py-2 border border-gray-300 text-sm rounded-lg hover:bg-sand disabled:opacity-50 cursor-pointer">
                {{ t('subscriptions.managePayment') }}
              </button>
            </template>
            <template v-else-if="s.status !== 'canceled'">
              <button @click="openEdit(s)" :class="smallBtn">{{ t('subscriptions.edit') }}</button>
              <button v-if="s.cancel_at_period_end" @click="act(s, 'resume')" :disabled="busy" :class="smallBtn">
                {{ t('subscriptions.resume') }}
              </button>
              <button v-else @click="cancel(s, false)" :disabled="busy" :class="smallBtn">
                {{ s.awaits_checkout ? t('subscriptions.discard') : t('subscriptions.cancelAtEnd') }}
              </button>
              <button v-if="!s.awaits_checkout" @click="cancel(s, true)" :disabled="busy" :class="smallBtn + ' text-red-600'">
                {{ t('subscriptions.cancelNow') }}
              </button>
              <button v-if="!s.awaits_checkout && stripeEnabled" @click="act(s, 'refresh')" :disabled="busy" :class="smallBtn">
                {{ t('subscriptions.refresh') }}
              </button>
            </template>
          </div>

          <!-- 支払い履歴 -->
          <details v-if="s.payments?.length" class="border-t border-mist">
            <summary class="px-5 py-3 text-sm text-indigo-600 cursor-pointer select-none">
              {{ t('subscriptions.history', { count: s.payments.length }) }}
            </summary>
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead class="bg-sand text-gray-500 text-xs">
                  <tr>
                    <th class="text-left font-semibold px-5 py-2">{{ t('subscriptions.colPeriod') }}</th>
                    <th class="text-right font-semibold px-5 py-2">{{ t('subscriptions.colAmount') }}</th>
                    <th class="text-left font-semibold px-5 py-2">{{ t('subscriptions.colStatus') }}</th>
                    <th class="px-5 py-2"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="p in s.payments" :key="p.id" class="border-t border-mist">
                    <td class="px-5 py-2.5 whitespace-nowrap text-gray-600">{{ formatDate(p.period_start) }} – {{ formatDate(p.period_end) }}</td>
                    <td class="px-5 py-2.5 text-right tabular-nums whitespace-nowrap">{{ money(p.status === 'paid' ? p.amount_paid : p.amount_due, p.currency) }}</td>
                    <td class="px-5 py-2.5"><span :class="paymentBadge(p.status)">{{ t('subscriptions.paymentStatus.' + p.status) }}</span></td>
                    <td class="px-5 py-2.5 text-right whitespace-nowrap">
                      <a v-if="p.hosted_invoice_url" :href="p.hosted_invoice_url" target="_blank" rel="noopener" class="text-indigo-600 hover:underline">
                        {{ p.status === 'paid' ? t('subscriptions.viewReceipt') : t('subscriptions.payInvoice') }}
                      </a>
                      <a v-if="p.invoice_pdf" :href="p.invoice_pdf" target="_blank" rel="noopener" class="text-indigo-600 hover:underline ml-3">PDF</a>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </details>
        </article>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '../api'
import { useAuthStore } from '../stores/auth'
import { activeDateLocale } from '../i18n'
import { formatMoney } from '../services/money'

const { t, te } = useI18n()
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const isDeveloper = computed(() => auth.isDeveloper)
const subscriptions = ref([])
const stripeEnabled = ref(false)
const companies = ref([])
const loading = ref(true)
const error = ref('')
const busy = ref(false)
const form = ref(null)
const formError = ref('')
const notice = ref(null)

const smallBtn = 'px-3 py-1.5 border border-gray-300 text-sm rounded-lg hover:bg-sand disabled:opacity-50 cursor-pointer'

// 顧客が「支払い登録」中、まだカードを入れていない・失敗している状態。
const ATTENTION = ['past_due', 'unpaid', 'incomplete']

const summary = computed(() => {
  const live = subscriptions.value.filter((s) => s.is_live)
  if (isDeveloper.value) {
    // 年払いは 12 で割って月額に揃える（いわゆる MRR）。
    const mrr = live.reduce((n, s) => n + (s.interval === 'year' ? s.amount / 12 : s.amount), 0)
    return [
      { key: 'summaryMrr', value: money(mrr, 'CAD') },
      { key: 'summaryActive', value: String(live.length) },
      { key: 'summaryAttention', value: String(subscriptions.value.filter((s) => needsAttention(s) || s.awaits_checkout).length) },
    ]
  }
  const next = live
    .filter((s) => s.current_period_end && !s.cancel_at_period_end)
    .sort((a, b) => new Date(a.current_period_end) - new Date(b.current_period_end))[0]
  return [
    { key: 'summaryActive', value: String(live.length) },
    { key: 'summaryNextCharge', value: next ? formatDate(next.current_period_end) : '—' },
    { key: 'summaryNextAmount', value: next ? money(next.amount, next.currency) : '—' },
  ]
})

function money(amount, currency) {
  return formatMoney(amount, { currency: currency || 'CAD' })
}

function formatDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString(activeDateLocale(), { year: 'numeric', month: 'short', day: 'numeric' })
}

function needsAttention(s) {
  return ATTENTION.includes(s.status)
}

// 表示上の状態。解約予約中は Stripe 上 active だが、利用者には「終了予定」の方が大事。
function displayStatus(s) {
  if (s.status !== 'canceled' && s.cancel_at_period_end) return 'ending'
  return s.status
}

function statusLabel(s) {
  const key = displayStatus(s)
  const k = 'subscriptions.status.' + key
  return te(k) ? t(k) : key
}

const STATUS_COLORS = {
  pending: 'bg-amber-100 text-amber-800',
  incomplete_expired: 'bg-amber-100 text-amber-800',
  active: 'bg-green-100 text-green-800',
  trialing: 'bg-green-100 text-green-800',
  ending: 'bg-gray-100 text-gray-700',
  past_due: 'bg-red-100 text-red-800',
  unpaid: 'bg-red-100 text-red-800',
  incomplete: 'bg-red-100 text-red-800',
  paused: 'bg-gray-100 text-gray-700',
  canceled: 'bg-gray-100 text-gray-500',
}
const badgeBase = 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium'

function badgeClass(s) {
  return badgeBase + ' ' + (STATUS_COLORS[displayStatus(s)] || 'bg-gray-100 text-gray-600')
}

function paymentBadge(status) {
  const colors = { paid: 'bg-green-100 text-green-800', failed: 'bg-red-100 text-red-800', open: 'bg-blue-100 text-blue-800' }
  return badgeBase + ' ' + (colors[status] || 'bg-gray-100 text-gray-600')
}

async function load() {
  try {
    const res = await api.get('/billing/subscriptions')
    subscriptions.value = res.data?.data || []
    stripeEnabled.value = !!res.data?.meta?.stripe_enabled
  } catch {
    error.value = t('subscriptions.loadFailed')
  } finally {
    loading.value = false
  }
}

function replace(updated) {
  const i = subscriptions.value.findIndex((s) => s.id === updated.id)
  if (i === -1) subscriptions.value.unshift(updated)
  else subscriptions.value[i] = updated
}

async function openCreate() {
  formError.value = ''
  form.value = { company_id: '', name: '', description: '', amount: '', interval: 'month' }
  if (companies.value.length === 0) {
    try {
      const res = await api.get('/companies')
      companies.value = res.data?.data || res.data || []
    } catch {}
  }
}

function openEdit(s) {
  formError.value = ''
  form.value = { id: s.id, live: !s.awaits_checkout, name: s.name, description: s.description || '', amount: s.amount, interval: s.interval }
}

async function save() {
  busy.value = true
  formError.value = ''
  const body = { name: form.value.name, description: form.value.description || null, amount: Number(form.value.amount), interval: form.value.interval }
  try {
    const res = form.value.id
      ? await api.put(`/billing/subscriptions/${form.value.id}`, body)
      : await api.post('/billing/subscriptions', { ...body, company_id: form.value.company_id })
    replace(res.data.data)
    form.value = null
  } catch (e) {
    formError.value = e.response?.status === 422 ? t('subscriptions.invalidInput') : t('subscriptions.actionFailed')
  } finally {
    busy.value = false
  }
}

async function act(s, action, body = {}) {
  busy.value = true
  try {
    const res = await api.post(`/billing/subscriptions/${s.id}/${action}`, body)
    replace(res.data.data)
  } catch {
    notice.value = { ok: false, text: t('subscriptions.actionFailed') }
  } finally {
    busy.value = false
  }
}

function cancel(s, immediately) {
  const msg = s.awaits_checkout
    ? t('subscriptions.confirmDiscard', { name: s.name })
    : immediately
      ? t('subscriptions.confirmCancelNow', { name: s.name })
      : t('subscriptions.confirmCancelAtEnd', { name: s.name, date: formatDate(s.current_period_end) })
  if (!window.confirm(msg)) return
  act(s, 'cancel', { immediately })
}

// Stripe のページへ移動する。カード番号はこのサイトを通らない。
async function redirectTo(request) {
  busy.value = true
  try {
    const res = await request()
    window.location.href = res.data.url
  } catch {
    notice.value = { ok: false, text: t('subscriptions.redirectFailed') }
    busy.value = false
  }
}

function startCheckout(s) {
  redirectTo(() => api.post(`/billing/subscriptions/${s.id}/checkout`))
}

function openPortal(companyId) {
  redirectTo(() => api.post('/billing/portal', { company_id: companyId }))
}

onMounted(() => {
  // Stripe Checkout から戻ってきたとき。状態は Webhook が数秒遅れて届くので、少し後に読み直す。
  const result = route.query.checkout
  if (result) {
    notice.value = result === 'success'
      ? { ok: true, text: t('subscriptions.checkoutSuccess') }
      : { ok: false, text: t('subscriptions.checkoutCanceled') }
    const { checkout, ...rest } = route.query
    router.replace({ query: rest })
    if (result === 'success') setTimeout(load, 4000)
  }
  load()
})
</script>
