<template>
  <div class="bg-white rounded-xl shadow px-5 py-4 flex justify-between items-center">
    <div>
      <div class="font-medium text-gray-800">{{ task.title }}</div>
      <div v-if="task.projectName" class="text-sm text-gray-400">{{ task.projectName }}</div>
      <div v-if="task.due_date" class="text-xs text-gray-400 mt-1">Due: {{ task.due_date }}</div>
    </div>
    <div class="flex items-center gap-2">
      <StatusBadge :status="task.type" />
      <StatusBadge :status="task.status" />
    </div>
  </div>
</template>

<script setup>
import StatusBadge from "./StatusBadge.vue"
defineProps({ task: { type: Object, required: true } })
</script>
