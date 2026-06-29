<template>
  <div class="max-w-2xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-800 mb-6">New Task</h1>
    <div class="bg-white rounded-xl shadow p-6">
      <form @submit.prevent="submit" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Title <span class="text-red-400">*</span></label>
          <input v-model="form.title" required
            class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
          <textarea v-model="form.description" rows="4"
            class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
          <select v-model="form.type"
            class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
            <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
          </select>
          <p class="text-xs text-gray-400 mt-1">{{ typeHint }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Staging URL</label>
          <input v-model="form.staging_url" type="url" placeholder="https://staging.example.com"
            class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Due Date</label>
          <input v-model="form.due_date" type="date"
            class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
        </div>
        <p v-if="error" class="text-red-500 text-sm">{{ error }}</p>
        <div class="flex gap-3 justify-end">
          <button type="button" @click="$router.back()" class="px-4 py-2 border rounded-lg">Cancel</button>
          <button type="submit" :disabled="submitting"
            class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50">
            {{ submitting ? 'Creating...' : 'Create Task' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../api'

const route = useRoute()
const router = useRouter()
const id = route.params.id
const submitting = ref(false)
const error = ref('')

const types = [
  { value: 'design_review',     label: 'Design Review',      hint: 'Review a design / mockup before development.' },
  { value: 'staging_review',    label: 'Staging Review',     hint: 'Review the work on the staging environment.' },
  { value: 'deploy_approval',   label: 'Deploy Approval',    hint: 'Request approval to deploy to production.' },
  { value: 'dependency_update', label: 'Dependency Update',  hint: 'Report a Composer / Laravel / package update.' },
  { value: 'content_revision',  label: 'Content Revision',   hint: 'Request a content / copy change.' },
  { value: 'other',             label: 'Other',              hint: 'Any other request.' },
]

const form = ref({ title: '', description: '', type: 'staging_review', staging_url: '', due_date: '' })

const typeHint = computed(() => types.find(t => t.value === form.value.type)?.hint || '')

async function submit() {
  error.value = ''
  submitting.value = true
  try {
    const payload = { ...form.value }
    if (!payload.staging_url) delete payload.staging_url
    if (!payload.due_date) delete payload.due_date
    await api.post(`/projects/${id}/tasks`, payload)
    router.push(`/projects/${id}`)
  } catch (e) {
    error.value = e.response?.data?.message ||
      Object.values(e.response?.data?.errors || {}).flat().join(' ') ||
      'Failed to create task'
  } finally {
    submitting.value = false
  }
}
</script>
