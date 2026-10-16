<template>
  <video ref="el" autoplay playsinline :muted="muted"
    class="w-full h-full bg-black"
    :class="[contain ? 'object-contain' : 'object-cover', mirror ? 'scale-x-[-1]' : '']" />
</template>

<script setup>
/*
 * MediaStream は Vue のリアクティブに載せてはいけない（Proxy に包まれると
 * ブラウザ側の API が受け付けない）ので、srcObject への代入だけをここに閉じ込める。
 */
import { ref, watch, onMounted } from 'vue'

const props = defineProps({
  stream: { type: Object, default: null },
  muted: { type: Boolean, default: false },
  mirror: { type: Boolean, default: false },
  // 画面共有は端を切ると資料が読めなくなるので contain で全体を映す。
  contain: { type: Boolean, default: false },
})

const el = ref(null)

function attach() {
  if (!el.value) return
  if (el.value.srcObject !== props.stream) {
    el.value.srcObject = props.stream || null
  }
  // 自動再生が拒否されることがある（タブが非アクティブなど）。失敗しても
  // ユーザー操作のたびに再生が再開するので、握りつぶしてよい。
  el.value.play?.().catch(() => {})
}

onMounted(attach)
watch(() => props.stream, attach)
</script>
