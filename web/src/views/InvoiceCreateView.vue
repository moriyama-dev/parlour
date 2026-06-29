<template>
  <div class="max-w-3xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-800 mb-6">New Invoice</h1>
    <div class="bg-white rounded-xl shadow p-6">
      <form @submit.prevent="submit" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Issued Date</label>
            <input v-model="form.issued_at" type="date" required class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Due Date</label>
            <input v-model="form.due_at" type="date" required class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Tax Rate (%)</label>
          <input v-model.number="form.tax_rate" type="number" min="0" max="100" class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
          <textarea v-model="form.notes" rows="2" class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
        </div>

        <div>
          <div class="flex justify-between items-center mb-2">
            <h3 class="font-medium text-gray-700">Line Items</h3>
            <button type="button" @click="addItem" class="text-sm text-indigo-600 hover:underline">+ Add Row</button>
          </div>
          <div class="space-y-2">
            <div v-for="(item, i) in form.items" :key="i" class="grid grid-cols-12 gap-2 items-center">
              <input v-model="item.description" placeholder="Description" class="col-span-5 border rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
              <input v-model.number="item.quantity" type="number" min="1" placeholder="Qty" class="col-span-2 border rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
              <input v-model.number="item.unit_price" type="number" min="0" placeholder="Price" class="col-span-3 border rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
              <div class="col-span-1 text-sm text-right text-gray-600">¥{{ ((item.quantity || 0) * (item.unit_price || 0)).toLocaleString() }}</div>
              <button type="button" @click="removeItem(i)" class="col-span-1 text-red-400 hover:text-red-600 text-sm">x</button>
            </div>
          </div>
        </div>

        <div class="border-t pt-4 text-right space-y-1">
          <div class="text-sm text-gray-600">Subtotal: ¥{{ subtotal.toLocaleString() }}</div>
          <div class="text-sm text-gray-600">Tax ({{ form.tax_rate }}%): ¥{{ taxAmount.toLocaleString() }}</div>
          <div class="font-bold text-lg">Total: ¥{{ total.toLocaleString() }}</div>
        </div>

        <div class="flex gap-3 justify-end">
          <button type="button" @click="$router.back()" class="px-4 py-2 border rounded-lg">Cancel</button>
          <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">Create Invoice</button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from "vue"
import { useRoute, useRouter } from "vue-router"
import api from "../api"

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

const subtotal = computed(() => form.value.items.reduce((s, i) => s + (i.quantity || 0) * (i.unit_price || 0), 0))
const taxAmount = computed(() => Math.round(subtotal.value * form.value.tax_rate / 100))
const total = computed(() => subtotal.value + taxAmount.value)

function addItem() { form.value.items.push({ description: "", quantity: 1, unit_price: 0 }) }
function removeItem(i) { form.value.items.splice(i, 1) }

async function submit() {
  await api.post(`/projects/${id}/invoices`, {
    ...form.value,
    subtotal_amount: subtotal.value,
    tax_amount: taxAmount.value,
    total_amount: total.value,
  })
  router.push(`/projects/${id}`)
}
</script>
