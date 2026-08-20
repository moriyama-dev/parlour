<template>
  <div v-if="isDemo" class="takumi-demobanner">
    <span>🔎 {{ t("demo.banner") }}</span>
  </div>
</template>

<script setup>
import { useI18n } from "vue-i18n"
import { IS_DEMO } from "../demo"

const { t } = useI18n()
const isDemo = IS_DEMO
</script>
