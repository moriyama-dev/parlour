<template>
  <div class="max-w-2xl mx-auto px-4 py-8 space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-800">{{ t("pushSettings.title") }}</h1>
      <p class="text-sm text-gray-500 mt-1">{{ t("pushSettings.lead") }}</p>
    </div>

    <div v-if="loading" class="text-center text-gray-400 py-16">{{ t("common.loading") }}</div>

    <template v-else>
      <!-- この端末 -->
      <section class="bg-white rounded-xl shadow px-6 py-5">
        <h2 class="font-semibold text-gray-700 mb-3">{{ t("pushSettings.thisDevice") }}</h2>

        <p v-if="!serverEnabled" class="text-sm text-gray-500">{{ t("pushSettings.notConfigured") }}</p>

        <!-- iPhone/iPad は「ホーム画面に追加」したアプリでしか通知を受け取れない -->
        <div v-else-if="needsInstall" class="text-sm text-gray-600 space-y-2">
          <p>{{ t("pushSettings.iosInstallLead") }}</p>
          <ol class="list-decimal pl-5 space-y-1">
            <li>{{ t("pushSettings.iosStep1") }}</li>
            <li>{{ t("pushSettings.iosStep2") }}</li>
            <li>{{ t("pushSettings.iosStep3") }}</li>
          </ol>
        </div>

        <p v-else-if="!supported" class="text-sm text-gray-500">{{ t("pushSettings.unsupported") }}</p>

        <p v-else-if="perm === 'denied'" class="text-sm text-amber-700 bg-amber-50 rounded-lg px-3 py-2">
          {{ t("pushSettings.denied") }}
        </p>

        <div v-else class="flex flex-wrap items-center gap-3">
          <span class="text-sm font-medium" :class="subscribed ? 'text-emerald-600' : 'text-gray-500'">
            {{ subscribed ? t("pushSettings.on") : t("pushSettings.off") }}
          </span>
          <div class="flex flex-wrap gap-2 ml-auto">
            <button v-if="!subscribed" @click="turnOn" :disabled="busy"
              class="bg-indigo-600 text-white text-sm px-4 py-2 rounded-lg hover:bg-indigo-700 disabled:opacity-50 cursor-pointer">
              {{ t("pushSettings.turnOn") }}
            </button>
            <template v-else>
              <button @click="sendTest" :disabled="busy"
                class="text-sm border border-indigo-300 text-indigo-600 px-3 py-1.5 rounded-lg hover:bg-indigo-50 disabled:opacity-50 cursor-pointer">
                {{ t("pushSettings.test") }}
              </button>
              <button @click="turnOff" :disabled="busy"
                class="text-sm border border-gray-300 px-3 py-1.5 rounded-lg hover:bg-gray-50 disabled:opacity-50 cursor-pointer">
                {{ t("pushSettings.turnOff") }}
              </button>
            </template>
          </div>
        </div>

        <p v-if="status" class="text-sm mt-3" :class="statusError ? 'text-red-600' : 'text-gray-600'">{{ status }}</p>
        <p v-if="showAndroidTip" class="text-xs text-gray-400 mt-3">{{ t("pushSettings.androidTip") }}</p>
      </section>

      <!-- 受け取る通知 -->
      <section class="bg-white rounded-xl shadow px-6 py-5">
        <h2 class="font-semibold text-gray-700">{{ t("pushSettings.categories") }}</h2>
        <p class="text-xs text-gray-400 mt-1 mb-3">{{ t("pushSettings.categoriesNote") }}</p>
        <div class="divide-y">
          <label v-for="c in categories" :key="c" class="flex items-center justify-between gap-4 py-3 cursor-pointer">
            <div>
              <div class="text-sm font-medium text-gray-800">{{ t(`pushSettings.cat.${c}`) }}</div>
              <div class="text-xs text-gray-500">{{ t(`pushSettings.catHint.${c}`) }}</div>
            </div>
            <input type="checkbox" class="sr-only peer" :checked="prefs[c]" @change="togglePref(c, $event.target.checked)" />
            <span class="relative inline-block w-10 h-6 shrink-0 rounded-full bg-gray-300 transition peer-checked:bg-indigo-600
                         after:absolute after:top-0.5 after:left-0.5 after:w-5 after:h-5 after:rounded-full after:bg-white after:transition
                         peer-checked:after:translate-x-4"></span>
          </label>
        </div>
      </section>

      <!-- 登録済みの端末 -->
      <section v-if="devices.length" class="bg-white rounded-xl shadow px-6 py-5">
        <h2 class="font-semibold text-gray-700 mb-3">{{ t("pushSettings.devices") }}</h2>
        <div class="divide-y">
          <div v-for="d in devices" :key="d.id" class="flex items-center justify-between gap-3 py-3">
            <div class="min-w-0">
              <div class="text-sm text-gray-800 truncate">
                {{ d.device_name || t("pushSettings.unknownDevice") }}
                <span v-if="d.endpoint_hash === currentHash"
                  class="ml-1 text-xs bg-indigo-50 text-indigo-600 px-2 py-0.5 rounded-full">{{ t("pushSettings.current") }}</span>
              </div>
              <div class="text-xs text-gray-400">
                {{ t("pushSettings.lastUsed") }}: {{ d.last_used_at ? formatDate(d.last_used_at) : t("common.none") }}
              </div>
            </div>
            <button @click="removeDevice(d)" class="text-sm text-gray-400 hover:text-red-500 shrink-0 cursor-pointer">{{ t("pushSettings.remove") }}</button>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from "vue"
import { useI18n } from "vue-i18n"
import api from "../api"
import { activeDateLocale } from "../i18n"
import * as push from "../services/push"

const { t } = useI18n()
const categories = ["message", "task", "invoice", "call", "subscription"]

const loading = ref(true)
const busy = ref(false)
const serverEnabled = ref(false)
const publicKey = ref("")
const prefs = ref({})
const devices = ref([])
const subscribed = ref(false)
const currentHash = ref("")
const perm = ref(push.permission())
const status = ref("")
const statusError = ref(false)

const supported = push.isSupported()
const needsInstall = push.isIos() && !push.isStandalone()
const showAndroidTip = /Android/.test(navigator.userAgent) && !push.isStandalone()

function formatDate(iso) {
  return new Date(iso).toLocaleString(activeDateLocale())
}

function say(msg, isError = false) {
  status.value = msg
  statusError.value = isError
}

async function load() {
  const { data } = await api.get("/push")
  serverEnabled.value = data.enabled
  publicKey.value = data.public_key
  prefs.value = data.preferences
  devices.value = data.devices
  const sub = await push.currentSubscription().catch(() => null)
  subscribed.value = !!sub && data.devices.length > 0
  currentHash.value = sub ? await push.endpointHash(sub.endpoint) : ""
  if (sub && !data.devices.some((d) => d.endpoint_hash === currentHash.value)) subscribed.value = false
}

async function turnOn() {
  busy.value = true
  say("")
  try {
    await push.enable(publicKey.value)
    await load()
    say(t("pushSettings.enabled"))
  } catch (e) {
    perm.value = push.permission()
    say(e.message === "denied" ? t("pushSettings.denied") : t("pushSettings.failed"), true)
  } finally {
    busy.value = false
  }
}

async function turnOff() {
  busy.value = true
  say("")
  try {
    await push.disable()
    await load()
  } catch {
    say(t("pushSettings.failed"), true)
  } finally {
    busy.value = false
  }
}

async function sendTest() {
  busy.value = true
  try {
    const { data } = await api.post("/push/test")
    say(data.delivered > 0 ? t("pushSettings.testSent", { count: data.delivered }) : t("pushSettings.testNone"), data.delivered === 0)
  } catch {
    say(t("pushSettings.failed"), true)
  } finally {
    busy.value = false
  }
}

async function togglePref(category, value) {
  const before = prefs.value[category]
  prefs.value = { ...prefs.value, [category]: value }
  try {
    const { data } = await api.put("/push/preferences", { [category]: value })
    prefs.value = data.preferences
  } catch {
    prefs.value = { ...prefs.value, [category]: before }
    say(t("pushSettings.failed"), true)
  }
}

async function removeDevice(d) {
  if (d.endpoint_hash === currentHash.value) return turnOff()
  await api.delete("/push/subscriptions", { data: { id: d.id } })
  devices.value = devices.value.filter((x) => x.id !== d.id)
}

onMounted(async () => {
  try {
    await load()
  } finally {
    loading.value = false
  }
})
</script>
