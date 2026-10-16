<template>
  <!--
    「イメージ」一覧 = Miro のダッシュボード相当。
    左に固定サイドバー、右にテンプレート行とボード一覧（グリッド / リスト）。
  -->
  <div class="boards-dash flex bg-white text-[#1c2f45]" style="min-height: calc(100vh - 61px)">
    <!-- サイドバー -->
    <aside class="hidden md:flex w-60 shrink-0 flex-col border-r border-[#e3ddd1] bg-[#faf7f0] py-4">
      <button class="flex items-center gap-3 px-4 py-2 mx-2 rounded-lg hover:bg-[#f3efe6] text-left cursor-pointer">
        <img src="/logo.png" alt="" class="w-9 h-9 object-contain shrink-0" />
        <span class="min-w-0">
          <span class="block text-sm font-serif font-semibold truncate">{{ t('boards.team') }}</span>
          <span class="block text-xs text-[#6b7c93]">{{ t('boards.teamSub') }}</span>
        </span>
        <BoardIcon name="chevron-down" :size="16" class="ml-auto text-[#6b7c93]" />
      </button>

      <nav class="mt-4 px-2 space-y-0.5">
        <button v-for="s in sections" :key="s.key" @click="section = s.key"
          class="w-full flex items-center gap-3 px-3 py-2 rounded-lg text-sm cursor-pointer transition"
          :class="section === s.key ? 'bg-[#e6eef3] text-[#2f5d7c] font-semibold' : 'hover:bg-[#f3efe6] text-[#1c2f45]'">
          <BoardIcon :name="s.icon" :size="18" />{{ t(s.label) }}
        </button>
      </nav>

      <div class="mt-6 px-5 text-[11px] font-semibold uppercase tracking-wider text-[#6b7c93]">{{ t('boards.spaces') }}</div>
      <div class="mt-1 px-2">
        <button @click="section = 'home'" class="w-full flex items-center gap-3 px-3 py-2 rounded-lg text-sm hover:bg-[#f3efe6] cursor-pointer">
          <span class="w-5 h-5 rounded-full bg-[#c8a46b]/30 text-[10px] font-bold flex items-center justify-center text-[#8a6a35]">P</span>{{ t('boards.personal') }}
        </button>
      </div>

      <div class="mt-auto px-4 text-xs text-[#6b7c93]">
        <div class="flex items-center gap-2">
          <span class="w-6 h-6 rounded-full bg-[#1c2f45] text-white text-[10px] font-bold flex items-center justify-center">{{ initials(auth.user?.name) }}</span>
          <span class="truncate">{{ auth.user?.name }}</span>
        </div>
      </div>
    </aside>

    <!-- メイン -->
    <main class="flex-1 min-w-0 px-4 sm:px-8 py-6">
      <!-- 検索バー -->
      <div class="flex items-center gap-3 mb-6">
        <label class="relative flex-1 max-w-xl">
          <BoardIcon name="search" :size="18" class="absolute left-3 top-1/2 -translate-y-1/2 text-[#6b7c93]" />
          <input v-model="query" type="search" :placeholder="t('boards.search')"
            class="w-full rounded-full border border-[#e3ddd1] bg-[#faf7f0] pl-10 pr-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#c8a46b]/50 focus:bg-white" />
        </label>
        <!-- モバイルではサイドバーが無いので、セクション切替をここに出す -->
        <select v-model="section" class="md:hidden rounded-lg border border-[#e3ddd1] px-2 py-2 text-sm bg-white">
          <option v-for="s in sections" :key="s.key" :value="s.key">{{ t(s.label) }}</option>
        </select>
      </div>

      <!-- テンプレート行（Miro の "Create a board"） -->
      <section v-if="section === 'home' || section === 'explore'" class="mb-10">
        <div class="flex items-center justify-between mb-3">
          <h2 class="text-lg font-serif font-semibold">{{ t('boards.createTitle') }}</h2>
          <button @click="section = section === 'explore' ? 'home' : 'explore'" class="text-sm text-[#2f5d7c] hover:underline cursor-pointer">
            {{ t('boards.exploreTemplates') }}
          </button>
        </div>
        <div class="grid gap-3" :class="section === 'explore' ? 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5' : 'grid-flow-col auto-cols-[10rem] overflow-x-auto pb-2'">
          <button @click="createBoard('blank')" :disabled="creating"
            class="group h-[8.5rem] rounded-2xl border-2 border-dashed border-[#d9d2c4] hover:border-[#c8a46b] hover:bg-[#faf7f0] flex flex-col items-center justify-center gap-2 cursor-pointer transition disabled:opacity-60">
            <span class="w-12 h-12 rounded-full bg-[#1c2f45] text-[#f6e7a6] flex items-center justify-center shadow-md group-hover:scale-105 transition">
              <BoardIcon name="plus" :size="26" />
            </span>
            <span class="text-sm font-semibold">{{ t('boards.newBoard') }}</span>
          </button>
          <button v-for="key in templateKeys" :key="key" @click="createBoard(key)" :disabled="creating"
            class="group h-[8.5rem] rounded-2xl border border-[#e3ddd1] hover:border-[#c8a46b] hover:shadow-md bg-white overflow-hidden text-left cursor-pointer transition disabled:opacity-60 flex flex-col">
            <div class="flex-1 bg-[#f6f2ea] flex items-center justify-center overflow-hidden">
              <img :src="templatePreview(key)" alt="" class="w-full h-full object-contain p-2" />
            </div>
            <div class="px-3 py-2 text-xs font-semibold truncate border-t border-[#ece7dd]">{{ t('boards.templates.' + key) }}</div>
          </button>
        </div>
      </section>

      <!-- ボード一覧 -->
      <section>
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
          <h2 class="text-lg font-serif font-semibold">{{ t(sectionTitle) }}</h2>
          <div class="flex items-center gap-2 text-sm">
            <label class="flex items-center gap-1 text-[#6b7c93]">
              <span class="hidden sm:inline">{{ t('boards.filterOwner') }}</span>
              <select v-model="owner" class="rounded-md border border-[#e3ddd1] bg-white px-2 py-1 text-[#1c2f45]">
                <option value="anyone">{{ t('boards.ownerAnyone') }}</option>
                <option value="me">{{ t('boards.ownerMe') }}</option>
              </select>
            </label>
            <label class="flex items-center gap-1 text-[#6b7c93]">
              <span class="hidden sm:inline">{{ t('boards.sortBy') }}</span>
              <select v-model="sort" class="rounded-md border border-[#e3ddd1] bg-white px-2 py-1 text-[#1c2f45]">
                <option value="opened">{{ t('boards.sortOpened') }}</option>
                <option value="modified">{{ t('boards.sortModified') }}</option>
                <option value="alpha">{{ t('boards.sortAlpha') }}</option>
                <option value="created">{{ t('boards.sortCreated') }}</option>
              </select>
            </label>
            <div class="flex rounded-md border border-[#e3ddd1] overflow-hidden">
              <button @click="view = 'grid'" :title="t('boards.grid')" class="p-1.5 cursor-pointer" :class="view === 'grid' ? 'bg-[#e6eef3] text-[#2f5d7c]' : 'text-[#6b7c93] hover:bg-[#f3efe6]'"><BoardIcon name="grid" :size="18" /></button>
              <button @click="view = 'list'" :title="t('boards.list')" class="p-1.5 cursor-pointer" :class="view === 'list' ? 'bg-[#e6eef3] text-[#2f5d7c]' : 'text-[#6b7c93] hover:bg-[#f3efe6]'"><BoardIcon name="list" :size="18" /></button>
            </div>
          </div>
        </div>

        <p v-if="error" class="text-sm text-red-500 mb-4">{{ error }}</p>
        <div v-if="loading" class="text-sm text-[#6b7c93] py-10 text-center">{{ t('common.loading') }}</div>

        <!-- 空状態 -->
        <div v-else-if="filtered.length === 0" class="py-16 text-center text-[#6b7c93]">
          <div class="mx-auto w-20 h-20 rounded-2xl bg-[#f3efe6] flex items-center justify-center mb-4">
            <BoardIcon :name="section === 'starred' ? 'star' : 'templates'" :size="36" class="text-[#b9b3a6]" />
          </div>
          <template v-if="query">
            <p class="font-medium text-[#1c2f45]">{{ t('boards.noResults', { q: query }) }}</p>
          </template>
          <template v-else-if="section === 'starred'">
            <p class="font-medium text-[#1c2f45]">{{ t('boards.emptyStarred') }}</p>
            <p class="text-sm mt-1">{{ t('boards.emptyStarredHint') }}</p>
          </template>
          <template v-else>
            <p class="font-medium text-[#1c2f45]">{{ t('boards.empty') }}</p>
            <p class="text-sm mt-1">{{ t('boards.emptyHint') }}</p>
          </template>
        </div>

        <!-- グリッド -->
        <div v-else-if="view === 'grid'" class="grid gap-4 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          <div v-for="b in filtered" :key="b.id" @click="open(b)"
            class="group relative rounded-2xl border border-[#e3ddd1] bg-white hover:shadow-lg hover:border-[#c8a46b] transition cursor-pointer overflow-hidden">
            <div class="aspect-[16/10] bg-[#f6f2ea] flex items-center justify-center overflow-hidden border-b border-[#ece7dd]">
              <img v-if="b.thumbnail" :src="thumbUrl(b.thumbnail)" alt="" class="w-full h-full object-contain p-3" />
              <div v-else class="text-[#b9b3a6]"><BoardIcon name="templates" :size="40" /></div>
            </div>
            <div class="px-3 py-2.5">
              <div class="flex items-start gap-2">
                <div class="min-w-0 flex-1">
                  <div class="text-sm font-semibold truncate">{{ b.title || t('boards.untitled') }}</div>
                  <div class="text-xs text-[#6b7c93] truncate mt-0.5">{{ meta(b) }}</div>
                  <div v-if="b.project" class="text-[11px] text-[#2f5d7c] truncate mt-0.5 flex items-center gap-1">
                    <BoardIcon name="users" :size="12" />{{ t('boards.sharedWith', { project: b.project.title }) }}
                  </div>
                </div>
                <button @click.stop="toggleStar(b)" :title="b.is_starred ? t('boards.unstar') : t('boards.star')"
                  class="p-1 rounded cursor-pointer transition"
                  :class="b.is_starred ? 'text-[#c8a46b]' : 'text-[#b9b3a6] opacity-0 group-hover:opacity-100 hover:text-[#c8a46b]'">
                  <BoardIcon :name="b.is_starred ? 'star-filled' : 'star'" :size="18" />
                </button>
                <button @click.stop="openMenu(b, $event)" class="p-1 rounded text-[#6b7c93] hover:bg-[#f3efe6] cursor-pointer">
                  <BoardIcon name="dots" :size="18" />
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- リスト -->
        <div v-else class="overflow-x-auto rounded-2xl border border-[#e3ddd1]">
          <table class="w-full text-sm">
            <thead class="bg-[#f6f2ea] text-[#6b7c93] text-xs">
              <tr>
                <th class="text-left font-semibold px-4 py-2">{{ t('boards.colName') }}</th>
                <th class="text-left font-semibold px-4 py-2 hidden sm:table-cell">{{ t('boards.colOwner') }}</th>
                <th class="text-left font-semibold px-4 py-2 hidden lg:table-cell">{{ t('boards.colProject') }}</th>
                <th class="text-left font-semibold px-4 py-2 hidden md:table-cell">{{ t('boards.colModified') }}</th>
                <th class="text-left font-semibold px-4 py-2 hidden md:table-cell">{{ t('boards.colOpened') }}</th>
                <th class="px-2 py-2"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="b in filtered" :key="b.id" @click="open(b)" class="border-t border-[#ece7dd] hover:bg-[#f6f2ea] cursor-pointer">
                <td class="px-4 py-2">
                  <div class="flex items-center gap-3 min-w-0">
                    <div class="w-14 h-9 rounded bg-[#f3efe6] border border-[#e3ddd1] overflow-hidden shrink-0 flex items-center justify-center">
                      <img v-if="b.thumbnail" :src="thumbUrl(b.thumbnail)" alt="" class="w-full h-full object-contain" />
                    </div>
                    <span class="font-medium truncate">{{ b.title || t('boards.untitled') }}</span>
                    <BoardIcon v-if="b.is_starred" name="star-filled" :size="14" class="text-[#c8a46b] shrink-0" />
                  </div>
                </td>
                <td class="px-4 py-2 hidden sm:table-cell text-[#6b7c93]">{{ ownerName(b) }}</td>
                <td class="px-4 py-2 hidden lg:table-cell text-[#6b7c93]">{{ b.project?.title || '—' }}</td>
                <td class="px-4 py-2 hidden md:table-cell text-[#6b7c93]">{{ ago(b.updated_at) }}</td>
                <td class="px-4 py-2 hidden md:table-cell text-[#6b7c93]">{{ ago(b.last_opened_at) }}</td>
                <td class="px-2 py-2 text-right">
                  <button @click.stop="openMenu(b, $event)" class="p-1 rounded text-[#6b7c93] hover:bg-[#e3ddd1] cursor-pointer"><BoardIcon name="dots" :size="18" /></button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </main>

    <!-- ケバブメニュー -->
    <div v-if="menu.board" class="fixed inset-0 z-50" @click="menu.board = null">
      <div class="absolute w-48 rounded-xl bg-white shadow-xl border border-[#e3ddd1] py-1 text-sm"
        :style="{ left: menu.x + 'px', top: menu.y + 'px' }" @click.stop>
        <button @click="open(menu.board)" class="w-full text-left px-3 py-2 hover:bg-[#f3efe6] cursor-pointer">{{ t('boards.open') }}</button>
        <button @click="startRename(menu.board)" class="w-full text-left px-3 py-2 hover:bg-[#f3efe6] cursor-pointer">{{ t('boards.rename') }}</button>
        <button @click="duplicate(menu.board)" class="w-full text-left px-3 py-2 hover:bg-[#f3efe6] cursor-pointer">{{ t('boards.duplicate') }}</button>
        <button @click="toggleStar(menu.board); menu.board = null" class="w-full text-left px-3 py-2 hover:bg-[#f3efe6] cursor-pointer">{{ menu.board.is_starred ? t('boards.unstar') : t('boards.star') }}</button>
        <button v-if="menu.board.can_delete" @click="confirmDelete = menu.board; menu.board = null"
          class="w-full text-left px-3 py-2 hover:bg-[#fbeeea] text-red-600 cursor-pointer">{{ t('boards.delete') }}</button>
      </div>
    </div>

    <!-- 名前変更 -->
    <div v-if="renaming" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4" @click.self="renaming = null">
      <form @submit.prevent="rename" class="bg-white rounded-xl shadow-xl p-6 w-full max-w-sm">
        <h3 class="font-semibold mb-3">{{ t('boards.rename') }}</h3>
        <input v-model="renameTitle" ref="renameInput" :placeholder="t('boards.renamePrompt')"
          class="w-full border border-[#e3ddd1] rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#2f5d7c]/40" />
        <div class="flex justify-end gap-2 mt-4">
          <button type="button" @click="renaming = null" class="px-4 py-2 rounded-lg border border-[#e3ddd1] text-sm cursor-pointer">{{ t('common.cancel') }}</button>
          <button type="submit" class="px-4 py-2 rounded-lg bg-[#2f5d7c] text-white text-sm cursor-pointer">{{ t('common.save') }}</button>
        </div>
      </form>
    </div>

    <!-- 削除確認 -->
    <div v-if="confirmDelete" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4" @click.self="confirmDelete = null">
      <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-sm">
        <p class="text-sm">{{ t('boards.confirmDelete', { title: confirmDelete.title || t('boards.untitled') }) }}</p>
        <div class="flex justify-end gap-2 mt-4">
          <button @click="confirmDelete = null" class="px-4 py-2 rounded-lg border border-[#e3ddd1] text-sm cursor-pointer">{{ t('common.cancel') }}</button>
          <button @click="remove(confirmDelete)" class="px-4 py-2 rounded-lg bg-red-600 text-white text-sm cursor-pointer">{{ t('common.delete') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '../api'
import { useAuthStore } from '../stores/auth'
import { activeDateLocale } from '../i18n'
import BoardIcon from '../components/board/BoardIcon.vue'
import { TEMPLATE_KEYS, templateElements, renderThumbnail, thumbnailDataUrl, emptyBoard } from '../services/board'

const { t, locale } = useI18n()
const auth = useAuthStore()
const router = useRouter()

const boards = ref([])
const loading = ref(true)
const error = ref('')
const creating = ref(false)
const query = ref('')
const section = ref('home')
const owner = ref('anyone')
const sort = ref('opened')
const view = ref(readPref('takumi_boards_view', 'grid'))
const menu = ref({ board: null, x: 0, y: 0 })
const renaming = ref(null)
const renameTitle = ref('')
const renameInput = ref(null)
const confirmDelete = ref(null)

const sections = [
  { key: 'home', icon: 'home', label: 'boards.home' },
  { key: 'recent', icon: 'clock', label: 'boards.recent' },
  { key: 'starred', icon: 'star', label: 'boards.starred' },
  { key: 'shared', icon: 'users', label: 'boards.shared' },
  { key: 'explore', icon: 'templates', label: 'boards.explore' },
]
const templateKeys = TEMPLATE_KEYS.filter((k) => k !== 'blank')

const sectionTitle = computed(() => ({
  home: 'boards.boardsIn', explore: 'boards.boardsIn', recent: 'boards.boardsRecent',
  starred: 'boards.boardsStarred', shared: 'boards.boardsShared',
}[section.value]))

const filtered = computed(() => {
  let list = boards.value.slice()
  if (section.value === 'starred') list = list.filter((b) => b.is_starred)
  if (section.value === 'shared') list = list.filter((b) => b.project_id)
  if (owner.value === 'me') list = list.filter((b) => b.is_owner)
  const q = query.value.trim().toLowerCase()
  if (q) list = list.filter((b) => (b.title || '').toLowerCase().includes(q) || (b.project?.title || '').toLowerCase().includes(q))
  const key = section.value === 'recent' ? 'opened' : sort.value
  const time = (s) => (s ? new Date(s).getTime() : 0)
  list.sort((a, b) => {
    if (key === 'alpha') return (a.title || '').localeCompare(b.title || '', locale.value)
    if (key === 'created') return time(b.created_at) - time(a.created_at)
    if (key === 'modified') return time(b.updated_at) - time(a.updated_at)
    return (time(b.last_opened_at) || time(b.updated_at)) - (time(a.last_opened_at) || time(a.updated_at))
  })
  if (section.value === 'recent') list = list.slice(0, 24)
  return list
})

function readPref(key, fallback) {
  try { return localStorage.getItem(key) || fallback } catch { return fallback }
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.get('/boards')
    boards.value = res.data?.data || []
  } catch {
    error.value = t('boards.loadFailed')
  } finally {
    loading.value = false
  }
}

const previewCache = new Map()
function templatePreview(key) {
  if (!previewCache.has(key)) {
    previewCache.set(key, thumbnailDataUrl(renderThumbnail(templateElements(key, locale.value), { w: 160, h: 100 })))
  }
  return previewCache.get(key)
}

function thumbUrl(svg) {
  return thumbnailDataUrl(svg)
}

async function createBoard(key) {
  if (creating.value) return
  creating.value = true
  error.value = ''
  try {
    const content = emptyBoard()
    if (key !== 'blank') content.elements = templateElements(key, locale.value, 0, 0)
    const title = key === 'blank' ? t('boards.untitled') : t('boards.templates.' + key)
    const res = await api.post('/boards', { title, content })
    router.push(`/boards/${res.data.data.id}`)
  } catch {
    error.value = t('boards.createFailed')
    creating.value = false
  }
}

function open(b) {
  router.push(`/boards/${b.id}`)
}

async function toggleStar(b) {
  const next = !b.is_starred
  b.is_starred = next
  try { await api.put(`/boards/${b.id}`, { is_starred: next }) } catch { b.is_starred = !next }
}

function openMenu(b, e) {
  const x = Math.min(e.clientX, window.innerWidth - 200)
  const y = Math.min(e.clientY, window.innerHeight - 220)
  menu.value = { board: b, x, y }
}

function startRename(b) {
  menu.value.board = null
  renaming.value = b
  renameTitle.value = b.title
  nextTick(() => renameInput.value?.select())
}

async function rename() {
  const b = renaming.value
  const title = renameTitle.value.trim() || t('boards.untitled')
  renaming.value = null
  const prev = b.title
  b.title = title
  try { await api.put(`/boards/${b.id}`, { title }) } catch { b.title = prev }
}

async function duplicate(b) {
  menu.value.board = null
  try {
    const res = await api.post(`/boards/${b.id}/duplicate`)
    boards.value.unshift(res.data.data)
  } catch {
    error.value = t('boards.createFailed')
  }
}

async function remove(b) {
  confirmDelete.value = null
  try {
    await api.delete(`/boards/${b.id}`)
    boards.value = boards.value.filter((x) => x.id !== b.id)
  } catch {
    error.value = t('boards.loadFailed')
  }
}

function initials(name) {
  return (name || '?').trim().split(/\s+/).map((s) => s[0]).join('').slice(0, 2).toUpperCase()
}

function ownerName(b) {
  return b.is_owner ? t('boards.you') : (b.owner?.name || '—')
}

function ago(iso) {
  if (!iso) return '—'
  const diff = Date.now() - new Date(iso).getTime()
  const m = Math.floor(diff / 60000)
  if (m < 1) return t('boards.justNow')
  if (m < 60) return t('boards.minutesAgo', { n: m })
  const h = Math.floor(m / 60)
  if (h < 24) return t('boards.hoursAgo', { n: h })
  const d = Math.floor(h / 24)
  if (d < 7) return t('boards.daysAgo', { n: d })
  return new Date(iso).toLocaleDateString(activeDateLocale())
}

function meta(b) {
  return `${t('boards.modifiedBy', { name: ownerName(b) })} · ${ago(b.updated_at)}`
}

onMounted(load)

watch(view, (v) => { try { localStorage.setItem('takumi_boards_view', v) } catch {} })
</script>
