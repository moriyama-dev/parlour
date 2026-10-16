<template>
  <!--
    案件の進捗メーター。
    「完了したタスク / 全タスク」という1つの比率だけを見せる。色は1色（進捗の
    濃紺）＋残りは下地。ステータスごとの内訳は TaskProgressChart が受け持つ。
  -->
  <div>
    <div class="flex items-baseline justify-between gap-3 mb-1.5">
      <span v-if="label" class="text-xs text-gray-500">{{ label }}</span>
      <span class="text-xs text-gray-500 tabular-nums ml-auto">
        {{ t('progress.doneOfTotal', { done, total }) }}
      </span>
      <span class="text-sm font-semibold text-[#173a63] tabular-nums">{{ percent }}%</span>
    </div>
    <div class="rounded-full overflow-hidden bg-[#e7ddcb]" :style="{ height: height + 'px' }"
      role="img" :aria-label="t('progress.aria', { percent, done, total })">
      <div class="h-full rounded-full bg-[#2f5d7c] transition-[width] duration-500"
        :style="{ width: percent + '%' }"></div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  stats: { type: Object, default: () => ({}) },
  label: { type: String, default: '' },
  height: { type: Number, default: 8 },
})

const { t } = useI18n()

// 完了＝承認済み＋公開済み。却下は「やり直し待ち」なので完了に数えない。
const done = computed(() => (props.stats?.approved || 0) + (props.stats?.deployed || 0))
const total = computed(() => props.stats?.total || 0)
const percent = computed(() => (total.value ? Math.round((done.value / total.value) * 100) : 0))
</script>
