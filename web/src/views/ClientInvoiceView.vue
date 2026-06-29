<template>
  <div class="max-w-3xl mx-auto px-4 py-8">
    <div v-if="invoice" class="bg-white rounded-xl shadow p-8">
      <div class="flex justify-between items-start mb-8">
        <div>
          <h1 class="text-2xl font-bold text-gray-800">Invoice #{{ invoice.invoice_number || invoice.id }}</h1>
          <div class="text-sm text-gray-500 mt-1">Issued: {{ invoice.issued_at }}</div>
          <div class="text-sm text-gray-500">Due: {{ invoice.due_at }}</div>
        </div>
        <div class="flex gap-3 items-center">
          <StatusBadge :status="invoice.status" />
          <button @click="print()" class="bg-gray-100 text-gray-700 px-3 py-1.5 rounded-lg text-sm hover:bg-gray-200">Print</button>
        </div>
      </div>

      <table class="w-full text-sm mb-6">
        <thead>
          <tr class="border-b text-gray-500">
            <th class="text-left py-2">Description</th>
            <th class="text-right py-2">Qty</th>
            <th class="text-right py-2">Unit Price</th>
            <th class="text-right py-2">Amount</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in items" :key="item.id" class="border-b">
            <td class="py-2">{{ item.description }}</td>
            <td class="text-right py-2">{{ item.quantity }}</td>
            <td class="text-right py-2">¥{{ Number(item.unit_price).toLocaleString() }}</td>
            <td class="text-right py-2">¥{{ (item.quantity * item.unit_price).toLocaleString() }}</td>
          </tr>
        </tbody>
      </table>

      <div class="text-right space-y-1">
        <div class="text-sm text-gray-600">Subtotal: ¥{{ Number(invoice.subtotal_amount || 0).toLocaleString() }}</div>
        <div class="text-sm text-gray-600">Tax: ¥{{ Number(invoice.tax_amount || 0).toLocaleString() }}</div>
        <div class="text-xl font-bold text-gray-800">Total: ¥{{ Number(invoice.total_amount || 0).toLocaleString() }}</div>
      </div>

      <div v-if="invoice.notes" class="mt-6 text-sm text-gray-500 border-t pt-4">{{ invoice.notes }}</div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from "vue"
import { useRoute } from "vue-router"
import api from "../api"
import StatusBadge from "../components/StatusBadge.vue"

const route = useRoute()
const invoice = ref(null)
const items = ref([])

function print() { window.print() }

onMounted(async () => {
  const projRes = await api.get("/projects")
  const projects = projRes.data?.data || projRes.data || []
  for (const p of projects) {
    try {
      const iRes = await api.get(`/projects/${p.id}/invoices`)
      const invs = iRes.data?.data || iRes.data || []
      const found = invs.find(i => String(i.id) === String(route.params.id))
      if (found) {
        invoice.value = found
        items.value = found.items || []
        break
      }
    } catch {}
  }
})
</script>
