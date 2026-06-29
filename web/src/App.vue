<template>
  <div class="min-h-screen bg-gray-50">
    <AppNav v-if="auth.isAuthenticated" />
    <router-view />
  </div>
</template>

<script setup>
import { onMounted } from "vue"
import AppNav from "./components/AppNav.vue"
import { useAuthStore } from "./stores/auth"

const auth = useAuthStore()
onMounted(async () => {
  if (auth.token && !auth.user) {
    try { await auth.fetchUser() } catch {}
  }
})
</script>
