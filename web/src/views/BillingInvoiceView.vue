<template>
  <!--
    請求書と領収書。
    同じ1件のデータを2つの体裁で出す（/billing/invoices/:id と .../receipt）。
    紙に出すことがあるので、印刷時は画面用の枠と操作を落として本文だけ残す。
  -->
  <div class="max-w-3xl mx-auto px-4 py-8">
    <div v-if="loading" class="text-sm text-gray-400 py-16 text-center">{{ t('common.loading') }}</div>

    <div v-else-if="error" class="bg-white rounded-2xl shadow p-8 text-center">
      <p class="text-sm text-gray-600 mb-4">{{ error }}</p>
      <router-link to="/billing" class="inline-block px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm">
        {{ t('pricing.backToBilling') }}
      </router-link>
    </div>

    <template v-else>
      <div class="flex items-center justify-between gap-3 mb-4 no-print">
        <router-link to="/billing" class="text-sm text-indigo-600 hover:underline">← {{ t('pricing.backToBilling') }}</router-link>
        <div class="flex items-center gap-2">
          <router-link v-if="isReceipt" :to="`/billing/invoices/${invoice.id}`"
            class="text-sm px-3 py-1.5 rounded-lg border border-mist hover:bg-sand">{{ t('pricing.viewInvoice') }}</router-link>
          <router-link v-else-if="invoice.status === 'paid'" :to="`/billing/invoices/${invoice.id}/receipt`"
            class="text-sm px-3 py-1.5 rounded-lg border border-mist hover:bg-sand">{{ t('pricing.viewReceipt') }}</router-link>
          <button @click="printDoc"
            class="text-sm px-3 py-1.5 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 cursor-pointer">{{ t('invoice.print') }}</button>
        </div>
      </div>

      <article class="bg-white rounded-2xl shadow p-8 print-flat">
        <header class="flex flex-wrap justify-between items-start gap-4 mb-8">
          <div>
            <h1 class="font-serif text-2xl font-semibold text-ink">
              {{ isReceipt ? t('pricing.receiptTitle') : t('pricing.invoiceTitle') }}
            </h1>
            <div class="text-sm text-gray-500 mt-1">
              {{ isReceipt ? t('pricing.receiptNumber', { number: invoice.receipt_number })
                           : t('invoice.number', { number: invoice.invoice_number }) }}
            </div>
            <div class="text-sm text-gray-500">
              <template v-if="isReceipt">{{ t('pricing.paidOn') }}: {{ formatDate(invoice.paid_at) }}</template>
              <template v-else>{{ t('invoice.issued') }}: {{ formatDate(invoice.issued_at) }}</template>
            </div>
            <div v-if="!isReceipt" class="text-sm text-gray-500">{{ t('common.due') }}: {{ formatDate(invoice.due_at) }}</div>
          </div>
          <div class="text-right">
            <div class="flex justify-end mb-2 no-print"><StatusBadge :status="invoice.status" /></div>
            <div class="font-serif text-base font-semibold text-ink">{{ t('pricing.issuer') }}</div>
            <div class="text-xs text-gray-500 whitespace-pre-line">{{ t('pricing.issuerDetail') }}</div>
          </div>
        </header>

        <div class="flex flex-wrap gap-x-10 gap-y-2 mb-6 text-sm">
          <div>
            <div class="text-xs text-gray-500">{{ t('pricing.billedTo') }}</div>
            <div class="text-ink font-medium">{{ invoice.project?.company?.name || '—' }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500">{{ t('pricing.colProject') }}</div>
            <div class="text-ink font-medium">{{ invoice.project?.title || '—' }}</div>
          </div>
        </div>

        <!-- 領収書は「何に対する支払いか」を1行で示す（但し書き）。 -->
        <p v-if="isReceipt" class="mb-6 text-sm text-gray-700 border-y border-mist py-3">
          {{ t('pricing.receiptFor', { project: invoice.project?.title || '—', number: invoice.invoice_number }) }}
        </p>

        <table class="w-full text-sm mb-6">
          <thead>
            <tr class="border-b border-mist text-gray-500 text-xs">
              <th class="text-left py-2">{{ t('invoice.description') }}</th>
              <th class="text-right py-2 w-16">{{ t('invoice.qty') }}</th>
              <th class="text-right py-2 w-28">{{ t('invoice.unitPrice') }}</th>
              <th class="text-right py-2 w-28">{{ t('invoice.amount') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in items" :key="item.id" class="border-b border-mist">
              <td class="py-2 text-gray-700">{{ item.description }}</td>
              <td class="py-2 text-right tabular-nums">{{ item.quantity }}</td>
              <td class="py-2 text-right tabular-nums">{{ formatMoney(item.unit_price) }}</td>
              <td class="py-2 text-right tabular-nums">{{ formatMoney(item.quantity * item.unit_price) }}</td>
            </tr>
            <tr v-if="items.length === 0">
              <td colspan="4" class="py-4 text-center text-gray-400 text-xs">{{ t('pricing.noItems') }}</td>
            </tr>
          </tbody>
        </table>

        <div class="flex justify-end">
          <dl class="w-full max-w-xs space-y-1 text-sm">
            <div class="flex justify-between"><dt class="text-gray-500">{{ t('invoice.subtotal') }}</dt><dd class="tabular-nums">{{ formatMoney(invoice.subtotal) }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">{{ t('invoice.taxWithRate', { rate: Number(invoice.tax_rate || 0) }) }}</dt><dd class="tabular-nums">{{ formatMoney(invoice.tax_amount) }}</dd></div>
            <div class="flex justify-between border-t border-mist pt-2 mt-2">
              <dt class="font-serif font-semibold text-ink">{{ isReceipt ? t('pricing.amountReceived') : t('invoice.total') }}</dt>
              <dd class="font-serif text-xl font-semibold text-ink tabular-nums">{{ formatMoney(invoice.total) }}</dd>
            </div>
          </dl>
        </div>

        <p v-if="invoice.notes" class="mt-6 text-sm text-gray-500 border-t border-mist pt-4 whitespace-pre-line">{{ invoice.notes }}</p>
        <p v-if="isReceipt" class="mt-6 text-xs text-gray-400">{{ t('pricing.receiptFooter') }}</p>
      </article>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '../api'
import { activeDateLocale } from '../i18n'
import { formatMoney } from '../services/money'
import StatusBadge from '../components/StatusBadge.vue'

const { t } = useI18n()
const route = useRoute()

const invoice = ref(null)
const loading = ref(true)
const error = ref('')

const items = computed(() => invoice.value?.items || [])
// 領収書は支払い済みのものだけ。URL を書き換えても未払いなら請求書として出す。
const isReceipt = computed(() => route.path.endsWith('/receipt') && invoice.value?.status === 'paid')

function formatDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString(activeDateLocale(), { year: 'numeric', month: 'long', day: 'numeric' })
}

function printDoc() {
  window.print()
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.get(`/billing/invoices/${route.params.id}`)
    invoice.value = res.data?.data
  } catch (e) {
    error.value = e.response?.status === 403 || e.response?.status === 404
      ? t('pricing.invoiceNotFound')
      : t('pricing.loadFailed')
  } finally {
    loading.value = false
  }
}

watch(() => route.params.id, load)
onMounted(load)
</script>

<style>
@media print {
  .no-print { display: none !important; }
  .print-flat { box-shadow: none !important; border: 0 !important; padding: 0 !important; }
}
</style>
