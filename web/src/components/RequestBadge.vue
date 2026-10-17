<template>
  <span :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap', color]">{{ label }}</span>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { STATUS_COLORS, PRIORITY_COLORS } from '../services/requests'

// kind: status / category / priority。文言は requests.* 辞書から引く。
const props = defineProps({
  kind: { type: String, default: 'status' },
  value: { type: String, default: '' },
})
const { t, te } = useI18n()

const dict = { status: 'status', category: 'categories', priority: 'priorities' }

const label = computed(() => {
  const key = `requests.${dict[props.kind]}.${props.value}`
  return te(key) ? t(key) : props.value
})

const color = computed(() => {
  if (props.kind === 'status') return STATUS_COLORS[props.value] || 'bg-gray-100 text-gray-600'
  if (props.kind === 'priority') return PRIORITY_COLORS[props.value] || 'bg-gray-100 text-gray-600'
  return 'bg-indigo-50 text-indigo-700'
})
</script>
