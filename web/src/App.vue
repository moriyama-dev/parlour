<template>
  <div class="min-h-screen takumi-gradient">
    <DemoBanner />
    <AppNav v-if="auth.isAuthenticated && !route.meta.fullscreen" />
    <router-view />
    <IncomingCallToast v-if="auth.isAuthenticated" />
  </div>
</template>

<script setup>
import { onMounted, watch } from "vue"
import { useI18n } from "vue-i18n"
import { useRoute } from "vue-router"
import AppNav from "./components/AppNav.vue"
import DemoBanner from "./components/DemoBanner.vue"
import IncomingCallToast from "./components/IncomingCallToast.vue"
import { useAuthStore } from "./stores/auth"
import { sync as syncPush } from "./services/push"

const auth = useAuthStore()
const route = useRoute()
const { locale } = useI18n()

// 通知を許可済みの端末は、ログインのたび・言語を切り替えるたびに持ち主と言語を伝え直す。
watch(() => [auth.user?.id, locale.value], ([id]) => { if (id) syncPush() }, { immediate: true })
onMounted(async () => {
  if (auth.token && !auth.user) {
    try { await auth.fetchUser() } catch {}
  }
})
</script>
