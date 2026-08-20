<template>
  <div class="min-h-screen takumi-gradient">
    <DemoBanner />
    <AppNav v-if="auth.isAuthenticated" />
    <router-view />
  </div>
</template>

<script setup>
import { onMounted } from "vue"
import AppNav from "./components/AppNav.vue"
import DemoBanner from "./components/DemoBanner.vue"
import { useAuthStore } from "./stores/auth"

const auth = useAuthStore()
onMounted(async () => {
  if (auth.token && !auth.user) {
    try { await auth.fetchUser() } catch {}
  }
})
</script>
