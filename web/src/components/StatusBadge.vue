<template>
  <span :class="badgeClass">
    {{ label }}
  </span>
</template>

<script setup>
import { computed } from "vue"
import { useI18n } from "vue-i18n"

const props = defineProps({ status: { type: String, default: "" } })
const { t, te } = useI18n()

// 色だけをここで管理し、ラベルは badge.* 辞書から引く（未定義は生値でフォールバック）
const colorMap = {
  pending_review: "bg-yellow-100 text-yellow-800",
  approved: "bg-green-100 text-green-800",
  rejected: "bg-red-100 text-red-800",
  draft: "bg-gray-100 text-gray-700",
  deployed: "bg-blue-100 text-blue-800",
  active: "bg-indigo-100 text-indigo-800",
  completed: "bg-green-100 text-green-800",
  archived: "bg-gray-100 text-gray-500",
  paid: "bg-green-100 text-green-800",
  unpaid: "bg-red-100 text-red-800",
  sent: "bg-blue-100 text-blue-800",
  overdue: "bg-red-100 text-red-800",
  in_progress: "bg-blue-100 text-blue-800",
  design_review: "bg-purple-100 text-purple-800",
  staging_review: "bg-indigo-100 text-indigo-800",
  deploy_approval: "bg-green-100 text-green-800",
  dependency_update: "bg-orange-100 text-orange-800",
  content_revision: "bg-orange-100 text-orange-800",
  other: "bg-gray-100 text-gray-600",
  design: "bg-purple-100 text-purple-800",
  development: "bg-indigo-100 text-indigo-800",
  content: "bg-orange-100 text-orange-800",
  review: "bg-yellow-100 text-yellow-800",
  approve: "bg-green-100 text-green-800",
  reject: "bg-red-100 text-red-800",
}

const base = "inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
const colorClass = computed(() => colorMap[props.status] || "bg-gray-100 text-gray-600")
const label = computed(() => {
  const key = `badge.${props.status}`
  return te(key) ? t(key) : props.status
})
const badgeClass = computed(() => base + " " + colorClass.value)
</script>
