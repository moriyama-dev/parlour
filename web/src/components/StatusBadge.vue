<template>
  <span :class="badgeClass">
    {{ label }}
  </span>
</template>

<script setup>
import { computed } from "vue"

const props = defineProps({ status: { type: String, default: "" } })

const map = {
  pending_review: ["bg-yellow-100 text-yellow-800", "Pending Review"],
  approved: ["bg-green-100 text-green-800", "Approved"],
  rejected: ["bg-red-100 text-red-800", "Rejected"],
  draft: ["bg-gray-100 text-gray-700", "Draft"],
  deployed: ["bg-blue-100 text-blue-800", "Deployed"],
  active: ["bg-indigo-100 text-indigo-800", "Active"],
  completed: ["bg-green-100 text-green-800", "Completed"],
  archived: ["bg-gray-100 text-gray-500", "Archived"],
  paid: ["bg-green-100 text-green-800", "Paid"],
  unpaid: ["bg-red-100 text-red-800", "Unpaid"],
  sent: ["bg-blue-100 text-blue-800", "Sent"],
  design: ["bg-purple-100 text-purple-800", "Design"],
  development: ["bg-indigo-100 text-indigo-800", "Development"],
  content: ["bg-orange-100 text-orange-800", "Content"],
  review: ["bg-yellow-100 text-yellow-800", "Review"],
  approve: ["bg-green-100 text-green-800", "Approved"],
  reject: ["bg-red-100 text-red-800", "Rejected"],
}

const base = "inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
const colorClass = computed(() => (map[props.status] || ["bg-gray-100 text-gray-600", props.status])[0])
const label = computed(() => (map[props.status] || ["", props.status])[1])
const badgeClass = computed(() => base + " " + colorClass.value)
</script>
