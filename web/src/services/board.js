/**
 * ホワイトボード（「イメージ」）のデータ定義と幾何計算。
 *
 * ボードの中身は { version, elements: [...] } という 1 つの JSON で、要素は配列の
 * 並び順が重ね順（後ろほど前面）。サーバーは構造を解釈しないので、ここが唯一の
 * スキーマ定義になる。
 *
 * 要素の共通フィールド: id, type, x, y, w, h, locked
 *   sticky    text, color, fontSize, bold, align
 *   shape     shape, text, fill, stroke, strokeWidth, fontSize, color, bold, align
 *   text      text, fontSize, color, bold, align
 *   frame     title, fill
 *   image     src (data URL)
 *   comment   text, author, createdAt, resolved  （x,y は点。w,h は固定）
 *   pen       points [[x,y],...] （絶対座標）, color, width
 *   connector from {id?, x, y}, to {id?, x, y}, style, arrow, color, width
 */

export const BOARD_VERSION = 1

// 付箋の色。紙色の背景に馴染むよう彩度を落としたパステル。
export const STICKY_COLORS = [
  '#f6e7a6', '#ead9a0', '#d8e3c1', '#c3d6b0', '#cbdbe6', '#c5dcd6',
  '#ebd3d8', '#e2b7b0', '#f0d0b0', '#d9d2e6', '#e4e0d8', '#ffffff',
]

export const SHAPE_FILLS = [
  'transparent', '#ffffff', '#f3efe6', '#f6e7a6', '#d8e3c1', '#cbdbe6',
  '#c5dcd6', '#ebd3d8', '#e2b7b0', '#f0d0b0', '#d9d2e6', '#2f5d7c', '#1c2f45',
]

export const STROKE_COLORS = [
  '#1c2f45', '#2f5d7c', '#b5544d', '#4f7d5a', '#c8a46b', '#7a6a9c', '#8a8578', '#ffffff',
]

export const FRAME_FILLS = ['#ffffff', '#f6f2ea', '#f9f3e3', '#eef3f6', '#eef4ec', '#f7eef0']

export const FONT_SIZES = [10, 12, 14, 18, 24, 36, 48, 64, 96]

export const SHAPES = [
  'rect', 'rounded', 'ellipse', 'triangle', 'diamond', 'parallelogram',
  'hexagon', 'star', 'arrowRight', 'arrowLeft', 'cylinder', 'cloud',
]

export const COMMENT_SIZE = 36

export function uid() {
  return Math.random().toString(36).slice(2, 10) + Date.now().toString(36).slice(-4)
}

export function emptyBoard() {
  return { version: BOARD_VERSION, elements: [] }
}

/** 図形の輪郭を SVG パスで返す（左上原点、幅 w・高さ h）。 */
export function shapePath(shape, w, h) {
  const r = Math.min(w, h)
  switch (shape) {
    case 'rounded': {
      const rad = Math.min(16, r / 4)
      return `M${rad},0 H${w - rad} Q${w},0 ${w},${rad} V${h - rad} Q${w},${h} ${w - rad},${h} H${rad} Q0,${h} 0,${h - rad} V${rad} Q0,0 ${rad},0 Z`
    }
    case 'ellipse':
      return `M${w / 2},0 A${w / 2},${h / 2} 0 1,0 ${w / 2},${h} A${w / 2},${h / 2} 0 1,0 ${w / 2},0 Z`
    case 'triangle':
      return `M${w / 2},0 L${w},${h} L0,${h} Z`
    case 'diamond':
      return `M${w / 2},0 L${w},${h / 2} L${w / 2},${h} L0,${h / 2} Z`
    case 'parallelogram': {
      const k = w * 0.2
      return `M${k},0 L${w},0 L${w - k},${h} L0,${h} Z`
    }
    case 'hexagon': {
      const k = w * 0.25
      return `M${k},0 L${w - k},0 L${w},${h / 2} L${w - k},${h} L${k},${h} L0,${h / 2} Z`
    }
    case 'star': {
      const cx = w / 2, cy = h / 2, R = 0.5, rr = 0.2
      const pts = []
      for (let i = 0; i < 10; i++) {
        const a = -Math.PI / 2 + (i * Math.PI) / 5
        const rad = i % 2 === 0 ? R : rr
        pts.push(`${cx + Math.cos(a) * rad * w},${cy + Math.sin(a) * rad * h}`)
      }
      return `M${pts.join(' L')} Z`
    }
    case 'arrowRight': {
      const head = Math.min(w * 0.35, h)
      const t = h * 0.25
      return `M0,${t} L${w - head},${t} L${w - head},0 L${w},${h / 2} L${w - head},${h} L${w - head},${h - t} L0,${h - t} Z`
    }
    case 'arrowLeft': {
      const head = Math.min(w * 0.35, h)
      const t = h * 0.25
      return `M${w},${t} L${head},${t} L${head},0 L0,${h / 2} L${head},${h} L${head},${h - t} L${w},${h - t} Z`
    }
    case 'cylinder': {
      const ry = Math.min(h * 0.15, 30)
      return `M0,${ry} A${w / 2},${ry} 0 0,1 ${w},${ry} V${h - ry} A${w / 2},${ry} 0 0,1 0,${h - ry} Z M0,${ry} A${w / 2},${ry} 0 0,0 ${w},${ry}`
    }
    case 'cloud': {
      const rad = Math.min(16, r / 4)
      const tail = Math.min(24, h * 0.2)
      const bh = h - tail
      return `M${rad},0 H${w - rad} Q${w},0 ${w},${rad} V${bh - rad} Q${w},${bh} ${w - rad},${bh} H${w * 0.35} L${w * 0.2},${h} L${w * 0.22},${bh} H${rad} Q0,${bh} 0,${bh - rad} V${rad} Q0,0 ${rad},0 Z`
    }
    case 'rect':
    default:
      return `M0,0 H${w} V${h} H0 Z`
  }
}

/** 要素の外接矩形。pen と connector は中身から計算する。 */
export function bounds(el, byId) {
  if (el.type === 'pen') {
    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
    for (const [x, y] of el.points) {
      if (x < minX) minX = x
      if (y < minY) minY = y
      if (x > maxX) maxX = x
      if (y > maxY) maxY = y
    }
    if (!isFinite(minX)) return { x: el.x || 0, y: el.y || 0, w: 0, h: 0 }
    const pad = (el.width || 2) / 2
    return { x: minX - pad, y: minY - pad, w: maxX - minX + pad * 2, h: maxY - minY + pad * 2 }
  }
  if (el.type === 'connector') {
    const { x1, y1, x2, y2 } = connectorPoints(el, byId)
    const x = Math.min(x1, x2), y = Math.min(y1, y2)
    return { x, y, w: Math.abs(x2 - x1), h: Math.abs(y2 - y1) }
  }
  if (el.type === 'comment') {
    return { x: el.x, y: el.y, w: COMMENT_SIZE, h: COMMENT_SIZE }
  }
  return { x: el.x, y: el.y, w: el.w, h: el.h }
}

export function unionBounds(list) {
  if (!list.length) return null
  let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
  for (const b of list) {
    minX = Math.min(minX, b.x)
    minY = Math.min(minY, b.y)
    maxX = Math.max(maxX, b.x + b.w)
    maxY = Math.max(maxY, b.y + b.h)
  }
  return { x: minX, y: minY, w: maxX - minX, h: maxY - minY }
}

export function center(b) {
  return { x: b.x + b.w / 2, y: b.y + b.h / 2 }
}

/** 矩形の中心から (tx,ty) に向かう直線が矩形の辺と交わる点。接続線の端をここに置く。 */
export function edgePoint(b, tx, ty) {
  const c = center(b)
  const dx = tx - c.x, dy = ty - c.y
  if (dx === 0 && dy === 0) return c
  const hw = b.w / 2, hh = b.h / 2
  const sx = dx !== 0 ? hw / Math.abs(dx) : Infinity
  const sy = dy !== 0 ? hh / Math.abs(dy) : Infinity
  const s = Math.min(sx, sy)
  return { x: c.x + dx * s, y: c.y + dy * s }
}

/** 接続線の両端の座標。相手が要素なら、その要素の辺に吸着させる。 */
export function connectorPoints(el, byId) {
  const fromEl = el.from?.id ? byId?.get(el.from.id) : null
  const toEl = el.to?.id ? byId?.get(el.to.id) : null
  const fb = fromEl ? bounds(fromEl, byId) : null
  const tb = toEl ? bounds(toEl, byId) : null
  const fc = fb ? center(fb) : { x: el.from.x, y: el.from.y }
  const tc = tb ? center(tb) : { x: el.to.x, y: el.to.y }
  const p1 = fb ? edgePoint(fb, tc.x, tc.y) : fc
  const p2 = tb ? edgePoint(tb, fc.x, fc.y) : tc
  return { x1: p1.x, y1: p1.y, x2: p2.x, y2: p2.y }
}

/** 接続線のパス（座標は絶対）。 */
export function connectorPath(style, x1, y1, x2, y2) {
  if (style === 'curved') {
    const dx = Math.abs(x2 - x1), dy = Math.abs(y2 - y1)
    if (dx >= dy) {
      const k = dx / 2
      return `M${x1},${y1} C${x1 + Math.sign(x2 - x1) * k},${y1} ${x2 - Math.sign(x2 - x1) * k},${y2} ${x2},${y2}`
    }
    const k = dy / 2
    return `M${x1},${y1} C${x1},${y1 + Math.sign(y2 - y1) * k} ${x2},${y2 - Math.sign(y2 - y1) * k} ${x2},${y2}`
  }
  if (style === 'elbow') {
    const dx = Math.abs(x2 - x1), dy = Math.abs(y2 - y1)
    if (dx >= dy) {
      const mx = (x1 + x2) / 2
      return `M${x1},${y1} L${mx},${y1} L${mx},${y2} L${x2},${y2}`
    }
    const my = (y1 + y2) / 2
    return `M${x1},${y1} L${x1},${my} L${x2},${my} L${x2},${y2}`
  }
  return `M${x1},${y1} L${x2},${y2}`
}

/** 接続線の終端の向き（矢印の回転用）。 */
export function connectorAngle(style, x1, y1, x2, y2, atEnd = true) {
  let ax, ay
  if (style === 'elbow') {
    const dx = Math.abs(x2 - x1), dy = Math.abs(y2 - y1)
    if (dx >= dy) { ax = atEnd ? Math.sign(x2 - x1) : Math.sign(x1 - x2); ay = 0 }
    else { ax = 0; ay = atEnd ? Math.sign(y2 - y1) : Math.sign(y1 - y2) }
  } else if (style === 'curved') {
    const dx = Math.abs(x2 - x1), dy = Math.abs(y2 - y1)
    if (dx >= dy) { ax = atEnd ? Math.sign(x2 - x1) : Math.sign(x1 - x2); ay = 0 }
    else { ax = 0; ay = atEnd ? Math.sign(y2 - y1) : Math.sign(y1 - y2) }
  } else {
    ax = atEnd ? x2 - x1 : x1 - x2
    ay = atEnd ? y2 - y1 : y1 - y2
  }
  return (Math.atan2(ay, ax) * 180) / Math.PI
}

export function penPath(points) {
  if (!points.length) return ''
  if (points.length === 1) return `M${points[0][0]},${points[0][1]} l0.1,0`
  let d = `M${points[0][0]},${points[0][1]}`
  for (let i = 1; i < points.length - 1; i++) {
    const mx = (points[i][0] + points[i + 1][0]) / 2
    const my = (points[i][1] + points[i + 1][1]) / 2
    d += ` Q${points[i][0]},${points[i][1]} ${mx},${my}`
  }
  const last = points[points.length - 1]
  d += ` L${last[0]},${last[1]}`
  return d
}

/** 新しい要素の雛形。(x,y) は左上。 */
export function makeElement(type, x, y, opts = {}) {
  const base = { id: uid(), type, x, y, locked: false }
  switch (type) {
    case 'sticky':
      return { ...base, w: 200, h: 200, text: '', color: STICKY_COLORS[0], fontSize: 18, bold: false, align: 'center', ...opts }
    case 'shape':
      return { ...base, w: 160, h: 120, shape: 'rect', text: '', fill: '#ffffff', stroke: '#1c2f45', strokeWidth: 2, fontSize: 16, color: '#1c2f45', bold: false, align: 'center', ...opts }
    case 'text':
      return { ...base, w: 240, h: 40, text: '', fontSize: 24, color: '#1c2f45', bold: false, align: 'left', ...opts }
    case 'frame':
      return { ...base, w: 800, h: 600, title: '', fill: '#ffffff', ...opts }
    case 'image':
      return { ...base, w: 320, h: 240, src: '', ...opts }
    case 'comment':
      return { ...base, w: COMMENT_SIZE, h: COMMENT_SIZE, text: '', author: '', createdAt: new Date().toISOString(), resolved: false, ...opts }
    case 'pen':
      return { ...base, w: 0, h: 0, points: [], color: '#1c2f45', width: 3, ...opts }
    case 'connector':
      return { ...base, w: 0, h: 0, from: { x, y }, to: { x: x + 100, y }, style: 'straight', arrow: 'end', color: '#1c2f45', width: 2, ...opts }
    default:
      return { ...base, w: 100, h: 100, ...opts }
  }
}

/** 一覧用サムネイル（軽い SVG 文字列）。data URL の <img> として描くので script は動かない。 */
export function renderThumbnail(elements, size = { w: 320, h: 200 }) {
  const byId = new Map(elements.map((e) => [e.id, e]))
  const list = elements.filter((e) => e.type !== 'comment')
  const bb = unionBounds(list.map((e) => bounds(e, byId)))
  if (!bb || bb.w === 0 || bb.h === 0) return ''
  const pad = Math.max(bb.w, bb.h) * 0.06
  const vb = { x: bb.x - pad, y: bb.y - pad, w: bb.w + pad * 2, h: bb.h + pad * 2 }
  const scale = Math.min(size.w / vb.w, size.h / vb.h)
  const ox = (size.w - vb.w * scale) / 2, oy = (size.h - vb.h * scale) / 2
  const r = (n) => Math.round(n * 10) / 10
  const parts = []
  const frames = list.filter((e) => e.type === 'frame')
  const rest = list.filter((e) => e.type !== 'frame')
  for (const el of [...frames, ...rest].slice(0, 400)) {
    const b = bounds(el, byId)
    const x = r(ox + (b.x - vb.x) * scale), y = r(oy + (b.y - vb.y) * scale)
    const w = r(b.w * scale), h = r(b.h * scale)
    switch (el.type) {
      case 'sticky':
        parts.push(`<rect x="${x}" y="${y}" width="${w}" height="${h}" fill="${el.color}" stroke="rgba(0,0,0,.08)"/>`)
        break
      case 'shape': {
        const fill = el.fill === 'transparent' ? 'none' : el.fill
        parts.push(`<g transform="translate(${x},${y}) scale(${r(scale)})"><path d="${shapePath(el.shape, b.w, b.h)}" fill="${fill}" stroke="${el.stroke}" stroke-width="${(el.strokeWidth || 1) / scale}" vector-effect="non-scaling-stroke"/></g>`)
        break
      }
      case 'text':
        parts.push(`<rect x="${x}" y="${y}" width="${w}" height="${Math.max(2, h)}" fill="rgba(5,0,56,.18)"/>`)
        break
      case 'frame':
        parts.push(`<rect x="${x}" y="${y}" width="${w}" height="${h}" fill="${el.fill || '#fff'}" stroke="#b9b3a6"/>`)
        break
      case 'image':
        parts.push(`<rect x="${x}" y="${y}" width="${w}" height="${h}" fill="#dfe3ea"/>`)
        break
      case 'pen': {
        const pts = el.points.map(([px, py]) => `${r(ox + (px - vb.x) * scale)},${r(oy + (py - vb.y) * scale)}`).join(' ')
        parts.push(`<polyline points="${pts}" fill="none" stroke="${el.color}" stroke-width="${Math.max(1, r(el.width * scale))}" stroke-linecap="round" stroke-linejoin="round"/>`)
        break
      }
      case 'connector': {
        const p = connectorPoints(el, byId)
        parts.push(`<line x1="${r(ox + (p.x1 - vb.x) * scale)}" y1="${r(oy + (p.y1 - vb.y) * scale)}" x2="${r(ox + (p.x2 - vb.x) * scale)}" y2="${r(oy + (p.y2 - vb.y) * scale)}" stroke="${el.color}" stroke-width="1.2"/>`)
        break
      }
    }
  }
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size.w} ${size.h}" width="${size.w}" height="${size.h}">${parts.join('')}</svg>`
}

export function thumbnailDataUrl(svg) {
  if (!svg) return ''
  return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg)
}

/* ---------------------------------------------------------------- テンプレート */

const TEMPLATE_KEYS = ['blank', 'kanban', 'flowchart', 'mindmap', 'retro', 'brainstorm', 'swot', 'wireframe']
export { TEMPLATE_KEYS }

const TEMPLATE_TEXT = {
  ja: {
    todo: '未着手', doing: '進行中', done: '完了',
    task: 'タスク', start: '開始', step: '処理', decision: '判断？', end: '終了', yes: 'はい', no: 'いいえ',
    central: '中心テーマ', branch: 'アイデア', leaf: '詳細',
    went: 'よかったこと', improve: '改善したいこと', action: '次にやること', note: '付箋',
    topic: 'テーマ', idea: 'アイデア',
    strengths: '強み', weaknesses: '弱み', opportunities: '機会', threats: '脅威',
    header: 'ヘッダー', hero: 'メインビジュアル', content: 'コンテンツ', footer: 'フッター', logo: 'ロゴ', nav: 'メニュー', cta: 'ボタン',
  },
  en: {
    todo: 'To do', doing: 'In progress', done: 'Done',
    task: 'Task', start: 'Start', step: 'Process', decision: 'Decision?', end: 'End', yes: 'Yes', no: 'No',
    central: 'Central topic', branch: 'Idea', leaf: 'Detail',
    went: 'What went well', improve: 'What to improve', action: 'Action items', note: 'Note',
    topic: 'Topic', idea: 'Idea',
    strengths: 'Strengths', weaknesses: 'Weaknesses', opportunities: 'Opportunities', threats: 'Threats',
    header: 'Header', hero: 'Hero image', content: 'Content', footer: 'Footer', logo: 'Logo', nav: 'Nav', cta: 'Button',
  },
}

/** テンプレートの要素一覧を (ox,oy) を左上として生成する。 */
export function templateElements(key, locale = 'ja', ox = 0, oy = 0) {
  const T = TEMPLATE_TEXT[locale] || TEMPLATE_TEXT.ja
  const els = []
  const frame = (x, y, w, h, title, fill) => { const e = makeElement('frame', ox + x, oy + y, { w, h, title, fill: fill || '#ffffff' }); els.push(e); return e }
  const sticky = (x, y, text, color, w = 160, h = 160) => { const e = makeElement('sticky', ox + x, oy + y, { text, color, w, h, fontSize: 16 }); els.push(e); return e }
  const shape = (x, y, w, h, shape, text, fill, extra = {}) => { const e = makeElement('shape', ox + x, oy + y, { w, h, shape, text, fill: fill || '#ffffff', ...extra }); els.push(e); return e }
  const text = (x, y, w, txt, fontSize = 24, extra = {}) => { const e = makeElement('text', ox + x, oy + y, { w, h: fontSize * 1.5, text: txt, fontSize, bold: true, ...extra }); els.push(e); return e }
  const link = (a, b, extra = {}) => { const e = makeElement('connector', 0, 0, { from: { id: a.id }, to: { id: b.id }, ...extra }); els.push(e); return e }

  switch (key) {
    case 'kanban': {
      const cols = [[T.todo, '#f6e7a6'], [T.doing, '#cbdbe6'], [T.done, '#d8e3c1']]
      cols.forEach(([title, color], i) => {
        frame(i * 360, 0, 340, 720, title, '#f6f2ea')
        for (let j = 0; j < (i === 0 ? 3 : i === 1 ? 2 : 1); j++) {
          sticky(i * 360 + 20, 80 + j * 190, `${T.task} ${j + 1}`, color, 300, 170)
        }
      })
      break
    }
    case 'flowchart': {
      const s = shape(0, 0, 180, 80, 'rounded', T.start, '#d8e3c1')
      const p1 = shape(0, 160, 180, 80, 'rect', T.step, '#ffffff')
      const d = shape(-10, 320, 200, 120, 'diamond', T.decision, '#f6e7a6')
      const p2 = shape(300, 340, 180, 80, 'rect', T.step, '#ffffff')
      const e = shape(0, 540, 180, 80, 'rounded', T.end, '#e2b7b0')
      link(s, p1); link(p1, d); link(d, p2, { style: 'elbow' }); link(d, e); link(p2, e, { style: 'elbow' })
      text(190, 420, 60, T.yes, 14, { bold: false }); text(60, 470, 60, T.no, 14, { bold: false })
      break
    }
    case 'mindmap': {
      const c = shape(400, 260, 220, 90, 'ellipse', T.central, '#2f5d7c', { color: '#ffffff', fontSize: 18, bold: true })
      const pos = [[40, 60], [40, 460], [800, 60], [800, 460], [420, 20], [420, 520]]
      const colors = ['#f6e7a6', '#d8e3c1', '#cbdbe6', '#ebd3d8', '#f0d0b0', '#d9d2e6']
      pos.forEach(([x, y], i) => {
        const b = shape(x, y, 170, 60, 'rounded', `${T.branch} ${i + 1}`, colors[i])
        link(c, b, { style: 'curved', arrow: 'none' })
        const lx = x < 400 ? x - 200 : x > 500 ? x + 200 : x
        const ly = y < 100 ? y - 90 : y > 400 ? y + 90 : y
        if (i < 4) {
          const l = shape(lx, ly, 140, 44, 'rounded', T.leaf, '#ffffff', { fontSize: 14 })
          link(b, l, { style: 'curved', arrow: 'none' })
        }
      })
      break
    }
    case 'retro': {
      const cols = [[T.went, '#d8e3c1'], [T.improve, '#ebd3d8'], [T.action, '#cbdbe6']]
      cols.forEach(([title, color], i) => {
        frame(i * 380, 0, 360, 640, title, '#ffffff')
        sticky(i * 380 + 30, 90, T.note, color, 140, 140)
        sticky(i * 380 + 190, 90, T.note, color, 140, 140)
      })
      break
    }
    case 'brainstorm': {
      frame(0, 0, 900, 620, T.topic, '#ffffff')
      text(30, 30, 500, T.topic, 32)
      const colors = ['#f6e7a6', '#ead9a0', '#d8e3c1', '#cbdbe6', '#ebd3d8', '#f0d0b0', '#d9d2e6', '#c5dcd6', '#e4e0d8', '#f6e7a6']
      for (let i = 0; i < 10; i++) {
        sticky(30 + (i % 5) * 172, 110 + Math.floor(i / 5) * 250, `${T.idea} ${i + 1}`, colors[i], 150, 150)
      }
      break
    }
    case 'swot': {
      const q = [[T.strengths, '#d8e3c1', 0, 0], [T.weaknesses, '#ebd3d8', 460, 0], [T.opportunities, '#cbdbe6', 0, 400], [T.threats, '#f6e7a6', 460, 400]]
      q.forEach(([title, color, x, y]) => {
        frame(x, y, 440, 380, title, '#ffffff')
        sticky(x + 30, y + 80, '', color, 140, 140)
        sticky(x + 190, y + 80, '', color, 140, 140)
      })
      break
    }
    case 'wireframe': {
      frame(0, 0, 720, 980, T.header, '#ffffff')
      shape(20, 20, 680, 80, 'rect', '', '#f3efe6', { strokeWidth: 1, stroke: '#8a8578' })
      shape(40, 40, 120, 40, 'rect', T.logo, '#ffffff', { strokeWidth: 1, stroke: '#8a8578', fontSize: 14 })
      shape(420, 40, 260, 40, 'rect', T.nav, '#ffffff', { strokeWidth: 1, stroke: '#8a8578', fontSize: 14 })
      shape(20, 120, 680, 320, 'rect', T.hero, '#f3efe6', { strokeWidth: 1, stroke: '#8a8578', fontSize: 20 })
      shape(260, 360, 200, 50, 'rounded', T.cta, '#2f5d7c', { color: '#ffffff', strokeWidth: 0, fontSize: 16 })
      for (let i = 0; i < 3; i++) shape(20 + i * 230, 470, 220, 200, 'rect', T.content, '#ffffff', { strokeWidth: 1, stroke: '#8a8578', fontSize: 14 })
      shape(20, 700, 680, 180, 'rect', T.content, '#ffffff', { strokeWidth: 1, stroke: '#8a8578', fontSize: 14 })
      shape(20, 900, 680, 60, 'rect', T.footer, '#f3efe6', { strokeWidth: 1, stroke: '#8a8578', fontSize: 14 })
      break
    }
    default:
      break
  }
  return els
}

/** 要素を dx,dy だけ動かす（pen は点も動かす）。 */
export function translateElement(el, dx, dy) {
  if (el.type === 'pen') {
    el.points = el.points.map(([x, y]) => [x + dx, y + dy])
    const b = bounds(el)
    el.x = b.x; el.y = b.y; el.w = b.w; el.h = b.h
    return
  }
  if (el.type === 'connector') {
    if (!el.from.id) { el.from.x += dx; el.from.y += dy }
    if (!el.to.id) { el.to.x += dx; el.to.y += dy }
    return
  }
  el.x += dx
  el.y += dy
}

export function cloneElements(els, offset = 20) {
  const idMap = new Map()
  const out = els.map((e) => {
    const c = JSON.parse(JSON.stringify(e))
    idMap.set(e.id, c.id = uid())
    return c
  })
  for (const c of out) {
    if (c.type === 'connector') {
      if (c.from.id) c.from.id = idMap.get(c.from.id) || null
      if (c.to.id) c.to.id = idMap.get(c.to.id) || null
      if (!c.from.id && c.from.x == null) c.from = { x: 0, y: 0 }
      if (!c.to.id && c.to.x == null) c.to = { x: 100, y: 0 }
    }
    translateElement(c, offset, offset)
  }
  return out
}
