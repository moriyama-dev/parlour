import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const routes = [
  { path: '/', redirect: '/dashboard' },
  { path: '/login', component: () => import('../views/LoginView.vue'), meta: { guest: true } },
  { path: '/invite/:token', component: () => import('../views/InviteView.vue') },
  { path: '/dashboard', component: () => import('../views/DashboardView.vue'), meta: { auth: true, role: 'developer' } },
  { path: '/companies', component: () => import('../views/CompaniesView.vue'), meta: { auth: true, role: 'developer' } },
  { path: '/clients', component: () => import('../views/ClientsView.vue'), meta: { auth: true, role: 'developer' } },
  { path: '/projects', component: () => import('../views/ProjectsView.vue'), meta: { auth: true, role: 'developer' } },
  { path: '/messages', component: () => import('../views/MessagesView.vue'), meta: { auth: true } },
  { path: '/projects/:id', component: () => import('../views/ProjectDetailView.vue'), meta: { auth: true } },
  { path: '/projects/:id/tasks/create', component: () => import('../views/TaskCreateView.vue'), meta: { auth: true, role: 'developer' } },
  { path: '/projects/:id/invoices/create', component: () => import('../views/InvoiceCreateView.vue'), meta: { auth: true, role: 'developer' } },
  { path: '/client/dashboard', component: () => import('../views/ClientDashboardView.vue'), meta: { auth: true, role: 'client' } },
  { path: '/client/tasks/:id', component: () => import('../views/ClientTaskView.vue'), meta: { auth: true, role: 'client' } },
  { path: '/client/projects/:id/messages', component: () => import('../views/ClientMessagesView.vue'), meta: { auth: true, role: 'client' } },
  { path: '/client/invoices/:id', component: () => import('../views/ClientInvoiceView.vue'), meta: { auth: true, role: 'client' } },
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
