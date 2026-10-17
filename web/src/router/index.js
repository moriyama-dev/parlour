import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const routes = [
  { path: '/', redirect: '/dashboard' },
  { path: '/login', component: () => import('../views/LoginView.vue'), meta: { guest: true } },
  { path: '/invite/:token', component: () => import('../views/InviteView.vue') },
  { path: '/dashboard', component: () => import('../views/DashboardView.vue'), meta: { auth: true, role: 'developer' } },
  { path: '/companies', component: () => import('../views/CompaniesView.vue'), meta: { auth: true, role: 'developer' } },
  { path: '/clients', component: () => import('../views/ClientsView.vue'), meta: { auth: true, role: 'developer' } },
  // 案件（部屋）は顧客も開ける。作れる会社の照合はサーバー側（ProjectController::store）。
  { path: '/projects', component: () => import('../views/ProjectsView.vue'), meta: { auth: true } },
  { path: '/messages', component: () => import('../views/MessagesView.vue'), meta: { auth: true } },
  // 「イメージ」= ホワイトボード。一覧（Miro のダッシュボード相当）と編集画面（ボード相当）。
  // どちらも開発者・顧客で共通（見える範囲は API 側の Board::visibleTo が決める）。
  { path: '/boards', component: () => import('../views/BoardsView.vue'), meta: { auth: true } },
  { path: '/boards/:id', component: () => import('../views/BoardView.vue'), meta: { auth: true, fullscreen: true } },
  // 料金：サービス内容と、案件をまたいだ請求書・領収書
  { path: '/billing', component: () => import('../views/BillingView.vue'), meta: { auth: true } },
  { path: '/billing/invoices/:id', component: () => import('../views/BillingInvoiceView.vue'), meta: { auth: true } },
  { path: '/billing/invoices/:id/receipt', component: () => import('../views/BillingInvoiceView.vue'), meta: { auth: true } },
  { path: '/projects/:id', component: () => import('../views/ProjectDetailView.vue'), meta: { auth: true } },
  { path: '/projects/:id/tasks/create', component: () => import('../views/TaskCreateView.vue'), meta: { auth: true, role: 'developer' } },
  { path: '/projects/:id/invoices/create', component: () => import('../views/InvoiceCreateView.vue'), meta: { auth: true, role: 'developer' } },
  // 通話画面は開発者と顧客で共通（アクセス可否は API 側の project.access が見る）
  { path: '/projects/:id/calls/:callId', component: () => import('../views/CallView.vue'), meta: { auth: true } },
  // 依頼キュー（Conte Studios の VIP 方式）。開発者・顧客で共通（見える範囲は API 側）。
  { path: '/requests', component: () => import('../views/RequestsView.vue'), meta: { auth: true } },
  { path: '/requests/:id', component: () => import('../views/RequestDetailView.vue'), meta: { auth: true } },
  // 推薦文と紹介。顧客は書く、開発者は読む。
  { path: '/feedback', component: () => import('../views/FeedbackView.vue'), meta: { auth: true } },
  // 端末への通知（Web Push）の設定。開発者・顧客で共通。
  { path: '/settings/notifications', component: () => import('../views/NotificationSettingsView.vue'), meta: { auth: true } },
  { path: '/client/dashboard', component: () => import('../views/ClientDashboardView.vue'), meta: { auth: true, role: 'client' } },
  { path: '/client/tasks/:id', component: () => import('../views/ClientTaskView.vue'), meta: { auth: true, role: 'client' } },
  { path: '/client/projects/:id/messages', component: () => import('../views/ClientMessagesView.vue'), meta: { auth: true, role: 'client' } },
  // 旧リンク（通知やブックマーク）は新しい請求書ページへ送る。
  { path: '/client/invoices/:id', redirect: (to) => `/billing/invoices/${to.params.id}` },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  // リロード直後など、トークンはあるがユーザー未取得のときは取得完了を待つ
  if (auth.token && !auth.user) {
    await auth.fetchUser()
  }
  if (to.meta.auth && !auth.isAuthenticated) return '/login'
  if (to.meta.guest && auth.isAuthenticated) return auth.isDeveloper ? '/dashboard' : '/client/dashboard'
  if (to.meta.role === 'developer' && auth.isAuthenticated && !auth.isDeveloper) return '/client/dashboard'
  if (to.meta.role === 'client' && auth.isAuthenticated && !auth.isClient) return '/dashboard'
})

export default router
