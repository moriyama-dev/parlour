<template>
  <!--
    タスクの進み具合。
    上に「完了率」1つ（読み手が最初に知りたい数字）、下にステータス別の件数を
    横棒で並べる。棒は全部同じ色——長さが件数を示しているので、色に別の意味を
    持たせない。ステータスの区別は左の見出しが担う。
  -->
  <div>
    <div v-if="total === 0" class="text-sm text-gray-400 py-6 text-center">
      {{ t('progress.noTasks') }}
    </div>

    <template v-else>
      <div class="flex items-end gap-3 mb-1">
        <div class="font-serif text-4xl leading-none text-[#173a63] tabular-nums">{{ percent }}<span class="text-xl">%</span></div>
        <div class="text-sm text-gray-500 pb-1">{{ t('progress.doneOfTotal', { done, total }) }}</div>
      </div>
      <ProgressMeter :stats="stats" :height="10" class="mb-6" />

      <!-- ステータス別の件数。棒＝件数、左の見出し＝ステータス。 -->
      <div class="space-y-2">
        <div v-for="row in rows" :key="row.status" class="grid grid-cols-[8.5rem_1fr_2.5rem] items-center gap-3"
          :title="t('progress.rowTitle', { status: row.label, n: row.count })">
          <span class="text-xs text-gray-600 truncate">{{ row.label }}</span>
          <span class="h-3 bg-[#f3efe6] rounded-r-sm relative">
            <span class="absolute inset-y-0 left-0 bg-[#2f5d7c] rounded-r"
              :style="{ width: (max ? (row.count / max) * 100 : 0) + '%' }"></span>
          </span>
          <span class="text-xs text-gray-700 tabular-nums text-right">{{ row.count }}</span>
        </div>
      </div>

      <p v-if="stats.rejected" class="mt-4 flex items-center gap-2 text-xs text-gray-600">
        <StatusBadge status="rejected" />
        {{ t('progress.rejectedHint', { n: stats.rejected }) }}
      </p>
    </template>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import ProgressMeter from './ProgressMeter.vue'
import StatusBadge from './StatusBadge.vue'

const props = defineProps({ stats: { type: Object, default: () => ({}) } })
const { t } = useI18n()

// tasks テーブルの enum と同じ並び（作業が進む順）。
const STATUSES = ['draft', 'pending_review', 'approved', 'deployed', 'rejected']

const total = computed(() => props.stats?.total || 0)
const done = computed(() => (props.stats?.approved || 0) + (props.stats?.deployed || 0))
const percent = computed(() => (total.value ? Math.round((done.value / total.value) * 100) : 0))
const rows = computed(() => STATUSES.map((s) => ({ status: s, label: t('badge.' + s), count: props.stats?.[s] || 0 })))
const max = computed(() => Math.max(...rows.value.map((r) => r.count), 1))
</script>
