<template>
  <!--
    固定ページ「料金」。
    左に何にいくらかかるか（ホスティング・制作・オプション）、右に実際に請求した
    ものの履歴。請求済みの金額は必ず請求書が正なので、料金表と履歴は同じ画面に
    並べても混ざらないよう見出しで分けてある。
  -->
  <div class="max-w-6xl mx-auto px-4 py-8">
    <div class="mb-6">
      <h1 class="font-serif text-2xl font-semibold text-ink">{{ t('pricing.title') }}</h1>
      <p class="text-sm text-gray-500 mt-1">{{ t('pricing.lead') }}</p>
    </div>

    <div class="flex gap-4 border-b border-mist mb-8">
      <button v-for="tab in tabs" :key="tab" @click="activeTab = tab" :class="tabClass(tab)">
        {{ t('pricing.tab' + tab) }}
      </button>
    </div>

    <!-- ============ 料金表 ============ -->
    <template v-if="activeTab === 'Plans'">
      <section class="mb-10">
        <h2 class="font-serif text-lg font-semibold text-ink mb-1">{{ t('pricing.hostingTitle') }}</h2>
        <p class="text-sm text-gray-500 mb-4">{{ t('pricing.hostingLead') }}</p>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <div v-for="plan in hostingPlans" :key="plan.key"
            class="rounded-2xl border bg-white p-5 flex flex-col"
            :class="plan.featured ? 'border-gold shadow-md' : 'border-mist shadow-sm'">
            <div class="flex items-center justify-between gap-2 mb-2">
              <h3 class="font-serif text-base font-semibold text-ink">{{ t('pricing.plans.' + plan.key + '.name') }}</h3>
              <span v-if="plan.featured" class="text-[11px] px-2 py-0.5 rounded-full bg-gold/20 text-[#8a6a35] font-medium">
                {{ t('pricing.popular') }}
              </span>
            </div>
            <p class="text-sm text-gray-500 mb-4 min-h-[2.5rem]">{{ t('pricing.plans.' + plan.key + '.summary') }}</p>
            <div class="mb-4">
              <span class="font-serif text-3xl font-semibold text-ink tabular-nums">{{ formatMoney(plan.monthly) }}</span>
              <span class="text-sm text-gray-500"> / {{ t('pricing.perMonth') }}</span>
            </div>
            <ul class="space-y-1.5 text-sm text-gray-700">
              <li v-for="f in plan.features" :key="f" class="flex gap-2">
                <span class="text-gold shrink-0" aria-hidden="true">✓</span>{{ t('pricing.features.' + f) }}
              </li>
            </ul>
          </div>
        </div>
      </section>

      <section class="mb-10">
        <h2 class="font-serif text-lg font-semibold text-ink mb-1">{{ t('pricing.buildTitle') }}</h2>
        <p class="text-sm text-gray-500 mb-4">{{ t('pricing.buildLead') }}</p>
        <div class="rounded-2xl border border-mist bg-white shadow-sm divide-y divide-mist">
          <div v-for="b in buildPlans" :key="b.key" class="flex flex-wrap items-center justify-between gap-2 px-5 py-4">
            <div class="min-w-0">
              <div class="font-medium text-ink">{{ t('pricing.build.' + b.key + '.name') }}</div>
              <div class="text-sm text-gray-500">{{ t('pricing.build.' + b.key + '.summary') }}</div>
            </div>
            <div class="text-right whitespace-nowrap">
              <span class="text-xs text-gray-500">{{ t('pricing.from') }}</span>
              <span class="font-serif text-xl font-semibold text-ink tabular-nums ml-1">{{ formatMoney(b.from) }}</span>
            </div>
          </div>
        </div>
      </section>

      <section class="mb-10">
        <h2 class="font-serif text-lg font-semibold text-ink mb-4">{{ t('pricing.addonsTitle') }}</h2>
        <div class="overflow-x-auto rounded-2xl border border-mist bg-white shadow-sm">
          <table class="w-full text-sm">
            <thead class="bg-sand text-gray-500 text-xs">
              <tr>
                <th class="text-left font-semibold px-5 py-2.5">{{ t('pricing.colService') }}</th>
                <th class="text-right font-semibold px-5 py-2.5">{{ t('pricing.colPrice') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="a in addons" :key="a.key" class="border-t border-mist">
                <td class="px-5 py-3">
                  <div class="text-ink">{{ t('pricing.addons.' + a.key + '.name') }}</div>
                  <div class="text-xs text-gray-500">{{ t('pricing.addons.' + a.key + '.summary') }}</div>
                </td>
                <td class="px-5 py-3 text-right whitespace-nowrap tabular-nums">
                  {{ formatMoney(a.price) }}<span class="text-xs text-gray-500"> / {{ t('pricing.units.' + a.unit) }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section>
        <h2 class="font-serif text-lg font-semibold text-ink mb-3">{{ t('pricing.termsTitle') }}</h2>
        <ul class="space-y-2 text-sm text-gray-600">
          <li v-for="term in terms" :key="term" class="flex gap-2">
            <span class="text-gold shrink-0" aria-hidden="true">•</span>{{ t('pricing.terms.' + term) }}
          </li>
        </ul>
      </section>
    </template>

    <!-- ============ サブスク（会社ごとの定期契約・Stripe 自動引き落とし） ============ -->
    <SubscriptionsPanel v-else-if="activeTab === 'Subscriptions'" />

    <!-- ============ 支払い履歴 ============ -->
    <template v-else>
      <div v-if="loading" class="text-sm text-gray-400 py-12 text-center">{{ t('common.loading') }}</div>
      <p v-else-if="error" class="text-sm text-red-500 py-6">{{ error }}</p>

      <template v-else>
        <div class="grid gap-4 sm:grid-cols-3 mb-6">
          <div v-for="card in summary" :key="card.key" class="rounded-2xl border border-mist bg-white shadow-sm px-5 py-4">
            <div class="text-xs text-gray-500 mb-1">{{ t('pricing.' + card.key) }}</div>
            <div class="font-serif text-2xl font-semibold text-ink tabular-nums">{{ card.value }}</div>
          </div>
        </div>

        <div v-if="invoices.length === 0" class="py-16 text-center text-gray-400">
          <p class="font-medium text-ink">{{ t('pricing.noInvoices') }}</p>
          <p class="text-sm mt-1">{{ t('pricing.noInvoicesHint') }}</p>
        </div>

        <div v-else class="overflow-x-auto rounded-2xl border border-mist bg-white shadow-sm">
          <table class="w-full text-sm">
            <thead class="bg-sand text-gray-500 text-xs">
              <tr>
                <th class="text-left font-semibold px-5 py-2.5">{{ t('pricing.colNumber') }}</th>
                <th class="text-left font-semibold px-5 py-2.5 hidden md:table-cell">{{ t('pricing.colProject') }}</th>
                <th class="text-left font-semibold px-5 py-2.5 hidden sm:table-cell">{{ t('pricing.colIssued') }}</th>
                <th class="text-right font-semibold px-5 py-2.5">{{ t('pricing.colAmount') }}</th>
                <th class="text-left font-semibold px-5 py-2.5">{{ t('pricing.colStatus') }}</th>
                <th class="px-5 py-2.5"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="inv in invoices" :key="inv.id" class="border-t border-mist hover:bg-sand/60">
                <td class="px-5 py-3 font-medium text-ink whitespace-nowrap">{{ inv.invoice_number }}</td>
                <td class="px-5 py-3 hidden md:table-cell text-gray-600">{{ inv.project?.title || '—' }}</td>
                <td class="px-5 py-3 hidden sm:table-cell text-gray-600 whitespace-nowrap">{{ formatDate(inv.issued_at) }}</td>
                <td class="px-5 py-3 text-right tabular-nums whitespace-nowrap">{{ formatMoney(inv.total) }}</td>
                <td class="px-5 py-3"><StatusBadge :status="inv.status" /></td>
                <td class="px-5 py-3 text-right whitespace-nowrap">
                  <router-link :to="`/billing/invoices/${inv.id}`" class="text-indigo-600 hover:underline">{{ t('pricing.viewInvoice') }}</router-link>
                  <router-link v-if="inv.status === 'paid'" :to="`/billing/invoices/${inv.id}/receipt`"
                    class="text-indigo-600 hover:underline ml-3">{{ t('pricing.viewReceipt') }}</router-link>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '../api'
import { activeDateLocale } from '../i18n'
import { formatMoney } from '../services/money'
import { HOSTING_PLANS, BUILD_PLANS, ADDONS, TERMS } from '../services/pricing'
import StatusBadge from '../components/StatusBadge.vue'
import SubscriptionsPanel from '../components/SubscriptionsPanel.vue'

const { t } = useI18n()

const route = useRoute()
const router = useRouter()

const tabs = ['Plans', 'Subscriptions', 'History']
// ?tab= で開くタブを指定できる（Stripe からの戻り先・お知らせのリンクが使う）。
const activeTab = ref(tabs.includes(route.query.tab) ? route.query.tab : 'Plans')
watch(activeTab, (tab) => {
  if (route.query.tab !== tab) router.replace({ query: { ...route.query, tab } })
})
// 料金ページを開いたままお知らせから ?tab= 付きで来たときも追従する。
watch(() => route.query.tab, (tab) => {
  if (tabs.includes(tab)) activeTab.value = tab
})
const invoices = ref([])
const loading = ref(true)
const error = ref('')

const hostingPlans = HOSTING_PLANS
const buildPlans = BUILD_PLANS
const addons = ADDONS
const terms = TERMS

const summary = computed(() => {
  const paid = invoices.value.filter((i) => i.status === 'paid')
  const outstanding = invoices.value.filter((i) => ['sent', 'overdue'].includes(i.status))
  const sum = (list) => list.reduce((n, i) => n + Number(i.total || 0), 0)
  return [
    { key: 'summaryPaid', value: formatMoney(sum(paid)) },
    { key: 'summaryOutstanding', value: formatMoney(sum(outstanding)) },
    { key: 'summaryCount', value: String(invoices.value.length) },
  ]
})

function tabClass(tab) {
  return tab === activeTab.value
    ? 'pb-2 px-1 font-medium border-b-2 border-indigo-600 text-indigo-600 cursor-pointer'
    : 'pb-2 px-1 font-medium text-gray-500 hover:text-gray-700 cursor-pointer'
}

function formatDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString(activeDateLocale(), { year: 'numeric', month: 'short', day: 'numeric' })
}

onMounted(async () => {
  try {
    const res = await api.get('/billing/invoices')
    invoices.value = res.data?.data || []
  } catch {
    error.value = t('pricing.loadFailed')
  } finally {
    loading.value = false
  }
})
</script>
