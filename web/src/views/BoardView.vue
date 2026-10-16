<template>
  <!--
    ホワイトボード編集画面 = Miro のボード相当。
    画面いっぱいの無限キャンバス（パン・ズーム）に、左のツールバーで要素を置いていく。
    中身は elements の配列だけで、保存は変更から 1 秒後に自動で走る（autosave）。
  -->
  <div class="fixed inset-0 overflow-hidden select-none bg-[#f6f2ea] text-[#1c2f45]" :class="{ 'is-dragging': !!dragKind }">
    <!-- 見つからない / 権限なし -->
    <div v-if="notFound" class="absolute inset-0 flex items-center justify-center p-6">
      <div class="bg-white rounded-2xl shadow-xl p-8 max-w-md text-center">
        <p class="text-sm text-[#6b7c93] mb-4">{{ t('board.notFound') }}</p>
        <router-link to="/boards" class="inline-block px-4 py-2 rounded-lg bg-[#2f5d7c] text-white text-sm">{{ t('board.back') }}</router-link>
      </div>
    </div>

    <template v-else>
      <!-- ================= キャンバス ================= -->
      <div ref="viewport" class="absolute inset-0 overflow-hidden touch-none" :style="gridStyle" :class="cursorClass"
        @pointerdown="onPointerDown" @pointermove="onPointerMove" @pointerup="onPointerUp" @pointercancel="onPointerUp"
        @wheel.prevent="onWheel" @dblclick="onDblClick" @contextmenu.prevent>
        <div class="absolute left-0 top-0 origin-top-left will-change-transform" :style="worldStyle">
          <template v-for="el in ordered" :key="el.id">
            <!-- フレーム：本体はクリックを通し（中で範囲選択できるように）、タイトルだけが掴める -->
            <div v-if="el.type === 'frame'" class="absolute rounded-sm pointer-events-none" :style="boxStyle(el)"
              :class="{ 'ring-2 ring-[#2f5d7c]': isSelected(el.id) }">
              <div class="absolute inset-0 border border-[#b9b3a6]" :style="{ background: el.fill || '#fff' }"></div>
              <div :data-el-id="el.id" class="absolute left-0 -top-7 h-7 max-w-full pointer-events-auto cursor-move px-1 flex items-end">
                <input v-if="editingId === el.id" :ref="setEditRef" v-model="el.title" @blur="stopEditing" @keydown.enter.prevent="stopEditing"
                  @keydown.esc.prevent="stopEditing" @pointerdown.stop @input="markDirty"
                  class="text-sm font-semibold bg-white border border-[#2f5d7c] rounded px-1 outline-none" :style="{ fontSize: 14 / cam.z + 'px' }" />
                <span v-else class="text-sm font-semibold text-[#47576f] truncate" :style="{ fontSize: 14 / Math.max(cam.z, 0.4) + 'px' }">
                  {{ el.title || t('board.frameDefault') }}
                </span>
              </div>
            </div>

            <!-- 付箋 -->
            <div v-else-if="el.type === 'sticky'" :data-el-id="el.id" class="absolute board-sticky flex items-center justify-center overflow-hidden rounded-[3px]"
              :style="{ ...boxStyle(el), background: el.color, boxShadow: '0 6px 16px rgba(16,35,58,.12)' }" :class="{ 'cursor-move': tool === 'select' && !el.locked }">
              <textarea v-if="editingId === el.id" :ref="setEditRef" :value="el.text" @input="onTextInput(el, $event)" @blur="stopEditing"
                @keydown.esc.prevent="stopEditing" @pointerdown.stop :placeholder="t('board.stickyPlaceholder')"
                class="board-textarea" :style="textStyle(el)"></textarea>
              <div v-else class="board-text" :style="textStyle(el)">{{ el.text }}</div>
            </div>

            <!-- 図形 -->
            <div v-else-if="el.type === 'shape'" :data-el-id="el.id" class="absolute flex items-center justify-center" :style="boxStyle(el)"
              :class="{ 'cursor-move': tool === 'select' && !el.locked }">
              <svg :width="el.w" :height="el.h" :viewBox="`0 0 ${el.w} ${el.h}`" class="absolute inset-0 overflow-visible" preserveAspectRatio="none">
                <path :d="shapePath(el.shape, el.w, el.h)" :fill="el.fill === 'transparent' ? 'none' : el.fill" :stroke="el.strokeWidth ? el.stroke : 'none'"
                  :stroke-width="el.strokeWidth" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
                <!-- 塗り無しでも中をクリックで掴めるように透明の当たり判定 -->
                <path :d="shapePath(el.shape, el.w, el.h)" fill="rgba(0,0,0,0.001)" stroke="none" />
              </svg>
              <textarea v-if="editingId === el.id" :ref="setEditRef" :value="el.text" @input="onTextInput(el, $event)" @blur="stopEditing"
                @keydown.esc.prevent="stopEditing" @pointerdown.stop class="board-textarea relative" :style="textStyle(el)"></textarea>
              <div v-else class="board-text relative" :style="textStyle(el)">{{ el.text }}</div>
            </div>

            <!-- テキスト -->
            <div v-else-if="el.type === 'text'" :data-el-id="el.id" class="absolute" :style="boxStyle(el)"
              :class="{ 'cursor-move': tool === 'select' && !el.locked, 'outline-dashed outline-1 outline-[#b9b3a6]': !el.text && editingId !== el.id }">
              <textarea v-if="editingId === el.id" :ref="setEditRef" :value="el.text" @input="onTextInput(el, $event)" @blur="stopEditing"
                @keydown.esc.prevent="stopEditing" @pointerdown.stop :placeholder="t('board.textPlaceholder')"
                class="board-textarea items-start" :style="{ ...textStyle(el), justifyContent: 'flex-start', padding: '4px' }"></textarea>
              <div v-else class="board-text items-start" :style="{ ...textStyle(el), justifyContent: 'flex-start', padding: '4px' }">{{ el.text }}</div>
            </div>

            <!-- 画像 -->
            <div v-else-if="el.type === 'image'" :data-el-id="el.id" class="absolute overflow-hidden bg-[#e3ddd1]" :style="boxStyle(el)"
              :class="{ 'cursor-move': tool === 'select' && !el.locked }">
              <img :src="el.src" alt="" class="w-full h-full object-fill pointer-events-none" draggable="false" />
            </div>

            <!-- コメント（ピン） -->
            <div v-else-if="el.type === 'comment'" :data-el-id="el.id" class="absolute" :style="{ left: el.x + 'px', top: el.y + 'px', width: COMMENT_SIZE + 'px', height: COMMENT_SIZE + 'px' }">
              <div class="w-full h-full rounded-full rounded-bl-none bg-[#1c2f45] text-white text-xs font-bold flex items-center justify-center shadow-md cursor-pointer border-2 border-white"
                :class="{ 'ring-2 ring-[#2f5d7c]': isSelected(el.id) }" :style="{ transform: `scale(${1 / Math.max(cam.z, 0.5)})`, transformOrigin: 'bottom left' }">
                {{ initials(el.author) }}
              </div>
            </div>

            <!-- ペン -->
            <svg v-else-if="el.type === 'pen'" class="absolute overflow-visible pointer-events-none" :style="svgStyle(el)"
              :width="Math.max(1, bounds(el).w)" :height="Math.max(1, bounds(el).h)">
              <g :transform="`translate(${-bounds(el).x},${-bounds(el).y})`">
                <path :d="penPath(el.points)" fill="none" :stroke="el.color" :stroke-width="el.width" stroke-linecap="round" stroke-linejoin="round" />
                <path :data-el-id="el.id" :data-pen-id="el.id" :d="penPath(el.points)" fill="none" stroke="transparent" :stroke-width="Math.max(el.width, 12 / cam.z)"
                  stroke-linecap="round" stroke-linejoin="round" class="pointer-events-auto" :class="{ 'cursor-move': tool === 'select' }" />
              </g>
            </svg>

            <!-- 接続線 -->
            <svg v-else-if="el.type === 'connector'" class="absolute overflow-visible pointer-events-none" :style="svgStyle(el)" width="1" height="1">
              <g :transform="`translate(${-bounds(el).x},${-bounds(el).y})`">
                <path :d="connectorD(el)" fill="none" :stroke="el.color" :stroke-width="el.width" stroke-linecap="round" stroke-linejoin="round" />
                <template v-if="el.arrow === 'end' || el.arrow === 'both'">
                  <path :d="arrowHead(el, true)" :fill="el.color" stroke="none" />
                </template>
                <template v-if="el.arrow === 'both'">
                  <path :d="arrowHead(el, false)" :fill="el.color" stroke="none" />
                </template>
                <path :data-el-id="el.id" :d="connectorD(el)" fill="none" stroke="transparent" :stroke-width="Math.max(el.width, 14 / cam.z)"
                  class="pointer-events-auto" :class="{ 'cursor-move': tool === 'select' }" />
              </g>
            </svg>
          </template>

          <!-- 描画中のペン / 接続線 / 作成中の矩形（プレビュー） -->
          <svg v-if="previewPen" class="absolute left-0 top-0 overflow-visible pointer-events-none" width="1" height="1">
            <path :d="penPath(previewPen.points)" fill="none" :stroke="previewPen.color" :stroke-width="previewPen.width" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
          <svg v-if="previewLine" class="absolute left-0 top-0 overflow-visible pointer-events-none" width="1" height="1">
            <path :d="connectorPath(connectorStyle, previewLine.x1, previewLine.y1, previewLine.x2, previewLine.y2)" fill="none" stroke="#2f5d7c" :stroke-width="2 / cam.z" stroke-dasharray="6 4" />
          </svg>
          <div v-if="previewRect" class="absolute border-2 border-[#2f5d7c] bg-[#2f5d7c]/5 pointer-events-none"
            :style="{ left: previewRect.x + 'px', top: previewRect.y + 'px', width: previewRect.w + 'px', height: previewRect.h + 'px' }"></div>
          <div v-if="hoverTargetId && byId.get(hoverTargetId)" class="absolute ring-2 ring-[#2f5d7c] pointer-events-none"
            :style="boxStyle(bounds(byId.get(hoverTargetId)))"></div>
        </div>

        <!-- 画面座標のオーバーレイ：範囲選択・選択枠・ハンドル -->
        <div v-if="marquee" class="absolute border border-[#2f5d7c] bg-[#2f5d7c]/10 pointer-events-none"
          :style="{ left: marquee.x + 'px', top: marquee.y + 'px', width: marquee.w + 'px', height: marquee.h + 'px' }"></div>

        <template v-if="selBox && !editingId">
          <div v-for="s in selectedScreenBoxes" :key="s.id" class="absolute border border-[#2f5d7c] pointer-events-none"
            :style="{ left: s.x - 1 + 'px', top: s.y - 1 + 'px', width: s.w + 2 + 'px', height: s.h + 2 + 'px' }"></div>
          <div v-if="selected.length > 1" class="absolute border border-dashed border-[#2f5d7c] pointer-events-none"
            :style="{ left: selBox.x - 4 + 'px', top: selBox.y - 4 + 'px', width: selBox.w + 8 + 'px', height: selBox.h + 8 + 'px' }"></div>
          <!-- リサイズハンドル（単一選択のみ） -->
          <template v-if="resizable">
            <div v-for="h in handles" :key="h.name" :data-handle="h.name" class="absolute w-2.5 h-2.5 bg-white border-2 border-[#c8a46b] rounded-full"
              :style="{ left: h.x - 5 + 'px', top: h.y - 5 + 'px', cursor: h.cursor }"></div>
          </template>
          <!-- 接続線の端点ハンドル -->
          <template v-if="connectorEnds">
            <div v-for="p in connectorEnds" :key="p.end" :data-endpoint="p.end" class="absolute w-3.5 h-3.5 bg-white border-2 border-[#c8a46b] rounded-full cursor-crosshair"
              :style="{ left: p.x - 7 + 'px', top: p.y - 7 + 'px' }"></div>
          </template>
        </template>
      </div>

      <!-- ================= コンテキストツールバー ================= -->
      <div v-if="selBox && !editingId && !dragKind" class="absolute z-30 flex items-center gap-1 rounded-2xl bg-white shadow-lg border border-[#e3ddd1] px-2 py-1.5"
        :style="ctxStyle" @pointerdown.stop>
        <template v-if="ctx.locked">
          <button class="ctx-btn" @click="setProp('locked', false)" :title="t('board.ctx.unlock')"><BoardIcon name="unlock" /></button>
          <span class="text-xs text-[#6b7c93] px-1">{{ t('board.lockedHint') }}</span>
        </template>
        <template v-else>
          <!-- 付箋の色 -->
          <div v-if="ctx.sticky" class="flex items-center gap-1 pr-1 border-r border-[#e3ddd1]">
            <button v-for="c in STICKY_COLORS" :key="c" @click="setProp('color', c, ['sticky'])" class="w-5 h-5 rounded-sm border border-black/10 cursor-pointer hover:scale-110 transition" :style="{ background: c }" :title="t('board.ctx.color')"></button>
          </div>
          <!-- 図形・フレームの塗り -->
          <div v-if="ctx.shape || ctx.frame" class="relative">
            <button class="ctx-btn gap-1" @click="ctxPanel = ctxPanel === 'fill' ? null : 'fill'" :title="t('board.ctx.fill')">
              <span class="w-4 h-4 rounded-sm border border-black/20 swatch-transparent" :style="{ background: ctx.fill === 'transparent' ? undefined : ctx.fill }"></span>
              <BoardIcon name="chevron-down" :size="12" />
            </button>
            <div v-if="ctxPanel === 'fill'" class="ctx-pop">
              <button v-for="c in (ctx.frame ? FRAME_FILLS : SHAPE_FILLS)" :key="c" @click="setProp('fill', c, ['shape', 'frame']); ctxPanel = null"
                class="w-6 h-6 rounded border border-black/10 cursor-pointer hover:scale-110 transition swatch-transparent" :style="{ background: c === 'transparent' ? undefined : c }"></button>
            </div>
          </div>
          <!-- 線の色（図形・ペン・接続線・テキスト色） -->
          <div v-if="ctx.shape || ctx.pen || ctx.connector || ctx.text" class="relative">
            <button class="ctx-btn gap-1" @click="ctxPanel = ctxPanel === 'stroke' ? null : 'stroke'" :title="ctx.text ? t('board.ctx.color') : t('board.ctx.stroke')">
              <span class="w-4 h-4 rounded-full border-2" :style="{ borderColor: ctx.strokeColor }"></span>
              <BoardIcon name="chevron-down" :size="12" />
            </button>
            <div v-if="ctxPanel === 'stroke'" class="ctx-pop">
              <button v-for="c in STROKE_COLORS" :key="c" @click="setStrokeColor(c); ctxPanel = null"
                class="w-6 h-6 rounded-full border border-black/10 cursor-pointer hover:scale-110 transition" :style="{ background: c }"></button>
            </div>
          </div>
          <!-- 線の太さ -->
          <div v-if="ctx.shape || ctx.pen || ctx.connector" class="relative">
            <button class="ctx-btn gap-1" @click="ctxPanel = ctxPanel === 'width' ? null : 'width'" :title="t('board.ctx.lineWidth')">
              <span class="w-4 border-b-2 border-current" :style="{ borderBottomWidth: Math.min(6, Math.max(1, ctx.strokeWidth)) + 'px' }"></span>
              <BoardIcon name="chevron-down" :size="12" />
            </button>
            <div v-if="ctxPanel === 'width'" class="ctx-pop flex-col">
              <button v-for="w in [0, 1, 2, 4, 6, 10]" :key="w" v-show="w !== 0 || ctx.shape" @click="setStrokeWidth(w); ctxPanel = null" class="flex items-center gap-2 px-2 py-1 rounded hover:bg-[#f3efe6] text-xs cursor-pointer w-24">
                <span class="w-10 border-b border-current" :style="{ borderBottomWidth: w + 'px', opacity: w ? 1 : 0.3 }"></span>{{ w || t('board.ctx.arrowNone') }}
              </button>
            </div>
          </div>
          <!-- 接続線の形と矢印 -->
          <template v-if="ctx.connector">
            <div class="flex items-center border-l border-[#e3ddd1] pl-1">
              <button v-for="s in ['straight', 'curved', 'elbow']" :key="s" class="ctx-btn" :class="{ active: ctx.lineStyle === s }" @click="setProp('style', s, ['connector'])" :title="t('board.ctx.line' + s[0].toUpperCase() + s.slice(1))"><BoardIcon :name="'line-' + s" /></button>
            </div>
            <div class="flex items-center border-l border-[#e3ddd1] pl-1">
              <button v-for="a in ['none', 'end', 'both']" :key="a" class="ctx-btn" :class="{ active: ctx.arrow === a }" @click="setProp('arrow', a, ['connector'])" :title="t('board.ctx.arrow' + a[0].toUpperCase() + a.slice(1))"><BoardIcon :name="'arrow-' + a" /></button>
            </div>
          </template>
          <!-- 文字 -->
          <template v-if="ctx.hasText">
            <div class="flex items-center gap-0.5 border-l border-[#e3ddd1] pl-1">
              <select :value="ctx.fontSize" @change="setProp('fontSize', Number($event.target.value), TEXT_TYPES)" class="text-xs border border-[#e3ddd1] rounded px-1 py-1 bg-white cursor-pointer" :title="t('board.ctx.fontSize')">
                <option v-for="s in FONT_SIZES" :key="s" :value="s">{{ s }}</option>
              </select>
              <button class="ctx-btn" :class="{ active: ctx.bold }" @click="setProp('bold', !ctx.bold, TEXT_TYPES)" :title="t('board.ctx.bold')"><BoardIcon name="bold" /></button>
              <button v-for="a in ['left', 'center', 'right']" :key="a" class="ctx-btn" :class="{ active: ctx.align === a }" @click="setProp('align', a, TEXT_TYPES)" :title="t('board.ctx.align')"><BoardIcon :name="'align-' + a" /></button>
            </div>
          </template>
          <!-- コメント -->
          <template v-if="ctx.comment">
            <button class="ctx-btn text-[#2f5d7c]" @click="deleteSelected" :title="t('board.ctx.resolve')"><BoardIcon name="check" /></button>
          </template>
          <!-- 共通 -->
          <div class="flex items-center border-l border-[#e3ddd1] pl-1">
            <button class="ctx-btn" @click="duplicateSelected" :title="t('board.ctx.duplicate')"><BoardIcon name="copy" /></button>
            <button class="ctx-btn" @click="reorder('front')" :title="t('board.ctx.bringFront')"><BoardIcon name="front" /></button>
            <button class="ctx-btn" @click="reorder('back')" :title="t('board.ctx.sendBack')"><BoardIcon name="back" /></button>
            <button class="ctx-btn" @click="setProp('locked', true)" :title="t('board.ctx.lock')"><BoardIcon name="lock" /></button>
            <button class="ctx-btn hover:text-red-600" @click="deleteSelected" :title="t('board.ctx.delete')"><BoardIcon name="trash" /></button>
          </div>
        </template>
      </div>

      <!-- コメントの吹き出し -->
      <div v-if="activeComment" class="absolute z-30 w-72 rounded-xl bg-white shadow-xl border border-[#e3ddd1] p-3" :style="commentStyle" @pointerdown.stop>
        <div class="flex items-center gap-2 mb-2">
          <span class="w-6 h-6 rounded-full bg-[#2f5d7c] text-white text-[10px] font-bold flex items-center justify-center">{{ initials(activeComment.author) }}</span>
          <span class="text-xs font-semibold truncate">{{ activeComment.author }}</span>
          <span class="text-[11px] text-[#6b7c93] ml-auto">{{ formatDate(activeComment.createdAt) }}</span>
        </div>
        <textarea v-model="activeComment.text" @input="markDirty" :ref="setEditRef" :placeholder="t('board.commentPlaceholder')" rows="3"
          class="w-full text-sm border border-[#e3ddd1] rounded-md px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-[#2f5d7c]/40 resize-none"></textarea>
        <div class="flex justify-end gap-2 mt-2">
          <button @click="deleteSelected" class="text-xs px-2 py-1 rounded text-[#6b7c93] hover:bg-[#f3efe6] cursor-pointer">{{ t('board.ctx.resolve') }}</button>
          <button @click="closeComment" class="text-xs px-3 py-1 rounded bg-[#2f5d7c] text-white cursor-pointer">{{ t('board.commentPost') }}</button>
        </div>
      </div>

      <!-- ================= 上部：ボード名 / 共有 ================= -->
      <div class="absolute top-3 left-3 z-30 flex items-center gap-1 rounded-2xl bg-white shadow-md border border-[#e3ddd1] pl-1 pr-2 py-1 max-w-[70vw]" @pointerdown.stop>
        <router-link to="/boards" class="p-1.5 rounded hover:bg-[#f3efe6] text-[#1c2f45]" :title="t('board.back')"><BoardIcon name="chevron-left" /></router-link>
        <img src="/logo.png" alt="" class="w-7 h-7 object-contain shrink-0" />
        <input v-if="editingTitle" ref="titleInput" v-model="title" @blur="commitTitle" @keydown.enter.prevent="commitTitle" @keydown.esc.prevent="editingTitle = false"
          class="text-sm font-semibold px-2 py-1 border border-[#2f5d7c] rounded outline-none min-w-[10rem]" />
        <button v-else @click="startTitleEdit" class="text-sm font-serif font-semibold px-2 py-1 rounded-full hover:bg-[#f3efe6] truncate cursor-text">{{ title || t('board.untitled') }}</button>
        <button @click="toggleStar" class="p-1 rounded hover:bg-[#f3efe6] cursor-pointer" :class="board?.is_starred ? 'text-[#c8a46b]' : 'text-[#b9b3a6]'">
          <BoardIcon :name="board?.is_starred ? 'star-filled' : 'star'" :size="18" />
        </button>
        <span class="hidden sm:flex items-center gap-1 text-[11px] text-[#6b7c93] pl-1 border-l border-[#e3ddd1] ml-1 whitespace-nowrap">
          <BoardIcon :name="saveState === 'saving' || saveState === 'dirty' ? 'cloud-sync' : 'cloud-check'" :size="14" :class="saveState === 'error' ? 'text-red-500' : ''" />
          {{ saveLabel }}
        </span>
      </div>

      <div class="absolute top-3 right-3 z-30 flex items-center gap-2 rounded-2xl bg-white shadow-md border border-[#e3ddd1] px-2 py-1" @pointerdown.stop>
        <div class="hidden sm:flex items-center -space-x-1.5 pr-1">
          <span class="w-7 h-7 rounded-full bg-[#2f5d7c] text-white text-[10px] font-bold flex items-center justify-center border-2 border-white" :title="auth.user?.name">{{ initials(auth.user?.name) }}</span>
          <span v-if="board?.owner && !board.is_owner" class="w-7 h-7 rounded-full bg-[#8aa7a0] text-white text-[10px] font-bold flex items-center justify-center border-2 border-white" :title="board.owner.name">{{ initials(board.owner.name) }}</span>
        </div>
        <button class="hidden md:inline-flex p-1.5 rounded hover:bg-[#f3efe6] text-[#1c2f45] cursor-pointer" :title="t('board.zoom.fit')" @click="zoomFit"><BoardIcon name="present" /></button>
        <button class="hidden md:inline-flex p-1.5 rounded hover:bg-[#f3efe6] text-[#1c2f45] cursor-pointer" @click="minimap = !minimap" :title="t('board.zoom.minimap')"><BoardIcon name="timer" /></button>
        <button @click="openShare" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full bg-[#1c2f45] hover:bg-[#2f5d7c] text-white text-sm font-semibold cursor-pointer">
          <BoardIcon name="share" :size="16" />{{ t('board.share') }}
        </button>
      </div>

      <!-- ================= 左ツールバー ================= -->
      <div class="absolute left-3 top-1/2 -translate-y-1/2 z-30 flex flex-col items-center gap-1 rounded-full bg-white shadow-md border border-[#e3ddd1] p-1.5" @pointerdown.stop>
        <button v-for="tb in toolbar" :key="tb.tool" class="tool-btn" :class="{ active: tool === tb.tool || (tb.tool === 'select' && tool === 'hand') || panel === tb.tool }"
          @click="onToolClick(tb)" :title="t('board.tools.' + tb.tool) + (tb.key ? ` (${tb.key.toUpperCase()})` : '')">
          <BoardIcon :name="tool === 'hand' && tb.tool === 'select' ? 'hand' : tb.icon" :size="22" />
        </button>
        <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="onUpload" />
      </div>

      <!-- ツールのサブパネル -->
      <div v-if="panel" class="absolute left-[4.25rem] top-1/2 -translate-y-1/2 z-30 rounded-2xl bg-white shadow-lg border border-[#e3ddd1] p-3" @pointerdown.stop>
        <!-- 付箋の色 -->
        <div v-if="panel === 'sticky'" class="grid grid-cols-4 gap-2">
          <button v-for="c in STICKY_COLORS" :key="c" @click="stickyColor = c; tool = 'sticky'; panel = null"
            class="w-9 h-9 rounded shadow-sm border border-black/10 cursor-pointer hover:scale-110 transition" :class="{ 'ring-2 ring-[#2f5d7c]': stickyColor === c }" :style="{ background: c }"></button>
        </div>
        <!-- 図形 -->
        <div v-else-if="panel === 'shape'" class="grid grid-cols-4 gap-1">
          <button v-for="s in SHAPES" :key="s" @click="shapeKind = s; tool = 'shape'; panel = null" :title="t('board.shapes.' + s)"
            class="w-11 h-11 rounded flex items-center justify-center hover:bg-[#f3efe6] cursor-pointer" :class="{ 'bg-[#e6eef3]': shapeKind === s }">
            <svg width="30" height="30" viewBox="-2 -2 34 34"><path :d="shapePath(s, 30, 30)" fill="none" stroke="#1c2f45" stroke-width="1.6" stroke-linejoin="round" /></svg>
          </button>
        </div>
        <!-- ペン -->
        <div v-else-if="panel === 'pen'" class="flex flex-col gap-3 w-44">
          <div class="flex flex-wrap gap-2">
            <button v-for="c in STROKE_COLORS" :key="c" @click="penColor = c; tool = 'pen'" class="w-7 h-7 rounded-full border border-black/10 cursor-pointer hover:scale-110 transition" :class="{ 'ring-2 ring-[#2f5d7c] ring-offset-1': penColor === c }" :style="{ background: c }"></button>
          </div>
          <div class="flex items-center gap-2">
            <button v-for="w in [2, 4, 8, 14]" :key="w" @click="penWidth = w; tool = 'pen'" class="w-9 h-9 rounded flex items-center justify-center hover:bg-[#f3efe6] cursor-pointer" :class="{ 'bg-[#e6eef3]': penWidth === w && tool === 'pen' }">
              <span class="rounded-full bg-[#1c2f45]" :style="{ width: w + 2 + 'px', height: w + 2 + 'px' }"></span>
            </button>
          </div>
          <button @click="tool = 'eraser'; panel = null" class="flex items-center gap-2 px-2 py-1.5 rounded text-sm hover:bg-[#f3efe6] cursor-pointer" :class="{ 'bg-[#e6eef3] text-[#2f5d7c]': tool === 'eraser' }">
            <BoardIcon name="eraser" :size="18" />{{ t('board.tools.eraser') }} (E)
          </button>
        </div>
        <!-- 接続線 -->
        <div v-else-if="panel === 'connector'" class="flex flex-col gap-2 w-48">
          <p class="text-[11px] text-[#6b7c93]">{{ t('board.connectorHint') }}</p>
          <div class="flex gap-1">
            <button v-for="s in ['straight', 'curved', 'elbow']" :key="s" @click="connectorStyle = s; tool = 'connector'" class="tool-btn" :class="{ active: connectorStyle === s }" :title="t('board.ctx.line' + s[0].toUpperCase() + s.slice(1))"><BoardIcon :name="'line-' + s" /></button>
          </div>
          <div class="flex gap-1">
            <button v-for="a in ['none', 'end', 'both']" :key="a" @click="connectorArrow = a; tool = 'connector'" class="tool-btn" :class="{ active: connectorArrow === a }" :title="t('board.ctx.arrow' + a[0].toUpperCase() + a.slice(1))"><BoardIcon :name="'arrow-' + a" /></button>
          </div>
        </div>
        <!-- テンプレート -->
        <div v-else-if="panel === 'templates'" class="w-[22rem] max-h-[70vh] overflow-y-auto">
          <div class="font-semibold text-sm mb-0.5">{{ t('board.templatePickerTitle') }}</div>
          <p class="text-[11px] text-[#6b7c93] mb-3">{{ t('board.templatePickerHint') }}</p>
          <div class="grid grid-cols-2 gap-2">
            <button v-for="k in templateKeys" :key="k" @click="insertTemplate(k)" class="rounded-lg border border-[#e3ddd1] hover:border-[#2f5d7c] hover:shadow overflow-hidden text-left cursor-pointer">
              <div class="h-20 bg-[#f6f2ea] flex items-center justify-center"><img :src="templatePreview(k)" alt="" class="h-full object-contain p-1" /></div>
              <div class="px-2 py-1.5 text-xs font-semibold truncate">{{ t('boards.templates.' + k) }}</div>
            </button>
          </div>
        </div>
      </div>

      <!-- ================= 左下：元に戻す / やり直す / ヘルプ ================= -->
      <div class="absolute left-3 bottom-3 z-30 flex items-center gap-1 rounded-full bg-white shadow-md border border-[#e3ddd1] p-1.5" @pointerdown.stop>
        <button class="tool-btn" :disabled="!undoStack.length" @click="undo" :title="t('board.undo')"><BoardIcon name="undo" /></button>
        <button class="tool-btn" :disabled="!redoStack.length" @click="redo" :title="t('board.redo')"><BoardIcon name="redo" /></button>
        <button class="tool-btn" @click="showHelp = !showHelp" :title="t('board.help')"><BoardIcon name="help" /></button>
      </div>

      <!-- ================= 右下：ズーム / ミニマップ ================= -->
      <div class="absolute right-3 bottom-3 z-30 flex flex-col items-end gap-2" @pointerdown.stop>
        <div v-if="minimap" class="rounded-2xl bg-white shadow-md border border-[#e3ddd1] p-1">
          <svg :width="MINI_W" :height="MINI_H" class="cursor-crosshair" @pointerdown.stop="onMinimapClick" @pointermove="onMinimapMove" @pointerup="endMini" @pointercancel="endMini">
            <rect x="0" y="0" :width="MINI_W" :height="MINI_H" fill="#f6f2ea" />
            <rect v-for="r in miniRects" :key="r.id" :x="r.x" :y="r.y" :width="r.w" :height="r.h" :fill="r.fill" :stroke="r.stroke" stroke-width="0.5" />
            <rect :x="miniView.x" :y="miniView.y" :width="miniView.w" :height="miniView.h" fill="rgba(66,98,255,.12)" stroke="#2f5d7c" stroke-width="1" />
          </svg>
        </div>
        <div class="flex items-center gap-1 rounded-full bg-white shadow-md border border-[#e3ddd1] p-1.5">
          <button class="tool-btn" @click="minimap = !minimap" :class="{ active: minimap }" :title="t('board.zoom.minimap')"><BoardIcon name="map" /></button>
          <button class="tool-btn" @click="zoomBy(1 / 1.25)" :title="t('board.zoom.out')"><BoardIcon name="minus" /></button>
          <div class="relative">
            <button class="text-xs font-semibold w-14 h-9 rounded-full hover:bg-[#f3efe6] cursor-pointer" @click="zoomMenu = !zoomMenu">{{ Math.round(cam.z * 100) }}%</button>
            <div v-if="zoomMenu" class="absolute bottom-11 right-0 w-40 rounded-xl bg-white shadow-xl border border-[#e3ddd1] py-1 text-sm">
              <button class="w-full text-left px-3 py-1.5 hover:bg-[#f3efe6] cursor-pointer" @click="zoomFit(); zoomMenu = false">{{ t('board.zoom.fit') }}</button>
              <button class="w-full text-left px-3 py-1.5 hover:bg-[#f3efe6] cursor-pointer" @click="zoomTo(1); zoomMenu = false">{{ t('board.zoom.reset') }}</button>
              <button v-for="z in [0.5, 2, 4]" :key="z" class="w-full text-left px-3 py-1.5 hover:bg-[#f3efe6] cursor-pointer" @click="zoomTo(z); zoomMenu = false">{{ z * 100 }}%</button>
            </div>
          </div>
          <button class="tool-btn" @click="zoomBy(1.25)" :title="t('board.zoom.in')"><BoardIcon name="plus" /></button>
        </div>
      </div>

      <!-- ================= ヘルプ ================= -->
      <div v-if="showHelp" class="absolute inset-0 z-40 bg-black/30 flex items-center justify-center p-4" @click.self="showHelp = false" @pointerdown.stop>
        <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-md">
          <div class="flex items-center justify-between mb-3">
            <h3 class="font-semibold">{{ t('board.helpTitle') }}</h3>
            <button @click="showHelp = false" class="p-1 rounded hover:bg-[#f3efe6] cursor-pointer"><BoardIcon name="close" /></button>
          </div>
          <ul class="text-sm space-y-1.5 text-[#2f4055]">
            <li v-for="k in ['pan', 'zoom', 'select', 'tools', 'edit', 'copy', 'undo', 'delete', 'esc']" :key="k">{{ t('board.helpItems.' + k) }}</li>
          </ul>
        </div>
      </div>

      <!-- ================= 共有 ================= -->
      <div v-if="showShare" class="absolute inset-0 z-40 bg-black/30 flex items-center justify-center p-4" @click.self="showShare = false" @pointerdown.stop>
        <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-md">
          <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold">{{ t('board.shareTitle') }}</h3>
            <button @click="showShare = false" class="p-1 rounded hover:bg-[#f3efe6] cursor-pointer"><BoardIcon name="close" /></button>
          </div>
          <label class="block text-xs font-semibold text-[#6b7c93] mb-1">{{ t('board.shareLink') }}</label>
          <div class="flex gap-2 mb-5">
            <input :value="shareUrl" readonly class="flex-1 text-sm border border-[#e3ddd1] rounded-md px-2 py-1.5 bg-[#f6f2ea]" />
            <button @click="copyLink" class="px-3 py-1.5 rounded-md bg-[#2f5d7c] text-white text-sm cursor-pointer whitespace-nowrap">{{ copied ? t('board.copied') : t('board.copyLink') }}</button>
          </div>
          <label class="block text-xs font-semibold text-[#6b7c93] mb-1">{{ t('board.shareProject') }}</label>
          <p class="text-[11px] text-[#6b7c93] mb-2">{{ t('board.shareProjectHint') }}</p>
          <select v-model="shareProjectId" :disabled="!canShare" @change="commitShare" class="w-full text-sm border border-[#e3ddd1] rounded-md px-2 py-1.5 bg-white disabled:bg-[#f6f2ea]">
            <option :value="null">{{ t('board.shareNone') }}</option>
            <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.title }}{{ p.company ? ` — ${p.company.name}` : '' }}</option>
          </select>
          <p v-if="!canShare" class="text-[11px] text-[#6b7c93] mt-2">{{ t('board.shareOnlyOwner') }}</p>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '../api'
import { useAuthStore } from '../stores/auth'
import { activeDateLocale } from '../i18n'
import BoardIcon from '../components/board/BoardIcon.vue'
import {
  STICKY_COLORS, SHAPE_FILLS, STROKE_COLORS, FRAME_FILLS, FONT_SIZES, SHAPES, COMMENT_SIZE, TEMPLATE_KEYS,
  shapePath, bounds, unionBounds, connectorPoints, connectorPath, connectorAngle, penPath,
  makeElement, renderThumbnail, thumbnailDataUrl, templateElements, translateElement, cloneElements, emptyBoard, BOARD_VERSION,
} from '../services/board'

const { t, locale } = useI18n()
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const TEXT_TYPES = ['sticky', 'shape', 'text']
const MIN_Z = 0.05, MAX_Z = 4
const MINI_W = 200, MINI_H = 130

/* ------------------------------------------------------------- 状態 */
const board = ref(null)
const notFound = ref(false)
const title = ref('')
const editingTitle = ref(false)
const titleInput = ref(null)
const elements = ref([])
const selection = ref([]) // id の配列（順序は選択順）
const editingId = ref(null)
const tool = ref('select')
const panel = ref(null)
const cam = reactive({ x: 0, y: 0, z: 1 })
const viewport = ref(null)
const fileInput = ref(null)
const spaceDown = ref(false)
const dragKind = ref(null)
const marquee = ref(null)
const previewPen = ref(null)
const previewLine = ref(null)
const previewRect = ref(null)
const hoverTargetId = ref(null)
const ctxPanel = ref(null)
const zoomMenu = ref(false)
const minimap = ref(false)
const showHelp = ref(false)
const showShare = ref(false)
const copied = ref(false)
const projects = ref([])
const shareProjectId = ref(null)
const saveState = ref('saved') // saved | dirty | saving | error
const undoStack = ref([])
const redoStack = ref([])

// ツールのオプション
const stickyColor = ref(STICKY_COLORS[0])
const shapeKind = ref('rect')
const penColor = ref('#1c2f45')
const penWidth = ref(4)
const connectorStyle = ref('straight')
const connectorArrow = ref('end')

const toolbar = [
  { tool: 'select', icon: 'cursor', key: 'v' },
  { tool: 'templates', icon: 'templates' },
  { tool: 'text', icon: 'text', key: 't' },
  { tool: 'sticky', icon: 'sticky', key: 'n' },
  { tool: 'shape', icon: 'shape', key: 's' },
  { tool: 'connector', icon: 'connector', key: 'l' },
  { tool: 'pen', icon: 'pen', key: 'p' },
  { tool: 'comment', icon: 'comment', key: 'c' },
  { tool: 'frame', icon: 'frame', key: 'f' },
  { tool: 'upload', icon: 'upload' },
]
const templateKeys = TEMPLATE_KEYS.filter((k) => k !== 'blank')

let drag = null // 進行中の操作（リアクティブにしない）
const pointers = new Map()
let clipboard = []
let editEl = null
let saveTimer = null
let viewportSize = { w: 1, h: 1 }

/* ------------------------------------------------------------- 派生 */
const byId = computed(() => new Map(elements.value.map((e) => [e.id, e])))
const ordered = computed(() => {
  const frames = [], rest = []
  for (const e of elements.value) (e.type === 'frame' ? frames : rest).push(e)
  return frames.concat(rest)
})
const selected = computed(() => selection.value.map((id) => byId.value.get(id)).filter(Boolean))
const selBox = computed(() => {
  if (!selected.value.length) return null
  const b = unionBounds(selected.value.map((e) => bounds(e, byId.value)))
  return b ? toScreen(b) : null
})
const selectedScreenBoxes = computed(() => selected.value.map((e) => ({ id: e.id, ...toScreen(bounds(e, byId.value)) })))
const resizable = computed(() => selected.value.length === 1 && !['pen', 'connector', 'comment'].includes(selected.value[0].type) && !selected.value[0].locked)
const handles = computed(() => {
  const b = selBox.value
  if (!b) return []
  const cx = b.x + b.w / 2, cy = b.y + b.h / 2
  return [
    { name: 'nw', x: b.x, y: b.y, cursor: 'nwse-resize' }, { name: 'n', x: cx, y: b.y, cursor: 'ns-resize' },
    { name: 'ne', x: b.x + b.w, y: b.y, cursor: 'nesw-resize' }, { name: 'e', x: b.x + b.w, y: cy, cursor: 'ew-resize' },
    { name: 'se', x: b.x + b.w, y: b.y + b.h, cursor: 'nwse-resize' }, { name: 's', x: cx, y: b.y + b.h, cursor: 'ns-resize' },
    { name: 'sw', x: b.x, y: b.y + b.h, cursor: 'nesw-resize' }, { name: 'w', x: b.x, y: cy, cursor: 'ew-resize' },
  ]
})
const connectorEnds = computed(() => {
  if (selected.value.length !== 1 || selected.value[0].type !== 'connector') return null
  const p = connectorPoints(selected.value[0], byId.value)
  return [
    { end: 'from', x: p.x1 * cam.z + cam.x, y: p.y1 * cam.z + cam.y },
    { end: 'to', x: p.x2 * cam.z + cam.x, y: p.y2 * cam.z + cam.y },
  ]
})
const ctx = computed(() => {
  const s = selected.value
  const types = new Set(s.map((e) => e.type))
  const first = (type, key, fallback) => (s.find((e) => e.type === type) || {})[key] ?? fallback
  const textEl = s.find((e) => TEXT_TYPES.includes(e.type))
  const strokeEl = s.find((e) => ['shape', 'pen', 'connector', 'text'].includes(e.type))
  return {
    locked: s.length > 0 && s.every((e) => e.locked),
    sticky: types.has('sticky'), shape: types.has('shape'), frame: types.has('frame'), pen: types.has('pen'),
    connector: types.has('connector'), text: types.has('text'), comment: types.has('comment'),
    hasText: !!textEl,
    fill: first('shape', 'fill', first('frame', 'fill', '#fff')),
    strokeColor: strokeEl ? (strokeEl.type === 'shape' ? strokeEl.stroke : strokeEl.color) : '#1c2f45',
    strokeWidth: strokeEl ? (strokeEl.type === 'shape' ? strokeEl.strokeWidth : strokeEl.width) : 2,
    fontSize: textEl?.fontSize ?? 16, bold: !!textEl?.bold, align: textEl?.align ?? 'center',
    lineStyle: first('connector', 'style', 'straight'), arrow: first('connector', 'arrow', 'end'),
  }
})
const ctxStyle = computed(() => {
  const b = selBox.value
  if (!b) return {}
  const top = b.y - 56
  return { left: Math.max(200, Math.min(viewportSize.w - 200, b.x + b.w / 2)) + 'px', top: (top < 60 ? b.y + b.h + 12 : top) + 'px', transform: 'translateX(-50%)' }
})
const activeComment = computed(() => (selected.value.length === 1 && selected.value[0].type === 'comment' ? selected.value[0] : null))
const commentStyle = computed(() => {
  const c = activeComment.value
  if (!c) return {}
  const s = toScreen(bounds(c))
  return { left: Math.min(viewportSize.w - 300, s.x + s.w + 8) + 'px', top: Math.max(8, Math.min(viewportSize.h - 200, s.y)) + 'px' }
})
const worldStyle = computed(() => ({ transform: `translate(${cam.x}px, ${cam.y}px) scale(${cam.z})` }))
const gridStyle = computed(() => {
  let step = 32 * cam.z
  while (step < 18) step *= 2
  while (step > 64) step /= 2
  return {
    backgroundImage: 'radial-gradient(circle, #c9c2b4 1px, transparent 1.2px)',
    backgroundSize: `${step}px ${step}px`,
    backgroundPosition: `${cam.x}px ${cam.y}px`,
  }
})
const cursorClass = computed(() => {
  if (dragKind.value === 'pan') return 'cursor-grabbing'
  if (spaceDown.value || tool.value === 'hand') return 'cursor-grab'
  if (tool.value === 'eraser') return 'cursor-cell'
  if (tool.value !== 'select') return 'cursor-crosshair'
  return ''
})
const saveLabel = computed(() => ({ saved: t('board.saved'), dirty: t('board.unsaved'), saving: t('board.saving'), error: t('board.saveFailed') }[saveState.value]))
const shareUrl = computed(() => window.location.origin + '/boards/' + route.params.id)
const canShare = computed(() => !!board.value && (board.value.is_owner || auth.isDeveloper))

// ミニマップ
const miniWorld = computed(() => {
  const view = { x: -cam.x / cam.z, y: -cam.y / cam.z, w: viewportSize.w / cam.z, h: viewportSize.h / cam.z }
  const all = elements.value.map((e) => bounds(e, byId.value))
  const bb = unionBounds([view, ...all])
  const pad = Math.max(bb.w, bb.h) * 0.05
  const world = { x: bb.x - pad, y: bb.y - pad, w: bb.w + pad * 2, h: bb.h + pad * 2 }
  const scale = Math.min(MINI_W / world.w, MINI_H / world.h)
  return { world, scale, ox: (MINI_W - world.w * scale) / 2, oy: (MINI_H - world.h * scale) / 2, view }
})
const miniRects = computed(() => {
  if (!minimap.value) return []
  const { world, scale, ox, oy } = miniWorld.value
  return ordered.value.slice(0, 400).map((e) => {
    const b = bounds(e, byId.value)
    const fill = e.type === 'sticky' ? e.color : e.type === 'shape' ? (e.fill === 'transparent' ? 'none' : e.fill) : e.type === 'frame' ? '#fff' : e.type === 'image' ? '#b9b3a6' : 'none'
    return { id: e.id, x: ox + (b.x - world.x) * scale, y: oy + (b.y - world.y) * scale, w: Math.max(1, b.w * scale), h: Math.max(1, b.h * scale), fill, stroke: e.type === 'frame' ? '#b9b3a6' : e.type === 'pen' || e.type === 'connector' ? e.color : 'none' }
  })
})
const miniView = computed(() => {
  const { world, scale, ox, oy, view } = miniWorld.value
  return { x: ox + (view.x - world.x) * scale, y: oy + (view.y - world.y) * scale, w: view.w * scale, h: view.h * scale }
})

/* ------------------------------------------------------------- 座標変換・描画補助 */
function toWorld(clientX, clientY) {
  const r = viewport.value.getBoundingClientRect()
  return { x: (clientX - r.left - cam.x) / cam.z, y: (clientY - r.top - cam.y) / cam.z }
}
function toScreen(b) {
  return { x: b.x * cam.z + cam.x, y: b.y * cam.z + cam.y, w: b.w * cam.z, h: b.h * cam.z }
}
function boxStyle(b) {
  return { left: b.x + 'px', top: b.y + 'px', width: b.w + 'px', height: b.h + 'px' }
}
function svgStyle(el) {
  const b = bounds(el, byId.value)
  return { left: b.x + 'px', top: b.y + 'px' }
}
function textStyle(el) {
  return {
    fontSize: el.fontSize + 'px', fontWeight: el.bold ? 700 : 400, textAlign: el.align || 'center',
    color: el.type === 'sticky' ? '#1c2f45' : el.color || '#1c2f45',
    justifyContent: el.align === 'left' ? 'flex-start' : el.align === 'right' ? 'flex-end' : 'center',
  }
}
function connectorD(el) {
  const p = connectorPoints(el, byId.value)
  return connectorPath(el.style, p.x1, p.y1, p.x2, p.y2)
}
function arrowHead(el, atEnd) {
  const p = connectorPoints(el, byId.value)
  const a = (connectorAngle(el.style, p.x1, p.y1, p.x2, p.y2, atEnd) * Math.PI) / 180
  const tip = atEnd ? { x: p.x2, y: p.y2 } : { x: p.x1, y: p.y1 }
  const size = 6 + el.width * 2
  const l = { x: tip.x - Math.cos(a - 0.45) * size, y: tip.y - Math.sin(a - 0.45) * size }
  const r = { x: tip.x - Math.cos(a + 0.45) * size, y: tip.y - Math.sin(a + 0.45) * size }
  return `M${tip.x},${tip.y} L${l.x},${l.y} L${r.x},${r.y} Z`
}
function isSelected(id) {
  return selection.value.includes(id)
}
function initials(name) {
  return (name || '?').trim().split(/\s+/).map((s) => s[0]).join('').slice(0, 2).toUpperCase()
}
function formatDate(iso) {
  return iso ? new Date(iso).toLocaleString(activeDateLocale()) : ''
}
const previewCache = new Map()
function templatePreview(k) {
  if (!previewCache.has(k)) previewCache.set(k, thumbnailDataUrl(renderThumbnail(templateElements(k, locale.value), { w: 160, h: 90 })))
  return previewCache.get(k)
}

/* ------------------------------------------------------------- 履歴・保存 */
function snapshot() {
  return JSON.stringify(elements.value)
}
function pushHistory() {
  undoStack.value.push(snapshot())
  if (undoStack.value.length > 100) undoStack.value.shift()
  redoStack.value = []
  markDirty()
}
function undo() {
  if (!undoStack.value.length) return
  stopEditing()
  redoStack.value.push(snapshot())
  elements.value = JSON.parse(undoStack.value.pop())
  pruneSelection()
  markDirty()
}
function redo() {
  if (!redoStack.value.length) return
  stopEditing()
  undoStack.value.push(snapshot())
  elements.value = JSON.parse(redoStack.value.pop())
  pruneSelection()
  markDirty()
}
function pruneSelection() {
  selection.value = selection.value.filter((id) => byId.value.has(id))
}
function markDirty() {
  saveState.value = 'dirty'
  clearTimeout(saveTimer)
  saveTimer = setTimeout(save, 1000)
}
function payload() {
  return { content: { version: BOARD_VERSION, elements: elements.value }, thumbnail: renderThumbnail(elements.value) }
}
async function save() {
  if (!board.value) return
  if (saveState.value === 'saving') { saveTimer = setTimeout(save, 500); return }
  saveState.value = 'saving'
  const body = payload()
  try {
    await api.put(`/boards/${board.value.id}`, body)
    // 保存中にさらに変更があれば dirty のまま残す（タイマーが再度 save を呼ぶ）。
    if (saveState.value === 'saving') saveState.value = 'saved'
  } catch {
    saveState.value = 'error'
    saveTimer = setTimeout(save, 5000)
  }
}
function flushOnLeave() {
  if (!board.value || saveState.value === 'saved') return
  // ページを離れる瞬間は axios が完走しないので keepalive の fetch で送る。
  try {
    fetch(`${window.location.origin}/api/boards/${board.value.id}`, {
      method: 'PUT', keepalive: true,
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('token')}` },
      body: JSON.stringify(payload()),
    }).catch(() => {})
  } catch {}
}

/* ------------------------------------------------------------- 読み込み */
async function load() {
  try {
    const res = await api.get(`/boards/${route.params.id}`)
    const b = res.data.data
    board.value = b
    title.value = b.title
    shareProjectId.value = b.project_id
    elements.value = (b.content && Array.isArray(b.content.elements)) ? b.content.elements : emptyBoard().elements
    await nextTick()
    measure()
    if (elements.value.length) zoomFit()
    else { cam.x = viewportSize.w / 2; cam.y = viewportSize.h / 2 }
  } catch (e) {
    if (e.response?.status === 403 || e.response?.status === 404) notFound.value = true
  }
}
function measure() {
  if (viewport.value) viewportSize = { w: viewport.value.clientWidth, h: viewport.value.clientHeight }
}

/* ------------------------------------------------------------- ズーム・パン */
function zoomTo(z, sx, sy) {
  z = Math.min(MAX_Z, Math.max(MIN_Z, z))
  if (sx == null) { sx = viewportSize.w / 2; sy = viewportSize.h / 2 }
  const wx = (sx - cam.x) / cam.z, wy = (sy - cam.y) / cam.z
  cam.z = z
  cam.x = sx - wx * z
  cam.y = sy - wy * z
}
function zoomBy(f) {
  zoomTo(cam.z * f)
}
function zoomFit() {
  const all = elements.value.map((e) => bounds(e, byId.value))
  const bb = unionBounds(all)
  if (!bb || !bb.w || !bb.h) { zoomTo(1); return }
  const z = Math.min(MAX_Z, Math.max(MIN_Z, Math.min(viewportSize.w / (bb.w + 160), viewportSize.h / (bb.h + 160))))
  cam.z = z
  cam.x = (viewportSize.w - bb.w * z) / 2 - bb.x * z
  cam.y = (viewportSize.h - bb.h * z) / 2 - bb.y * z
}
function onWheel(e) {
  if (e.ctrlKey || e.metaKey) {
    const r = viewport.value.getBoundingClientRect()
    const f = Math.exp(-e.deltaY * 0.0022)
    zoomTo(cam.z * f, e.clientX - r.left, e.clientY - r.top)
  } else {
    cam.x -= e.deltaX
    cam.y -= e.deltaY
  }
  zoomMenu.value = false
}
function onMinimapClick(e) {
  centerOnMini(e)
  drag = { kind: 'mini' }
  e.currentTarget.setPointerCapture(e.pointerId)
}
function onMinimapMove(e) {
  if (drag?.kind === 'mini') centerOnMini(e)
}
function endMini() {
  if (drag?.kind === 'mini') drag = null
}
function centerOnMini(e) {
  const r = e.currentTarget.getBoundingClientRect()
  const { world, scale, ox, oy } = miniWorld.value
  const wx = world.x + (e.clientX - r.left - ox) / scale
  const wy = world.y + (e.clientY - r.top - oy) / scale
  cam.x = viewportSize.w / 2 - wx * cam.z
  cam.y = viewportSize.h / 2 - wy * cam.z
}

/* ------------------------------------------------------------- ツール */
function onToolClick(tb) {
  if (tb.tool === 'upload') { fileInput.value?.click(); return }
  if (tb.tool === 'templates') { panel.value = panel.value === 'templates' ? null : 'templates'; return }
  if (tb.tool === 'select') { tool.value = tool.value === 'select' ? 'hand' : 'select'; panel.value = null; return }
  if (['sticky', 'shape', 'pen', 'connector'].includes(tb.tool)) {
    panel.value = panel.value === tb.tool ? null : tb.tool
    tool.value = tb.tool
    return
  }
  panel.value = null
  tool.value = tb.tool
}
function setTool(name) {
  tool.value = name
  panel.value = null
}

/* ------------------------------------------------------------- ポインタ操作 */
function hitElement(target) {
  const node = target?.closest?.('[data-el-id]')
  return node ? byId.value.get(node.dataset.elId) : null
}
function elementAtPoint(clientX, clientY, types) {
  for (const node of document.elementsFromPoint(clientX, clientY)) {
    const wrap = node.closest?.('[data-el-id]')
    if (wrap) {
      const el = byId.value.get(wrap.dataset.elId)
      if (el && (!types || types.includes(el.type))) return el
    }
  }
  return null
}
function frameChildren(frame) {
  return elements.value.filter((e) => {
    if (e.id === frame.id || e.type === 'frame' || e.type === 'connector') return false
    const b = bounds(e, byId.value)
    const cx = b.x + b.w / 2, cy = b.y + b.h / 2
    return cx >= frame.x && cx <= frame.x + frame.w && cy >= frame.y && cy <= frame.y + frame.h
  })
}

function onPointerDown(e) {
  if (e.target.closest('textarea, input, select, button')) return
  zoomMenu.value = false
  ctxPanel.value = null
  const wp = toWorld(e.clientX, e.clientY)
  pointers.set(e.pointerId, { x: e.clientX, y: e.clientY })
  viewport.value.setPointerCapture?.(e.pointerId)

  // 2本指 → ピンチ
  if (pointers.size === 2) {
    const [a, b] = [...pointers.values()]
    drag = { kind: 'pinch', dist: Math.hypot(a.x - b.x, a.y - b.y), z: cam.z, cx: (a.x + b.x) / 2, cy: (a.y + b.y) / 2, camX: cam.x, camY: cam.y }
    dragKind.value = 'pinch'
    previewPen.value = null
    return
  }

  const handle = e.target.dataset?.handle
  const endpoint = e.target.dataset?.endpoint
  const hit = hitElement(e.target)

  if (e.button === 1 || spaceDown.value || tool.value === 'hand' || (e.pointerType === 'touch' && tool.value === 'select' && !hit && !handle)) {
    drag = { kind: 'pan', sx: e.clientX, sy: e.clientY, camX: cam.x, camY: cam.y }
    dragKind.value = 'pan'
    return
  }
  if (e.button === 2) return

  if (editingId.value && (!hit || hit.id !== editingId.value)) stopEditing()

  switch (tool.value) {
    case 'select': {
      if (handle && selected.value.length === 1) {
        const el = selected.value[0]
        pushHistory()
        drag = { kind: 'resize', handle, el, start: { x: el.x, y: el.y, w: el.w, h: el.h }, wp, keepAspect: el.type === 'sticky' || el.type === 'image' }
        dragKind.value = 'resize'
        return
      }
      if (endpoint && selected.value.length === 1) {
        pushHistory()
        drag = { kind: 'endpoint', el: selected.value[0], end: endpoint }
        dragKind.value = 'endpoint'
        return
      }
      if (hit) {
        if (e.shiftKey) {
          selection.value = isSelected(hit.id) ? selection.value.filter((id) => id !== hit.id) : [...selection.value, hit.id]
        } else if (!isSelected(hit.id)) {
          selection.value = [hit.id]
        }
        const moveSet = new Set()
        for (const el of selected.value) {
          if (el.locked) continue
          moveSet.add(el.id)
          if (el.type === 'frame') frameChildren(el).forEach((c) => moveSet.add(c.id))
        }
        drag = { kind: 'move', last: wp, moved: false, ids: [...moveSet], pushed: false }
        dragKind.value = 'move'
        return
      }
      if (!e.shiftKey) selection.value = []
      drag = { kind: 'marquee', start: wp, additive: e.shiftKey, base: [...selection.value] }
      dragKind.value = 'marquee'
      return
    }
    case 'pen': {
      previewPen.value = { points: [[wp.x, wp.y]], color: penColor.value, width: penWidth.value }
      drag = { kind: 'pen', last: wp }
      dragKind.value = 'pen'
      return
    }
    case 'eraser': {
      pushHistory()
      drag = { kind: 'erase' }
      dragKind.value = 'erase'
      eraseAt(e.clientX, e.clientY)
      return
    }
    case 'connector': {
      const from = hit && !['pen', 'connector', 'comment'].includes(hit.type) ? hit : null
      drag = { kind: 'connector', fromId: from?.id || null, start: wp }
      previewLine.value = { x1: wp.x, y1: wp.y, x2: wp.x, y2: wp.y }
      dragKind.value = 'connector'
      return
    }
    case 'sticky': {
      pushHistory()
      const el = makeElement('sticky', wp.x - 100, wp.y - 100, { color: stickyColor.value })
      elements.value.push(el)
      selection.value = [el.id]
      setTool('select')
      startEditing(el.id, false)
      return
    }
    case 'text': {
      pushHistory()
      const el = makeElement('text', wp.x, wp.y - 20)
      elements.value.push(el)
      selection.value = [el.id]
      setTool('select')
      startEditing(el.id, false)
      return
    }
    case 'comment': {
      pushHistory()
      const el = makeElement('comment', wp.x, wp.y - COMMENT_SIZE, { author: auth.user?.name || '' })
      elements.value.push(el)
      selection.value = [el.id]
      setTool('select')
      nextTick(() => editEl?.focus())
      return
    }
    case 'shape':
    case 'frame': {
      drag = { kind: 'create', type: tool.value, start: wp }
      previewRect.value = { x: wp.x, y: wp.y, w: 0, h: 0 }
      dragKind.value = 'create'
      return
    }
  }
}

function onPointerMove(e) {
  if (pointers.has(e.pointerId)) pointers.set(e.pointerId, { x: e.clientX, y: e.clientY })
  if (!drag) return
  const wp = toWorld(e.clientX, e.clientY)
  switch (drag.kind) {
    case 'pan':
      cam.x = drag.camX + (e.clientX - drag.sx)
      cam.y = drag.camY + (e.clientY - drag.sy)
      break
    case 'pinch': {
      if (pointers.size < 2) break
      const [a, b] = [...pointers.values()]
      const dist = Math.hypot(a.x - b.x, a.y - b.y)
      const cx = (a.x + b.x) / 2, cy = (a.y + b.y) / 2
      const r = viewport.value.getBoundingClientRect()
      const z = Math.min(MAX_Z, Math.max(MIN_Z, drag.z * (dist / drag.dist)))
      const wx = (drag.cx - r.left - drag.camX) / drag.z, wy = (drag.cy - r.top - drag.camY) / drag.z
      cam.z = z
      cam.x = cx - r.left - wx * z
      cam.y = cy - r.top - wy * z
      break
    }
    case 'move': {
      const dx = wp.x - drag.last.x, dy = wp.y - drag.last.y
      if (!drag.moved && Math.hypot(dx, dy) * cam.z < 2) break
      if (!drag.pushed) { pushHistory(); drag.pushed = true }
      drag.moved = true
      for (const id of drag.ids) {
        const el = byId.value.get(id)
        if (el) translateElement(el, dx, dy)
      }
      drag.last = wp
      break
    }
    case 'marquee': {
      const x = Math.min(drag.start.x, wp.x), y = Math.min(drag.start.y, wp.y)
      const w = Math.abs(wp.x - drag.start.x), h = Math.abs(wp.y - drag.start.y)
      marquee.value = toScreen({ x, y, w, h })
      const inside = elements.value.filter((el) => {
        const b = bounds(el, byId.value)
        if (el.type === 'frame') return b.x >= x && b.y >= y && b.x + b.w <= x + w && b.y + b.h <= y + h
        return b.x < x + w && b.x + b.w > x && b.y < y + h && b.y + b.h > y
      }).map((el) => el.id)
      selection.value = drag.additive ? [...new Set([...drag.base, ...inside])] : inside
      break
    }
    case 'resize': {
      const { el, start, handle } = drag
      let { x, y, w, h } = start
      const dx = wp.x - drag.wp.x, dy = wp.y - drag.wp.y
      if (handle.includes('e')) w = start.w + dx
      if (handle.includes('s')) h = start.h + dy
      if (handle.includes('w')) { w = start.w - dx; x = start.x + dx }
      if (handle.includes('n')) { h = start.h - dy; y = start.y + dy }
      if (drag.keepAspect && handle.length === 2) {
        const ratio = start.w / start.h
        if (Math.abs(w - start.w) > Math.abs(h - start.h)) h = w / ratio
        else w = h * ratio
        if (handle.includes('w')) x = start.x + start.w - w
        if (handle.includes('n')) y = start.y + start.h - h
      }
      const min = 20
      if (w < min) { if (handle.includes('w')) x = start.x + start.w - min; w = min }
      if (h < min) { if (handle.includes('n')) y = start.y + start.h - min; h = min }
      el.x = x; el.y = y; el.w = w; el.h = h
      break
    }
    case 'endpoint': {
      const target = elementAtPoint(e.clientX, e.clientY, ['sticky', 'shape', 'text', 'frame', 'image'])
      hoverTargetId.value = target?.id || null
      drag.el[drag.end] = target ? { id: target.id } : { x: wp.x, y: wp.y }
      break
    }
    case 'pen': {
      if (Math.hypot(wp.x - drag.last.x, wp.y - drag.last.y) < 1.5 / cam.z) break
      previewPen.value.points.push([wp.x, wp.y])
      drag.last = wp
      break
    }
    case 'erase':
      eraseAt(e.clientX, e.clientY)
      break
    case 'connector': {
      const target = elementAtPoint(e.clientX, e.clientY, ['sticky', 'shape', 'text', 'frame', 'image'])
      hoverTargetId.value = target && target.id !== drag.fromId ? target.id : null
      previewLine.value = { ...previewLine.value, x2: wp.x, y2: wp.y }
      break
    }
    case 'create': {
      previewRect.value = {
        x: Math.min(drag.start.x, wp.x), y: Math.min(drag.start.y, wp.y),
        w: Math.abs(wp.x - drag.start.x), h: Math.abs(wp.y - drag.start.y),
      }
      break
    }
  }
}

function onPointerUp(e) {
  pointers.delete(e.pointerId)
  if (!drag) return
  const wp = toWorld(e.clientX, e.clientY)
  const kind = drag.kind
  switch (kind) {
    case 'pinch':
      if (pointers.size === 0) { drag = null; dragKind.value = null }
      return
    case 'move':
      if (drag.moved) markDirty()
      break
    case 'marquee':
      marquee.value = null
      break
    case 'resize':
    case 'endpoint':
      hoverTargetId.value = null
      markDirty()
      break
    case 'pen': {
      const pts = previewPen.value?.points || []
      previewPen.value = null
      if (pts.length >= 1) {
        pushHistory()
        const el = makeElement('pen', 0, 0, { points: pts, color: penColor.value, width: penWidth.value })
        const b = bounds(el)
        Object.assign(el, { x: b.x, y: b.y, w: b.w, h: b.h })
        elements.value.push(el)
      }
      break
    }
    case 'connector': {
      const target = elementAtPoint(e.clientX, e.clientY, ['sticky', 'shape', 'text', 'frame', 'image'])
      hoverTargetId.value = null
      previewLine.value = null
      const far = Math.hypot(wp.x - drag.start.x, wp.y - drag.start.y) * cam.z > 8
      if (far || (drag.fromId && target && target.id !== drag.fromId)) {
        pushHistory()
        const el = makeElement('connector', drag.start.x, drag.start.y, {
          from: drag.fromId ? { id: drag.fromId } : { x: drag.start.x, y: drag.start.y },
          to: target && target.id !== drag.fromId ? { id: target.id } : { x: wp.x, y: wp.y },
          style: connectorStyle.value, arrow: connectorArrow.value, color: penColor.value,
        })
        elements.value.push(el)
        selection.value = [el.id]
      }
      break
    }
    case 'create': {
      const r = previewRect.value
      previewRect.value = null
      pushHistory()
      let el
      if (drag.type === 'frame') {
        const w = r.w > 40 ? r.w : 800, h = r.h > 40 ? r.h : 600
        el = makeElement('frame', r.w > 40 ? r.x : wp.x - w / 2, r.h > 40 ? r.y : wp.y - h / 2, { w, h })
        elements.value.unshift(el)
      } else {
        const w = r.w > 10 ? r.w : 160, h = r.h > 10 ? r.h : 120
        el = makeElement('shape', r.w > 10 ? r.x : wp.x - w / 2, r.h > 10 ? r.y : wp.y - h / 2, { w, h, shape: shapeKind.value, stroke: penColor.value })
        elements.value.push(el)
      }
      selection.value = [el.id]
      setTool('select')
      break
    }
  }
  drag = null
  dragKind.value = null
}

function eraseAt(clientX, clientY) {
  const hits = document.elementsFromPoint(clientX, clientY).map((n) => n.dataset?.penId).filter(Boolean)
  if (!hits.length) return
  elements.value = elements.value.filter((el) => !hits.includes(el.id))
  markDirty()
}

function onDblClick(e) {
  const hit = hitElement(e.target)
  if (!hit) {
    // 何も無い所をダブルクリック → 付箋を置く（Miro と同じ）
    if (tool.value === 'select') {
      const wp = toWorld(e.clientX, e.clientY)
      pushHistory()
      const el = makeElement('sticky', wp.x - 100, wp.y - 100, { color: stickyColor.value })
      elements.value.push(el)
      selection.value = [el.id]
      startEditing(el.id, false)
    }
    return
  }
  if (hit.locked) { pushHistory(); hit.locked = false; return }
  if (['sticky', 'shape', 'text', 'frame'].includes(hit.type)) {
    selection.value = [hit.id]
    startEditing(hit.id)
  }
}

/* ------------------------------------------------------------- 文字編集 */
function setEditRef(node) {
  if (node) editEl = node
}
function startEditing(id, push = true) {
  if (push) pushHistory()
  editingId.value = id
  nextTick(() => {
    if (!editEl) return
    editEl.focus()
    if (editEl.setSelectionRange && editEl.value) editEl.setSelectionRange(editEl.value.length, editEl.value.length)
  })
}
function stopEditing() {
  if (!editingId.value) return
  const el = byId.value.get(editingId.value)
  editingId.value = null
  if (el && el.type === 'text' && !el.text.trim()) {
    elements.value = elements.value.filter((x) => x.id !== el.id)
    selection.value = selection.value.filter((id) => id !== el.id)
  }
  markDirty()
}
function onTextInput(el, e) {
  el.text = e.target.value
  if (el.type === 'text') {
    e.target.style.height = 'auto'
    el.h = Math.max(el.fontSize * 1.5, e.target.scrollHeight)
    e.target.style.height = ''
  }
  markDirty()
}
function closeComment() {
  const c = activeComment.value
  selection.value = []
  if (c && !c.text.trim()) elements.value = elements.value.filter((x) => x.id !== c.id)
  markDirty()
}

/* ------------------------------------------------------------- 選択要素への操作 */
function setProp(key, value, types) {
  pushHistory()
  for (const el of selected.value) {
    if (!types || types.includes(el.type)) el[key] = value
  }
}
function setStrokeColor(c) {
  pushHistory()
  for (const el of selected.value) {
    if (el.type === 'shape') el.stroke = c
    else if (['pen', 'connector', 'text'].includes(el.type)) el.color = c
  }
}
function setStrokeWidth(w) {
  pushHistory()
  for (const el of selected.value) {
    if (el.type === 'shape') el.strokeWidth = w
    else if (['pen', 'connector'].includes(el.type)) el.width = Math.max(1, w)
  }
}
function deleteSelected() {
  if (!selection.value.length) return
  const ids = new Set(selected.value.filter((e) => !e.locked).map((e) => e.id))
  if (!ids.size) return
  pushHistory()
  elements.value = elements.value.filter((el) => {
    if (ids.has(el.id)) return false
    // 端が消えた接続線も一緒に消す
    if (el.type === 'connector' && ((el.from.id && ids.has(el.from.id)) || (el.to.id && ids.has(el.to.id)))) return false
    return true
  })
  selection.value = []
}
function duplicateSelected() {
  if (!selected.value.length) return
  pushHistory()
  const copies = cloneElements(selected.value)
  elements.value.push(...copies)
  selection.value = copies.map((c) => c.id)
}
function reorder(where) {
  if (!selected.value.length) return
  pushHistory()
  const ids = new Set(selection.value)
  const picked = elements.value.filter((e) => ids.has(e.id))
  const rest = elements.value.filter((e) => !ids.has(e.id))
  elements.value = where === 'front' ? [...rest, ...picked] : [...picked, ...rest]
}
function copySelection() {
  if (selected.value.length) clipboard = JSON.parse(JSON.stringify(selected.value))
}
function paste() {
  if (!clipboard.length) return
  pushHistory()
  const copies = cloneElements(clipboard, 24)
  elements.value.push(...copies)
  selection.value = copies.map((c) => c.id)
  clipboard = JSON.parse(JSON.stringify(copies))
}
function nudge(dx, dy) {
  const movable = selected.value.filter((e) => !e.locked)
  if (!movable.length) return
  pushHistory()
  movable.forEach((el) => translateElement(el, dx, dy))
}

/* ------------------------------------------------------------- テンプレート・画像 */
function insertTemplate(key) {
  const els = templateElements(key, locale.value)
  if (!els.length) return
  pushHistory()
  const bb = unionBounds(els.map((e) => bounds(e, new Map(els.map((x) => [x.id, x])))))
  const cx = (viewportSize.w / 2 - cam.x) / cam.z, cy = (viewportSize.h / 2 - cam.y) / cam.z
  const dx = cx - (bb.x + bb.w / 2), dy = cy - (bb.y + bb.h / 2)
  els.forEach((e) => translateElement(e, dx, dy))
  const frames = els.filter((e) => e.type === 'frame'), rest = els.filter((e) => e.type !== 'frame')
  elements.value.unshift(...frames)
  elements.value.push(...rest)
  selection.value = els.map((e) => e.id)
  panel.value = null
}
function onUpload(e) {
  const file = e.target.files?.[0]
  e.target.value = ''
  if (!file) return
  if (file.size > 2 * 1024 * 1024) { alert(t('board.uploadTooLarge')); return }
  const reader = new FileReader()
  reader.onload = () => {
    const img = new Image()
    img.onload = () => {
      const maxW = 480
      const scale = Math.min(1, maxW / img.naturalWidth)
      const w = img.naturalWidth * scale, h = img.naturalHeight * scale
      const cx = (viewportSize.w / 2 - cam.x) / cam.z, cy = (viewportSize.h / 2 - cam.y) / cam.z
      pushHistory()
      const el = makeElement('image', cx - w / 2, cy - h / 2, { w, h, src: reader.result })
      elements.value.push(el)
      selection.value = [el.id]
      setTool('select')
    }
    img.src = reader.result
  }
  reader.readAsDataURL(file)
}

/* ------------------------------------------------------------- タイトル・スター・共有 */
function startTitleEdit() {
  editingTitle.value = true
  nextTick(() => titleInput.value?.select())
}
async function commitTitle() {
  if (!editingTitle.value) return
  editingTitle.value = false
  const next = title.value.trim() || t('board.untitled')
  title.value = next
  if (board.value && next !== board.value.title) {
    board.value.title = next
    try { await api.put(`/boards/${board.value.id}`, { title: next }) } catch {}
  }
}
async function toggleStar() {
  if (!board.value) return
  board.value.is_starred = !board.value.is_starred
  try { await api.put(`/boards/${board.value.id}`, { is_starred: board.value.is_starred }) } catch {}
}
async function openShare() {
  showShare.value = true
  copied.value = false
  if (!projects.value.length) {
    try { projects.value = (await api.get('/projects')).data?.data || [] } catch {}
  }
}
async function copyLink() {
  try { await navigator.clipboard.writeText(shareUrl.value); copied.value = true } catch {}
}
async function commitShare() {
  if (!board.value || !canShare.value) return
  try {
    const res = await api.put(`/boards/${board.value.id}`, { project_id: shareProjectId.value })
    board.value.project_id = res.data.data.project_id
  } catch {
    shareProjectId.value = board.value.project_id
  }
}

/* ------------------------------------------------------------- キーボード */
function onKeyDown(e) {
  const inField = e.target.closest?.('textarea, input, select')
  if (e.key === 'Escape') {
    if (inField) { e.target.blur(); return }
    if (panel.value) { panel.value = null; return }
    if (selection.value.length) { selection.value = []; return }
    setTool('select')
    return
  }
  if (inField) return
  if (e.code === 'Space') { spaceDown.value = true; e.preventDefault(); return }
  const mod = e.ctrlKey || e.metaKey
  if (mod && e.key.toLowerCase() === 'z') { e.preventDefault(); e.shiftKey ? redo() : undo(); return }
  if (mod && e.key.toLowerCase() === 'y') { e.preventDefault(); redo(); return }
  if (mod && e.key.toLowerCase() === 'c') { e.preventDefault(); copySelection(); return }
  if (mod && e.key.toLowerCase() === 'x') { e.preventDefault(); copySelection(); deleteSelected(); return }
  if (mod && e.key.toLowerCase() === 'v') { e.preventDefault(); paste(); return }
  if (mod && e.key.toLowerCase() === 'd') { e.preventDefault(); duplicateSelected(); return }
  if (mod && e.key.toLowerCase() === 'a') { e.preventDefault(); selection.value = elements.value.map((x) => x.id); return }
  if (mod && (e.key === '=' || e.key === '+')) { e.preventDefault(); zoomBy(1.25); return }
  if (mod && e.key === '-') { e.preventDefault(); zoomBy(1 / 1.25); return }
  if (mod && e.key === '0') { e.preventDefault(); zoomTo(1); return }
  if (mod) return
  if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); deleteSelected(); return }
  if (e.key.startsWith('Arrow')) {
    e.preventDefault()
    const step = e.shiftKey ? 10 : 1
    nudge(e.key === 'ArrowLeft' ? -step : e.key === 'ArrowRight' ? step : 0, e.key === 'ArrowUp' ? -step : e.key === 'ArrowDown' ? step : 0)
    return
  }
  if (e.key === 'Enter' && selected.value.length === 1 && ['sticky', 'shape', 'text', 'frame'].includes(selected.value[0].type)) {
    e.preventDefault(); startEditing(selected.value[0].id); return
  }
  const map = { v: 'select', h: 'hand', t: 'text', n: 'sticky', s: 'shape', l: 'connector', p: 'pen', e: 'eraser', c: 'comment', f: 'frame' }
  const next = map[e.key.toLowerCase()]
  if (next) setTool(next)
}
function onKeyUp(e) {
  if (e.code === 'Space') spaceDown.value = false
}

/* ------------------------------------------------------------- ライフサイクル */
watch(() => route.params.id, () => { if (route.params.id) load() })

function onVisibility() {
  if (document.visibilityState === 'hidden') flushOnLeave()
}

onMounted(() => {
  measure()
  load()
  window.addEventListener('keydown', onKeyDown)
  window.addEventListener('keyup', onKeyUp)
  window.addEventListener('resize', measure)
  window.addEventListener('beforeunload', flushOnLeave)
  document.addEventListener('visibilitychange', onVisibility)
})
onBeforeUnmount(() => {
  clearTimeout(saveTimer)
  flushOnLeave()
  window.removeEventListener('keydown', onKeyDown)
  window.removeEventListener('keyup', onKeyUp)
  window.removeEventListener('resize', measure)
  window.removeEventListener('beforeunload', flushOnLeave)
  document.removeEventListener('visibilitychange', onVisibility)
})
</script>

<style scoped>
@reference "../style.css";

.tool-btn {
  @apply w-10 h-10 rounded-full flex items-center justify-center text-[#1c2f45] hover:bg-[#f3efe6] cursor-pointer transition disabled:opacity-30 disabled:cursor-default;
}
.tool-btn.active {
  @apply bg-[#1c2f45] text-[#f6e7a6];
}
.ctx-btn {
  @apply h-8 min-w-8 px-1.5 rounded-md flex items-center justify-center text-[#1c2f45] hover:bg-[#f3efe6] cursor-pointer transition;
}
.ctx-btn.active {
  @apply bg-[#e6eef3] text-[#1c2f45];
}
.ctx-pop {
  @apply absolute top-10 left-0 z-40 flex flex-wrap gap-1.5 w-52 rounded-2xl bg-white shadow-lg border border-[#e3ddd1] p-2;
}
.ctx-pop.flex-col {
  @apply w-auto;
}
.swatch-transparent {
  background-image: linear-gradient(45deg, #e3ddd1 25%, transparent 25%, transparent 75%, #e3ddd1 75%), linear-gradient(45deg, #e3ddd1 25%, transparent 25%, transparent 75%, #e3ddd1 75%);
  background-size: 8px 8px;
  background-position: 0 0, 4px 4px;
}
.board-text,
.board-textarea {
  width: 100%;
  height: 100%;
  padding: 12px;
  display: flex;
  align-items: center;
  white-space: pre-wrap;
  word-break: break-word;
  line-height: 1.3;
  font-family: inherit;
  overflow: hidden;
}
.board-text {
  pointer-events: none;
}
.board-textarea {
  display: block;
  background: transparent;
  border: none;
  outline: none;
  resize: none;
  cursor: text;
}
.is-dragging {
  user-select: none;
}
</style>
