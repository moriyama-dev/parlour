<template>
  <div
    v-if="visible"
    role="status"
    class="fixed bottom-4 left-1/2 -translate-x-1/2 z-50 max-w-[calc(100%-2rem)] rounded-lg bg-ink px-4 py-2 text-sm text-white shadow-lg"
  >
    {{ t("shadow.readOnly") }}
  </div>
</template>

<script setup>
// Shadow ユーザー（CLAUDE.md §18）で書き込み操作をしたとき、API の 403 を受けて一言だけ知らせる。
import { onBeforeUnmount, onMounted, ref } from "vue"
import { useI18n } from "vue-i18n"

const { t } = useI18n()
const visible = ref(false)
let timer = null

function show() {
  visible.value = true
  clearTimeout(timer)
  timer = setTimeout(() => (visible.value = false), 3500)
}

onMounted(() => window.addEventListener("shadow-readonly", show))
onBeforeUnmount(() => {
  window.removeEventListener("shadow-readonly", show)
  clearTimeout(timer)
})
</script>
