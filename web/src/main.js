import { createApp } from "vue"
import { createPinia } from "pinia"
import App from "./App.vue"
import router from "./router"
import { i18n } from "./i18n"
import { registerServiceWorker } from "./services/push"
import "./style.css"

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.use(i18n)
app.mount("#app")

// 通知（Web Push）の受け口。ホーム画面に追加したときのアプリ化にも使われる。
registerServiceWorker(router)
