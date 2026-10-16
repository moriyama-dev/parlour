/*
 * MediaPipe の WASM を node_modules から public/mediapipe/ へ複製する。
 *
 * 背景ぼかし・仮想背景は MediaPipe の人物セグメンテーションで実装してある。
 * これを CDN から読み込むと「顧客との通話映像を処理するコードを外部から
 * 取ってくる」ことになるので、自ドメインから配る。
 *
 * 複製をリポジトリに入れないのは、合計 23MB あって job-hunting 用の公開リポジトリが
 * 太るため。npm パッケージが唯一の出所になるので、バージョンずれも起きない。
 * dev / build の前に自動で走る（package.json の predev / prebuild）。
 */
import { existsSync, mkdirSync, copyFileSync, statSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const src = join(root, 'node_modules', '@mediapipe', 'tasks-vision', 'wasm')
const dest = join(root, 'public', 'mediapipe')

// SIMD 版と、SIMD が無いブラウザ向けのフォールバック。FilesetResolver が
// 実行時にどちらか一方だけを取りに行くので、置いてあっても転送量は増えない。
const files = [
  'vision_wasm_internal.js',
  'vision_wasm_internal.wasm',
  'vision_wasm_nosimd_internal.js',
  'vision_wasm_nosimd_internal.wasm',
]

if (!existsSync(src)) {
  console.error('[mediapipe] @mediapipe/tasks-vision が見つかりません。npm install を実行してください。')
  process.exit(1)
}

mkdirSync(dest, { recursive: true })

let copied = 0
for (const file of files) {
  const from = join(src, file)
  const to = join(dest, file)
  if (!existsSync(from)) {
    console.error(`[mediapipe] ${file} がパッケージにありません。`)
    process.exit(1)
  }
  // 同じサイズなら触らない（毎回 23MB コピーすると dev の起動が遅くなる）。
  if (existsSync(to) && statSync(to).size === statSync(from).size) continue
  copyFileSync(from, to)
  copied++
}

console.log(copied ? `[mediapipe] ${copied} ファイルを public/mediapipe/ へ複製しました。` : '[mediapipe] 最新です。')
