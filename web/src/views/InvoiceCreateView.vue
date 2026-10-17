<template>
  <div class="max-w-3xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-800 mb-6">{{ t("invoice.newInvoice") }}</h1>
    <div class="bg-white rounded-xl shadow p-6">
      <form @submit.prevent="submit" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">{{ t("invoice.issuedDate") }}</label>
            <input v-model="form.issued_at" type="date" required class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">{{ t("invoice.dueDate") }}</label>
            <input v-model="form.due_at" type="date" required class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t("invoice.taxRate") }}</label>
          <input v-model.number="form.tax_rate" type="number" min="0" max="100" class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t("invoice.notes") }}</label>
          <textarea v-model="form.notes" rows="2" class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
        </div>

        <div>
          <div class="flex justify-between items-center mb-2">
            <h3 class="font-medium text-gray-700">{{ t("invoice.lineItems") }}</h3>
            <button type="button" @click="addItem" class="text-sm text-indigo-600 hover:underline">{{ t("invoice.addRow") }}</button>
          </div>
          <div class="space-y-2">
            <div v-for="(item, i) in form.items" :key="i" class="grid grid-cols-12 gap-2 items-center">
              <input v-model="item.description" :placeholder="t('invoice.description')" class="col-span-5 border rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
              <input v-model.number="item.quantity" type="number" min="1" :placeholder="t('invoice.qty')" class="col-span-2 border rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
              <input v-model.number="item.unit_price" type="number" min="0" :placeholder="t('invoice.price')" class="col-span-3 border rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
              <div class="col-span-1 text-sm text-right text-gray-600">¥{{ ((item.quantity || 0) * (item.unit_price || 0)).toLocaleString() }}</div>
              <button type="button" @click="removeItem(i)" class="col-span-1 text-red-400 hover:text-red-600 text-sm">x</button>
            </div>
          </div>
        </div>

        <!-- 前金・残金の分割（着手時に半額、完成時に残り半額） -->
        <div class="border border-mist rounded-lg px-4 py-3 space-y-2">
          <label class="flex items-center gap-2 text-sm text-gray-700">
            <input v-model="split" type="checkbox" class="accent-indigo-600" /> {{ t("invoice.split") }}
          </label>
          <p class="text-xs text-gray-400">{{ t("invoice.splitHint") }}</p>
          <div v-if="split">
            <label class="block text-sm font-medium text-gray-700 mb-1">{{ t("invoice.balanceDue") }}</label>
            <input v-model="balanceDueAt" type="date" required class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          </div>
        </div>

        <div class="border-t pt-4 text-right space-y-1">
          <div class="text-sm text-gray-600">{{ t("invoice.subtotal") }}: ¥{{ subtotal.toLocaleString() }}</div>
          <div class="text-sm text-gray-600">{{ t("invoice.taxWithRate", { rate: form.tax_rate }) }}: ¥{{ taxAmount.toLocaleString() }}</div>
          <div class="font-bold text-lg">{{ t("invoice.total") }}: ¥{{ total.toLocaleString() }}</div>
        </div>

        <p v-if="error" class="text-sm text-red-500 text-right">{{ error }}</p>
        <div class="flex gap-3 justify-end">
          <button type="button" @click="$router.back()" class="px-4 py-2 border rounded-lg">{{ t("common.cancel") }}</button>
          <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">{{ t("invoice.createInvoice") }}</button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from "vue"
import { useRoute, useRouter } from "vue-router"
import { useI18n } from "vue-i18n"
import api from "../api"

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const id = route.params.id

const form = ref({
  issued_at: "",
  due_at: "",
  tax_rate: 10,
  notes: "",
  items: [{ description: "", quantity: 1, unit_price: 0 }],
})

const split = ref(false)
const balanceDueAt = ref("")
const error = ref("")

const subtotal = computed(() => form.value.items.reduce((s, i) => s + (i.quantity || 0) * (i.unit_price || 0), 0))
const taxAmount = computed(() => Math.round(subtotal.value * form.value.tax_rate / 100))
const total = computed(() => subtotal.value + taxAmount.value)

function addItem() { form.value.items.push({ description: "", quantity: 1, unit_price: 0 }) }
function removeItem(i) { form.value.items.splice(i, 1) }

/*
 * API は subtotal / tax_amount / total と items を受け取る（以前は *_amount という名前で送っていて
 * 422 になり、明細も保存されていなかった）。
 * 分割するときは同じ明細を半額ずつにした2通を作る。端数は前金側に寄せる。
 */
function invoicePayload(items, extra = {}) {
  const sub = items.reduce((s, i) => s + i.quantity * i.unit_price, 0)
  const tax = Math.round(sub * form.value.tax_rate / 100)
  return { ...form.value, ...extra, items, subtotal: sub, tax_amount: tax, total: sub + tax }
}

function halve(items, suffix, roundUp) {
  return items.map((i) => {
    const line = (i.quantity || 0) * (i.unit_price || 0)
    const half = roundUp ? Math.ceil(line / 2) : Math.floor(line / 2)
    return { description: (i.description || "") + suffix, quantity: 1, unit_price: half }
  })
}

async function submit() {
  error.value = ""
  const items = form.value.items.filter((i) => i.description)
  try {
    if (split.value) {
      const notes = (n) => [t(n), form.value.notes].filter(Boolean).join("\n")
      await api.post(`/projects/${id}/invoices`, invoicePayload(halve(items, t("invoice.depositSuffix"), true), { notes: notes("invoice.depositNote") }))
      await api.post(`/projects/${id}/invoices`, invoicePayload(halve(items, t("invoice.balanceSuffix"), false), { notes: notes("invoice.balanceNote"), due_at: balanceDueAt.value }))
    } else {
      await api.post(`/projects/${id}/invoices`, invoicePayload(items.map((i) => ({ description: i.description, quantity: i.quantity || 0, unit_price: i.unit_price || 0 }))))
    }
    router.push(`/projects/${id}`)
  } catch (e) {
    error.value = e.response?.data?.message || t("invoice.createFailed")
  }
}
</script>
