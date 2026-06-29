<template>
  <div class="max-w-3xl mx-auto px-4 py-8">
    <h1 class="text-xl font-bold text-gray-800 mb-6">Messages</h1>

    <div class="space-y-4 mb-6">
      <div v-if="messages.length === 0" class="text-gray-400 text-sm py-8 text-center">
        No messages yet. Start the conversation below.
      </div>

      <div v-for="msg in messages" :key="msg.id" class="bg-white rounded-xl shadow px-4 py-3">
        <!-- Top-level message -->
        <div class="flex justify-between items-start">
          <div class="flex items-center gap-2">
            <div class="w-8 h-8 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600 text-xs font-bold">
              {{ initials(msg.user?.name) }}
            </div>
            <span class="font-medium text-gray-800 text-sm">{{ msg.user?.name }}</span>
          </div>
          <span class="text-xs text-gray-400">{{ formatDate(msg.created_at) }}</span>
        </div>
        <div class="mt-2 text-gray-700 text-sm whitespace-pre-wrap pl-10">{{ msg.body }}</div>
        <div class="pl-10 mt-1">
          <button @click="toggleReply(msg.id, msg.user?.name)" class="text-xs text-indigo-500 hover:text-indigo-700">Reply</button>
        </div>

        <div v-if="replyingTo === msg.id" class="pl-10 mt-3 flex gap-2">
          <input v-model="replyBody" :placeholder="`Reply to ${replyingToUser}...`" @keyup.enter="sendReply(msg.id)"
            class="flex-1 border rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          <button @click="sendReply(msg.id)" class="bg-indigo-600 text-white px-3 py-1.5 rounded-lg text-sm hover:bg-indigo-700">Reply</button>
          <button @click="cancelReply" class="px-3 py-1.5 border rounded-lg text-sm text-gray-500">Cancel</button>
        </div>

        <!-- First-level replies -->
        <div v-if="msg.replies && msg.replies.length" class="pl-10 mt-3 space-y-3 border-l-2 border-gray-100 ml-4">
          <div v-for="r in msg.replies" :key="r.id" class="pl-4">
            <div class="flex justify-between items-start">
              <div class="flex items-center gap-2">
                <div class="w-6 h-6 rounded-full bg-gray-100 flex items-center justify-center text-gray-500 text-[10px] font-bold">
                  {{ initials(r.user?.name) }}
                </div>
                <span class="font-medium text-gray-700 text-xs">{{ r.user?.name }}</span>
              </div>
              <span class="text-xs text-gray-400">{{ formatDate(r.created_at) }}</span>
            </div>
            <div class="mt-1 text-gray-600 text-sm whitespace-pre-wrap pl-8">{{ r.body }}</div>
            <div class="pl-8 mt-1">
              <button @click="toggleReply(r.id, r.user?.name)" class="text-xs text-indigo-400 hover:text-indigo-600">Reply</button>
            </div>

            <div v-if="replyingTo === r.id" class="pl-8 mt-2 flex gap-2">
              <input v-model="replyBody" :placeholder="`Reply to ${replyingToUser}...`" @keyup.enter="sendReply(r.id)"
                class="flex-1 border rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
              <button @click="sendReply(r.id)" class="bg-indigo-600 text-white px-3 py-1.5 rounded-lg text-sm hover:bg-indigo-700">Reply</button>
              <button @click="cancelReply" class="px-3 py-1.5 border rounded-lg text-sm text-gray-500">Cancel</button>
            </div>

            <!-- Second-level replies -->
            <div v-if="r.replies?.length" class="pl-8 mt-2 space-y-2 border-l-2 border-gray-50 ml-2">
              <div v-for="rr in r.replies" :key="rr.id" class="pl-3">
                <div class="flex justify-between items-start">
                  <div class="flex items-center gap-2">
                    <div class="w-5 h-5 rounded-full bg-gray-50 flex items-center justify-center text-gray-400 text-[9px] font-bold">
                      {{ initials(rr.user?.name) }}
                    </div>
                    <span class="font-medium text-gray-600 text-xs">{{ rr.user?.name }}</span>
                  </div>
                  <span class="text-xs text-gray-400">{{ formatDate(rr.created_at) }}</span>
                </div>
                <div class="mt-1 text-gray-600 text-sm whitespace-pre-wrap pl-7">{{ rr.body }}</div>
                <div class="pl-7 mt-1">
                  <button @click="toggleReply(r.id, r.user?.name)" class="text-xs text-indigo-300 hover:text-indigo-500">Reply</button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <form @submit.prevent="send" class="flex gap-2 sticky bottom-4">
      <input v-model="body" placeholder="Type a message..."
        class="flex-1 border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
      <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700">Send</button>
    </form>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import api from '../api'

const route = useRoute()
const id = route.params.id
const messages = ref([])
const body = ref('')
const replyingTo = ref(null)
const replyingToUser = ref('')
const replyBody = ref('')

function formatDate(d) {
  if (!d) return ''
  return new Date(d).toLocaleString('en-CA', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

function initials(name) {
  if (!name) return '?'
  return name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase()
}

async function load() {
  const res = await api.get(`/projects/${id}/messages`)
  messages.value = res.data?.data || res.data || []
}

onMounted(load)

async function send() {
  if (!body.value.trim()) return
  await api.post(`/projects/${id}/messages`, { body: body.value })
  body.value = ''
  await load()
}

function toggleReply(replyId, userName) {
  if (replyingTo.value === replyId) {
    replyingTo.value = null
    replyingToUser.value = ''
  } else {
    replyingTo.value = replyId
    replyingToUser.value = userName || ''
  }
  replyBody.value = ''
}

function cancelReply() {
  replyingTo.value = null
  replyingToUser.value = ''
  replyBody.value = ''
}

async function sendReply(parentId) {
  if (!replyBody.value.trim()) return
  await api.post(`/projects/${id}/messages`, { body: replyBody.value, parent_id: parentId })
  replyBody.value = ''
  replyingTo.value = null
  replyingToUser.value = ''
  await load()
}
</script>
