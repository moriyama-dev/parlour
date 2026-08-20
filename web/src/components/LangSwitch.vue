<template>
  <div class="takumi-langswitch" role="group" aria-label="Language">
    <button type="button" @click="choose('ja')" :class="{ active: locale === 'ja' }" :aria-pressed="locale === 'ja'">
      日本語
    </button>
    <button type="button" @click="choose('en')" :class="{ active: locale === 'en' }" :aria-pressed="locale === 'en'">
      EN
    </button>
  </div>
</template>

<script setup>
import { useI18n } from "vue-i18n"
import { setLocale } from "../i18n"

const { locale } = useI18n()

function choose(lang) {
  setLocale(lang)
}
</script>
