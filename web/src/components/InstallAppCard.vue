<template>
  <section v-if="visible" class="takumi-card p-5 mt-6 flex flex-col sm:flex-row items-center gap-4 text-center sm:text-left">
    <img src="/favicon-192.png" alt="" class="w-14 h-14 rounded-2xl shadow shrink-0" />
    <div class="flex-1 min-w-0">
      <div class="font-semibold text-ink">{{ t("install.title") }}</div>
      <p class="text-sm text-gray-500 mt-0.5">{{ t("install.lead") }}</p>
    </div>
    <div class="flex flex-col items-center gap-1 shrink-0">
      <button @click="install" class="btn-takumi px-5 py-2.5 font-medium cursor-pointer whitespace-nowrap">📲 {{ t("install.button") }}</button>
      <button @click="dismiss" class="text-xs text-gray-400 hover:text-gray-600 cursor-pointer">{{ t("install.later") }}</button>
    </div>

    <!-- 手順の案内（iPhone、またはブラウザがボタンからのインストールに対応していないとき） -->
    <div v-if="showGuide" class="fixed inset-0 bg-black/50 z-50 flex items-end sm:items-center justify-center p-4 text-left" @click.self="showGuide = false">
      <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm p-6">
        <h3 class="font-serif text-lg font-semibold text-ink mb-4">{{ t("install.guideTitle") }}</h3>

        <!-- iPhone の Chrome などでは追加できないので、Safari で開き直してもらう -->
        <template v-if="os === 'ios-other'">
          <p class="text-sm text-gray-600">{{ t("install.openInSafari") }}</p>
          <button @click="copyUrl" class="mt-4 w-full border border-indigo-600 text-indigo-600 rounded-lg py-2 text-sm cursor-pointer">
            {{ copied ? t("install.copied") : t("install.copyUrl") }}
          </button>
        </template>

        <ol v-else class="space-y-4">
          <li v-for="(step, i) in steps" :key="i" class="flex gap-3 items-start">
            <span class="w-7 h-7 rounded-full bg-ink text-white text-sm font-semibold flex items-center justify-center shrink-0">{{ i + 1 }}</span>
            <div class="text-sm text-gray-700 pt-0.5">
              {{ step.text }}
              <!-- Safari の共有ボタンの形（□に↑） -->
              <svg v-if="step.icon === 'share'" class="inline-block w-5 h-5 ml-1 -mt-1 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 3v12" /><path d="M8 7l4-4 4 4" /><path d="M6 11H5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-8a1 1 0 0 0-1-1h-1" />
              </svg>
              <span v-if="step.icon === 'plus'" class="inline-flex items-center justify-center w-5 h-5 ml-1 border border-gray-400 rounded text-xs text-gray-600" aria-hidden="true">＋</span>
              <span v-if="step.icon === 'menu'" class="ml-1 font-bold text-gray-600" aria-hidden="true">︙</span>
            </div>
          </li>
        </ol>

        <p v-if="os === 'ios-safari'" class="text-xs text-gray-400 mt-4">{{ t("install.iosNote") }}</p>
        <button @click="showGuide = false" class="mt-5 w-full bg-indigo-600 text-white rounded-lg py-2 text-sm cursor-pointer">{{ t("common.close") }}</button>
      </div>
    </div>
  </section>
</template>

<script setup>
import { ref, computed } from "vue"
import { useI18n } from "vue-i18n"
import { installState, platform, promptInstall } from "../services/install"

const { t } = useI18n()
const os = platform()
const showGuide = ref(false)
const copied = ref(false)

// 「今は表示しない」は端末ごとの好み。保存できない環境（プライベートモード等）でも動くように。
const KEY = "takumi_install_dismissed"
const dismissed = ref((() => { try { return localStorage.getItem(KEY) === "1" } catch { return false } })())

const visible = computed(() => !installState.installed && !dismissed.value)

const steps = computed(() => {
  if (os === "ios-safari") return [
    { text: t("install.ios1"), icon: "share" },
    { text: t("install.ios2"), icon: "plus" },
    { text: t("install.ios3") },
  ]
  if (os === "android") return [
    { text: t("install.android1"), icon: "menu" },
    { text: t("install.android2") },
  ]
  return [
    { text: t("install.desktop1") },
    { text: t("install.desktop2") },
  ]
})

async function install() {
  // Chrome / Edge はここで本物の確認ダイアログが出る。出せないときだけ手順を案内する。
  if (installState.canPrompt && await promptInstall()) return
  showGuide.value = true
}

function dismiss() {
  dismissed.value = true
  try { localStorage.setItem(KEY, "1") } catch {}
}

async function copyUrl() {
  try {
    await navigator.clipboard.writeText(location.origin)
    copied.value = true
  } catch {}
}
</script>
