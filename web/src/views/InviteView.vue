<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-100">
    <div class="bg-white shadow-lg rounded-xl p-8 w-full max-w-md">
      <div v-if="loading" class="text-center text-gray-400 py-8">Loading...</div>

      <div v-else-if="expired" class="text-center">
        <div class="text-red-500 text-4xl mb-4">⚠️</div>
        <h2 class="text-xl font-bold text-gray-800 mb-2">Invitation Expired</h2>
        <p class="text-gray-500 text-sm">This invitation link has expired or already been used.</p>
      </div>

      <div v-else>
        <h1 class="text-2xl font-bold text-gray-800 mb-1 text-center">Parlour</h1>
        <p class="text-center text-gray-500 text-sm mb-6">
          You've been invited to join <span class="font-semibold text-indigo-600">{{ company }}</span>
        </p>

        <form @submit.prevent="handleRegister" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
            <input v-model="form.name" type="text" required
              class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
            <input v-model="form.email" type="email" required
              class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
            <input v-model="form.password" type="password" required minlength="8"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
            <input v-model="form.password_confirmation" type="password" required
              class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          </div>
          <p v-if="error" class="text-red-500 text-sm">{{ error }}</p>
          <button type="submit" :disabled="submitting"
            class="w-full bg-indigo-600 text-white rounded-lg py-2 font-medium hover:bg-indigo-700 disabled:opacity-50">
            {{ submitting ? 'Creating account...' : 'Create Account & Join' }}
          </button>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../api'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const loading = ref(true)
const expired = ref(false)
const company = ref('')
const error = ref('')
const submitting = ref(false)
const form = ref({ name: '', email: '', password: '', password_confirmation: '' })

onMounted(async () => {
  try {
    const res = await api.get(`/invitations/${route.params.token}`)
    company.value = res.data.company.name
    if (res.data.email) form.value.email = res.data.email
  } catch (e) {
    expired.value = true
  } finally {
    loading.value = false
  }
})

async function handleRegister() {
  error.value = ''
  submitting.value = true
  try {
    const res = await api.post(`/invitations/${route.params.token}/accept`, form.value)
    auth.token = res.data.token
    localStorage.setItem('token', res.data.token)
    auth.user = res.data.user
    router.push('/client/dashboard')
  } catch (e) {
    error.value = e.response?.data?.message || Object.values(e.response?.data?.errors || {}).flat().join(' ') || 'Registration failed'
  } finally {
    submitting.value = false
  }
}
</script>
