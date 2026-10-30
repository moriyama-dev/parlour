import axios from "axios"

const api = axios.create({ baseURL: "/api" })

api.interceptors.request.use((config) => {
  const token = localStorage.getItem("token")
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

api.interceptors.response.use(
  (res) => res,
  (err) => {
    if (err.response?.status === 401) {
      localStorage.removeItem("token")
      window.location.href = "/login"
    }
    // Shadow ユーザー（閲覧専用）の書き込みは API が 403 で断る。画面は本人と同じに見せたまま、知らせるだけ。
    if (err.response?.status === 403 && err.response.data?.shadow) {
      window.dispatchEvent(new CustomEvent("shadow-readonly"))
    }
    return Promise.reject(err)
  }
)

export default api
