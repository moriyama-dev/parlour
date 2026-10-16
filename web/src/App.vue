<template>
  <div class="min-h-screen takumi-gradient">
    <DemoBanner />
    <AppNav v-if="auth.isAuthenticated && !route.meta.fullscreen" />
    <router-view />
    <IncomingCallToast v-if="auth.isAuthenticated" />
  </div>
</template>

<script setup>
import { onMounted } from "vue"
import { useRoute } from "vue-router"
import AppNav from "./components/AppNav.vue"
import DemoBanner from "./components/DemoBanner.vue"
import IncomingCallToast from "./components/IncomingCallToast.vue"
import { useAuthStore } from "./stores/auth"

const auth = useAuthStore()
const route = useRoute()
onMounted(async () => {
  if (auth.token && !auth.user) {
    try { await auth.fetchUser() } catch {}
  }
})
</script>
