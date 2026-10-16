<template>
  <svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <template v-if="name === 'cursor'"><path d="M5 3l14 8-6 2-3 6z" fill="currentColor" stroke="none" /></template>
    <template v-else-if="name === 'hand'"><path d="M8 13V5.5a1.5 1.5 0 013 0V12M11 6a1.5 1.5 0 013 0v6M14 7.5a1.5 1.5 0 013 0V13M17 11a1.5 1.5 0 013 0v3.5c0 3.6-2.5 6.5-6.5 6.5S8 20 7 18l-3-5.5a1.4 1.4 0 012.4-1.4L8 13" /></template>
    <template v-else-if="name === 'templates'"><rect x="3" y="3" width="8" height="8" rx="1.5" /><rect x="13" y="3" width="8" height="8" rx="1.5" /><rect x="3" y="13" width="8" height="8" rx="1.5" /><rect x="13" y="13" width="8" height="8" rx="1.5" /></template>
    <template v-else-if="name === 'text'"><path d="M5 6h14M12 6v13M9 19h6" /></template>
    <template v-else-if="name === 'sticky'"><path d="M4 4h16v10l-6 6H4z" fill="currentColor" fill-opacity=".15" /><path d="M14 20v-6h6" /></template>
    <template v-else-if="name === 'shape'"><rect x="3" y="3" width="10" height="10" rx="1.5" /><circle cx="16" cy="16" r="5" /></template>
    <template v-else-if="name === 'connector'"><circle cx="5" cy="19" r="2" /><path d="M6.5 17.5L17 7" /><path d="M13 5h6v6" /></template>
    <template v-else-if="name === 'pen'"><path d="M4 20l4-1L19 8a2 2 0 00-3-3L5 16z" /><path d="M14 6l3 3" /></template>
    <template v-else-if="name === 'eraser'"><path d="M4 16l9-9a2 2 0 013 0l4 4a2 2 0 010 3l-6 6H9z" /><path d="M9 20l-5-4M13 20h8" /></template>
    <template v-else-if="name === 'comment'"><path d="M4 5h16v11H9l-5 4z" /></template>
    <template v-else-if="name === 'frame'"><path d="M6 3v18M18 3v18M3 6h18M3 18h18" /></template>
    <template v-else-if="name === 'upload'"><path d="M12 16V4M7 9l5-5 5 5" /><path d="M4 20h16" /></template>
    <template v-else-if="name === 'more'"><path d="M12 5v14M5 12h14" /></template>
    <template v-else-if="name === 'undo'"><path d="M8 7H4v4" /><path d="M4 11a8 8 0 1116 1" /></template>
    <template v-else-if="name === 'redo'"><path d="M16 7h4v4" /><path d="M20 11A8 8 0 104 12" /></template>
    <template v-else-if="name === 'plus'"><path d="M12 5v14M5 12h14" /></template>
    <template v-else-if="name === 'minus'"><path d="M5 12h14" /></template>
    <template v-else-if="name === 'help'"><circle cx="12" cy="12" r="9" /><path d="M9.5 9.5a2.5 2.5 0 015 0c0 1.7-2.5 2-2.5 3.5M12 17h.01" /></template>
    <template v-else-if="name === 'map'"><path d="M3 6l6-2 6 2 6-2v14l-6 2-6-2-6 2z" /><path d="M9 4v14M15 6v14" /></template>
    <template v-else-if="name === 'star'"><path d="M12 3l2.8 5.8 6.2.9-4.5 4.4 1.1 6.3L12 17.5 6.4 20.4l1.1-6.3L3 9.7l6.2-.9z" /></template>
    <template v-else-if="name === 'star-filled'"><path d="M12 3l2.8 5.8 6.2.9-4.5 4.4 1.1 6.3L12 17.5 6.4 20.4l1.1-6.3L3 9.7l6.2-.9z" fill="currentColor" /></template>
    <template v-else-if="name === 'trash'"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3" /></template>
    <template v-else-if="name === 'copy'"><rect x="9" y="9" width="12" height="12" rx="2" /><path d="M5 15V5a2 2 0 012-2h10" /></template>
    <template v-else-if="name === 'lock'"><rect x="5" y="11" width="14" height="10" rx="2" /><path d="M8 11V7a4 4 0 018 0v4" /></template>
    <template v-else-if="name === 'unlock'"><rect x="5" y="11" width="14" height="10" rx="2" /><path d="M8 11V7a4 4 0 017.5-2" /></template>
    <template v-else-if="name === 'front'"><rect x="8" y="8" width="13" height="13" rx="1.5" fill="currentColor" fill-opacity=".2" /><path d="M3 16V5a2 2 0 012-2h11" /></template>
    <template v-else-if="name === 'back'"><rect x="3" y="3" width="13" height="13" rx="1.5" fill="currentColor" fill-opacity=".2" /><path d="M21 8v11a2 2 0 01-2 2H8" /></template>
    <template v-else-if="name === 'bold'"><path d="M7 4h6a4 4 0 010 8H7zM7 12h7a4 4 0 010 8H7z" /></template>
    <template v-else-if="name === 'align-left'"><path d="M4 6h16M4 12h10M4 18h14" /></template>
    <template v-else-if="name === 'align-center'"><path d="M4 6h16M7 12h10M5 18h14" /></template>
    <template v-else-if="name === 'align-right'"><path d="M4 6h16M10 12h10M6 18h14" /></template>
    <template v-else-if="name === 'chevron-down'"><path d="M6 9l6 6 6-6" /></template>
    <template v-else-if="name === 'chevron-left'"><path d="M15 6l-6 6 6 6" /></template>
    <template v-else-if="name === 'search'"><circle cx="11" cy="11" r="7" /><path d="M20 20l-4-4" /></template>
    <template v-else-if="name === 'grid'"><rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" /><rect x="3" y="14" width="7" height="7" rx="1" /><rect x="14" y="14" width="7" height="7" rx="1" /></template>
    <template v-else-if="name === 'list'"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" /></template>
    <template v-else-if="name === 'dots'"><circle cx="5" cy="12" r="1.5" fill="currentColor" /><circle cx="12" cy="12" r="1.5" fill="currentColor" /><circle cx="19" cy="12" r="1.5" fill="currentColor" /></template>
    <template v-else-if="name === 'home'"><path d="M3 11l9-7 9 7v9a1 1 0 01-1 1h-5v-6h-6v6H4a1 1 0 01-1-1z" /></template>
    <template v-else-if="name === 'clock'"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></template>
    <template v-else-if="name === 'share'"><path d="M4 12v7a1 1 0 001 1h14a1 1 0 001-1v-7M12 15V3M7 8l5-5 5 5" /></template>
    <template v-else-if="name === 'users'"><circle cx="9" cy="8" r="3.5" /><path d="M2.5 20a6.5 6.5 0 0113 0" /><circle cx="17" cy="9" r="2.5" /><path d="M16 15a5 5 0 015.5 5" /></template>
    <template v-else-if="name === 'check'"><path d="M5 12l5 5 9-10" /></template>
    <template v-else-if="name === 'close'"><path d="M6 6l12 12M18 6L6 18" /></template>
    <template v-else-if="name === 'link'"><path d="M10 14a4 4 0 005.7 0l3-3a4 4 0 00-5.7-5.7l-1 1" /><path d="M14 10a4 4 0 00-5.7 0l-3 3a4 4 0 005.7 5.7l1-1" /></template>
    <template v-else-if="name === 'fit'"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5" /></template>
    <template v-else-if="name === 'cloud-check'"><path d="M7 18a4 4 0 01-.5-8 6 6 0 0111.6 1.5A3.5 3.5 0 0117.5 18z" /><path d="M9 13l2 2 4-4" /></template>
    <template v-else-if="name === 'cloud-sync'"><path d="M7 18a4 4 0 01-.5-8 6 6 0 0111.6 1.5A3.5 3.5 0 0117.5 18z" /><path d="M12 9v5M10 12l2 2 2-2" /></template>
    <template v-else-if="name === 'image'"><rect x="3" y="4" width="18" height="16" rx="2" /><circle cx="9" cy="10" r="2" /><path d="M21 16l-5-5-9 9" /></template>
    <template v-else-if="name === 'line-straight'"><path d="M4 20L20 4" /></template>
    <template v-else-if="name === 'line-curved'"><path d="M4 20C4 8 20 16 20 4" /></template>
    <template v-else-if="name === 'line-elbow'"><path d="M4 20v-8h16V4" /></template>
    <template v-else-if="name === 'arrow-none'"><path d="M4 12h16" /></template>
    <template v-else-if="name === 'arrow-end'"><path d="M4 12h16M15 7l5 5-5 5" /></template>
    <template v-else-if="name === 'arrow-both'"><path d="M4 12h16M15 7l5 5-5 5M9 7l-5 5 5 5" /></template>
    <template v-else-if="name === 'present'"><rect x="3" y="4" width="18" height="12" rx="2" /><path d="M12 16v4M8 20h8M10 8l5 2-5 2z" fill="currentColor" /></template>
    <template v-else-if="name === 'timer'"><circle cx="12" cy="13" r="8" /><path d="M12 9v4l2 2M9 2h6" /></template>
  </svg>
</template>

<script setup>
defineProps({
  name: { type: String, required: true },
  size: { type: [Number, String], default: 20 },
})
</script>
