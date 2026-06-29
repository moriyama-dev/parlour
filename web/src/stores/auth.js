import { defineStore } from "pinia"
import api from "../api"

export const useAuthStore = defineStore("auth", {
  state: () => ({
    user: null,
    token: localStorage.getItem("token"),
  }),
  getters: {
    isAuthenticated: (state) => !!state.token && !!state.user,
    isDeveloper: (state) => state.user?.role === "developer",
    isClient: (state) => state.user?.role === "client",
  },
  actions: {
    async login(email, password) {
      const res = await api.post("/auth/login", { email, password })
      this.token = res.data.token
      localStorage.setItem("token", this.token)
      this.user = res.data.user?.data || res.data.user
      if (!this.user) await this.fetchUser()
    },
    async logout() {
      try { await api.post("/auth/logout") } catch {}
      this.token = null
      this.user = null
      localStorage.removeItem("token")
    },
    async fetchUser() {
      if (!this.token) return
      try {
        const res = await api.get("/auth/me")
        this.user = res.data?.data || res.data
      } catch (e) {
        if (e.response?.status === 401) {
          this.token = null
          this.user = null
          localStorage.removeItem("token")
        }
      }
    },
  },
})
