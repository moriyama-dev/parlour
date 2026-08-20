<template>
  <div class="min-h-screen flex items-center justify-center takumi-gradient px-4 relative">
    <div class="absolute top-5 right-5">
      <LangSwitch />
    </div>
    <div class="w-full max-w-md">
      <div class="flex flex-col items-center mb-7">
        <img src="/logo.png" alt="Takumi" class="h-16 w-16 object-contain mb-4" />
        <h1 class="font-serif text-3xl font-semibold text-ink text-center leading-tight">Takumi Web Services</h1>
        <div class="takumi-gold-rule mt-3"></div>
        <p class="text-sm text-gray-500 mt-3">{{ t("login.subtitle") }}</p>
      </div>
      <div class="takumi-card p-8">
        <form @submit.prevent="handleLogin" class="space-y-5">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">{{ t("login.email") }}</label>
            <input v-model="email" type="email" required
              class="w-full bg-sand border border-mist rounded-xl px-4 py-2.5 text-ink focus:outline-none focus:ring-2 focus:ring-gold/60 focus:border-gold transition" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">{{ t("login.password") }}</label>
            <input v-model="password" type="password" required
              class="w-full bg-sand border border-mist rounded-xl px-4 py-2.5 text-ink focus:outline-none focus:ring-2 focus:ring-gold/60 focus:border-gold transition" />
          </div>
          <p v-if="error" class="text-red-500 text-sm">{{ error }}</p>
          <button type="submit" :disabled="loading"
            class="btn-takumi w-full py-2.5 font-medium disabled:opacity-50">
            {{ loading ? t("login.signingIn") : t("login.signIn") }}
          </button>
        </form>
      </div>

      <!-- 採用担当・見学者向けの公開デモアカウント（デモサイトのみ表示） -->
      <div v-if="isDemo" class="takumi-card p-5 mt-5">
        <p class="text-sm font-semibold text-ink">{{ t("login.demo.heading") }}</p>
        <p class="text-xs text-gray-500 mt-1 mb-3">{{ t("login.demo.lead") }}</p>
        <div class="space-y-2">
          <div v-for="acc in demoAccounts" :key="acc.role"
            class="flex items-center justify-between gap-3 bg-sand border border-mist rounded-xl px-3 py-2">
            <div class="min-w-0">
              <div class="text-xs font-medium text-navy">{{ t(acc.labelKey) }}</div>
              <div class="text-xs text-gray-500 truncate">{{ acc.email }} / {{ acc.password }}</div>
            </div>
            <button type="button" @click="useDemo(acc)"
              class="shrink-0 text-xs font-semibold text-gold hover:text-navy transition whitespace-nowrap">
              {{ t("login.demo.fill") }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from "vue"
import { useRouter } from "vue-router"
import { useI18n } from "vue-i18n"
import { useAuthStore } from "../stores/auth"
import LangSwitch from "../components/LangSwitch.vue"
import { DEMO_ACCOUNTS, IS_DEMO } from "../demo"

const { t } = useI18n()
const auth = useAuthStore()
const router = useRouter()
const email = ref("")
const password = ref("")
const error = ref("")
const loading = ref(false)
const demoAccounts = DEMO_ACCOUNTS
const isDemo = IS_DEMO

function useDemo(acc) {
  email.value = acc.email
  password.value = acc.password
}

async function handleLogin() {
  error.value = ""
  loading.value = true
  try {
    await auth.login(email.value, password.value)
    router.push(auth.isDeveloper ? "/dashboard" : "/client/dashboard")
  } catch (e) {
    error.value = e.response?.data?.message || t("login.failed")
  } finally {
    loading.value = false
  }
}
</script>
