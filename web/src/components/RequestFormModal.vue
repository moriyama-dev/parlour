<template>
  <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4" @click.self="$emit('close')">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg max-h-[92vh] overflow-y-auto p-6">
      <h2 class="font-serif text-lg font-semibold text-ink mb-4">{{ editing ? t('requests.editTitle') : t('requests.newTitle') }}</h2>
      <form @submit.prevent="submit" class="space-y-3">
        <div v-if="!editing && companies.length > 1">
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('requests.company') }}</label>
          <select v-model="form.company_id" required class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
            <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('requests.titleLabel') }}</label>
          <input v-model="form.title" required maxlength="255" :placeholder="t('requests.titlePlaceholder')"
            class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('requests.description') }}</label>
          <textarea v-model="form.description" rows="5" :placeholder="t('requests.descriptionPlaceholder')"
            class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('requests.category') }}</label>
            <select v-model="form.category" class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
              <option v-for="c in CATEGORIES" :key="c" :value="c">{{ t(`requests.categories.${c}`) }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('requests.priority') }}</label>
            <select v-model="form.priority" class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
              <option v-for="p in PRIORITIES" :key="p" :value="p">{{ t(`requests.priorities.${p}`) }}</option>
            </select>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('requests.project') }}</label>
          <select v-model="form.project_id" class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
            <option :value="null">{{ t('requests.noProject') }}</option>
            <option v-for="p in companyProjects" :key="p.id" :value="p.id">{{ p.title }}</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('requests.videoUrl') }}</label>
          <input v-model="form.video_url" type="url" placeholder="https://www.loom.com/share/…"
            class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          <p class="text-xs text-gray-400 mt-1">{{ t('requests.videoHint') }}</p>
        </div>

        <div class="grid sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('requests.referenceUrl') }}</label>
            <input v-model="form.reference_url" type="url" placeholder="https://"
              class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('requests.requestedDue') }}</label>
            <input v-model="form.requested_due_on" type="date"
              class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          </div>
        </div>

        <p v-if="error" class="text-sm text-red-500">{{ error }}</p>
        <div class="flex justify-end gap-3 pt-2">
          <button type="button" @click="$emit('close')" class="px-4 py-2 border rounded-lg cursor-pointer">{{ t('common.cancel') }}</button>
          <button type="submit" :disabled="saving"
            class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-60 cursor-pointer">
            {{ editing ? t('requests.save') : t('requests.submit') }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../api'
import { CATEGORIES, PRIORITIES } from '../services/requests'

const props = defineProps({
  companies: { type: Array, default: () => [] },
  // 編集するときの依頼。無ければ新規。
  initial: { type: Object, default: null },
  companyId: { type: [Number, String], default: null },
  projectId: { type: [Number, String], default: null },
})
const emit = defineEmits(['close', 'saved'])
const { t } = useI18n()

const editing = computed(() => !!props.initial)
const projects = ref([])
const saving = ref(false)
const error = ref('')

const src = props.initial || {}
const form = ref({
  company_id: Number(src.company_id || props.companyId || props.companies[0]?.id || 0) || null,
  title: src.title || '',
  description: src.description || '',
  category: src.category || 'change',
  priority: src.priority || 'normal',
  project_id: Number(src.project_id || props.projectId || 0) || null,
  reference_url: src.reference_url || '',
  video_url: src.video_url || '',
  requested_due_on: src.requested_due_on || '',
})

const companyProjects = computed(() => projects.value.filter((p) => Number(p.company_id) === Number(form.value.company_id)))

onMounted(async () => {
  try {
    const res = await api.get('/projects')
    projects.value = res.data?.data || []
  } catch {
    projects.value = []
  }
})

async function submit() {
  saving.value = true
  error.value = ''
  // 空欄は null で送る（url / date の検証が空文字で落ちないように）。
  const payload = Object.fromEntries(Object.entries(form.value).map(([k, v]) => [k, v === '' ? null : v]))
  try {
    const res = editing.value
      ? await api.put(`/requests/${props.initial.id}`, payload)
      : await api.post('/requests', payload)
    emit('saved', res.data.data)
  } catch (e) {
    error.value = e.response?.data?.message || t('requests.actionFailed')
  } finally {
    saving.value = false
  }
}
</script>
