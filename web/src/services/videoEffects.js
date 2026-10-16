/*
 * 背景ぼかし / 仮想背景。
 *
 * カメラ映像を canvas に描き直して、その canvas から MediaStreamTrack を取り出す。
 * 送信側で差し替えるので、相手のブラウザには普通の映像として届く（相手側に何も要らない）。
 *
 * 人物と背景の切り分けは MediaPipe の Image Segmenter。WASM とモデルは
 * public/mediapipe と public/models から配る。顧客との通話映像を処理するコードを
 * 外部 CDN から取ってくるのは筋が悪いので、CDN は使わない。
 */
import { FilesetResolver, ImageSegmenter } from '@mediapipe/tasks-vision'

export const EFFECT_NONE = 'none'
export const EFFECT_BLUR = 'blur'
export const EFFECT_IMAGE = 'image'

const WASM_PATH = '/mediapipe'
const MODEL_PATH = '/models/selfie_segmenter.tflite'

// 出力フレームレート。30 まで上げても見た目はほとんど変わらず CPU だけ食う。
const FPS = 24

/**
 * セグメンターは重い（WASM 12MB ＋ モデル読み込み）ので、タブ内で 1 つだけ作って使い回す。
 */
let segmenterPromise = null

async function getSegmenter() {
  if (!segmenterPromise) {
    segmenterPromise = (async () => {
      const fileset = await FilesetResolver.forVisionTasks(WASM_PATH)
      return ImageSegmenter.createFromOptions(fileset, {
        baseOptions: {
          modelAssetPath: MODEL_PATH,
          // GPU が使えない環境（古い GPU・リモートデスクトップ等）では CPU に落ちる。
          delegate: 'GPU',
        },
        runningMode: 'VIDEO',
        outputCategoryMask: true,
        outputConfidenceMasks: false,
      })
    })().catch((e) => {
      // 失敗を握ったままにすると次回以降ずっと同じ失敗を返してしまう。
      segmenterPromise = null
      throw e
    })
  }
  return segmenterPromise
}

/**
 * 背景の判定。
 *
 * MediaPipe の categoryMask は「どちらの値が人物か」が公式ドキュメントに明記されておらず、
 * モデルやバージョンで 0/1 だったり 0/255 だったりする。取り違えると人物の方がぼける。
 *
 * そこで値を決め打ちせず、フレーム上端の帯を見て判定する。ウェブカメラの自撮りで
 * 画面の一番上のラインが人物で埋まることはまず無いので、そこで多数派の値が背景。
 * どのモデルを使っても自動で正しい向きに揃う。
 */
function detectBackgroundValue(mask, width, height) {
  const counts = new Map()
  const rows = Math.max(1, Math.round(height * 0.06))

  for (let y = 0; y < rows; y++) {
    const offset = y * width
    for (let x = 0; x < width; x += 2) {
      const v = mask[offset + x]
      counts.set(v, (counts.get(v) || 0) + 1)
    }
  }

  let best = 0
  let bestCount = -1
  for (const [value, count] of counts) {
    if (count > bestCount) {
      best = value
      bestCount = count
    }
  }
  return best
}

export class VideoEffects {
  constructor() {
    this.effect = EFFECT_NONE
    this.blurPx = 12
    this.backgroundImage = null

    this.video = null
    this.output = null
    this.person = null
    this.maskCanvas = null

    this.running = false
    this.frameHandle = null
    this.lastTimestamp = -1

    // 背景を表す mask 値。数フレームごとに測り直して、照明や構図が変わっても追従させる。
    this.backgroundValue = null
    this.framesSinceProbe = 0

    this.onError = null
  }

  get isActive() {
    return this.running && this.effect !== EFFECT_NONE
  }

  /**
   * カメラのトラックを受け取り、加工後のトラックを返す。
   * 呼び出し側はこれを sender.replaceTrack() に渡す。
   */
  async start(sourceTrack) {
    await this.stop()

    const settings = sourceTrack.getSettings()
    const width = settings.width || 640
    const height = settings.height || 480

    this.video = document.createElement('video')
    this.video.playsInline = true
    this.video.muted = true
    this.video.srcObject = new MediaStream([sourceTrack])
    await this.video.play()

    this.output = document.createElement('canvas')
    this.output.width = width
    this.output.height = height
    this.outputCtx = this.output.getContext('2d')

    // 人物だけを切り抜くための作業用。マスクを alpha として掛けるのに使う。
    this.person = document.createElement('canvas')
    this.person.width = width
    this.person.height = height
    this.personCtx = this.person.getContext('2d')

    // マスクはモデルの解像度（出力より小さい）。拡大時の補間がそのまま輪郭の
    // ぼかしになるので、境界のギザギザ取りを別途やらなくて済む。
    this.maskCanvas = document.createElement('canvas')
    this.maskCtx = this.maskCanvas.getContext('2d')

    this.running = true
    this.loop()
    this.stream = this.output.captureStream(FPS)

    // セグメンターの読み込み（WASM 12MB）は待たない。待つとボタンを押してから
    // 数秒間 replaceTrack が走らず、その間だけ相手側の映像が止まって見える。
    // 読み込みが終わるまでは canvas に素通しで描いておき、終わった時点で効き始める。
    getSegmenter()
      .then((segmenter) => { this.segmenter = segmenter })
      .catch((e) => {
        // WASM が読めない環境では素通し（エフェクト無し）で通話を続ける。
        this.segmenter = null
        this.onError?.(e)
      })

    return this.stream.getVideoTracks()[0]
  }

  setEffect(effect, options = {}) {
    this.effect = effect
    if (typeof options.blurPx === 'number') this.blurPx = options.blurPx
    if ('backgroundImage' in options) this.backgroundImage = options.backgroundImage
  }

  async stop() {
    this.running = false
    if (this.frameHandle) {
      cancelAnimationFrame(this.frameHandle)
      this.frameHandle = null
    }
    if (this.stream) {
      this.stream.getTracks().forEach((t) => t.stop())
      this.stream = null
    }
    if (this.video) {
      this.video.pause()
      this.video.srcObject = null
      this.video = null
    }
    this.backgroundValue = null
    this.lastTimestamp = -1
  }

  loop = () => {
    if (!this.running) return
    this.frameHandle = requestAnimationFrame(this.loop)

    const video = this.video
    if (!video || video.readyState < 2) return

    // captureStream は canvas への描画をそのままフレームにする。エフェクト無しの
    // ときも描き続けないと映像が止まるので、素通しで描く。
    if (!this.segmenter || this.effect === EFFECT_NONE) {
      this.outputCtx.filter = 'none'
      this.outputCtx.drawImage(video, 0, 0, this.output.width, this.output.height)
      return
    }

    // segmentForVideo は単調増加のタイムスタンプを要求する。
    const timestamp = performance.now()
    if (timestamp <= this.lastTimestamp) return
    this.lastTimestamp = timestamp

    try {
      this.segmenter.segmentForVideo(video, timestamp, (result) => {
        this.composite(video, result)
        result.close()
      })
    } catch {
      // 1 フレーム落ちても次で回復する。ここで例外を投げるとループごと止まる。
    }
  }

  composite(video, result) {
    const categoryMask = result.categoryMask
    if (!categoryMask) return

    const w = categoryMask.width
    const h = categoryMask.height
    const mask = categoryMask.getAsUint8Array()

    // 30 フレームに 1 回だけ測る。毎フレームやるほど変わるものではない。
    if (this.backgroundValue === null || this.framesSinceProbe++ > 30) {
      this.backgroundValue = detectBackgroundValue(mask, w, h)
      this.framesSinceProbe = 0
    }

    if (this.maskCanvas.width !== w || this.maskCanvas.height !== h) {
      this.maskCanvas.width = w
      this.maskCanvas.height = h
    }

    // 人物のところだけ alpha=255 の画像を作る。RGB は使わないので触らない。
    const imageData = this.maskCtx.createImageData(w, h)
    const px = imageData.data
    const bg = this.backgroundValue
    for (let i = 0, j = 3; i < mask.length; i++, j += 4) {
      px[j] = mask[i] === bg ? 0 : 255
    }
    this.maskCtx.putImageData(imageData, 0, 0)

    const { width, height } = this.output

    // 1. 背景を敷く
    this.outputCtx.filter = 'none'
    if (this.effect === EFFECT_IMAGE && this.backgroundImage) {
      this.drawCover(this.outputCtx, this.backgroundImage, width, height)
    } else {
      this.outputCtx.filter = `blur(${this.blurPx}px)`
      this.outputCtx.drawImage(video, 0, 0, width, height)
      this.outputCtx.filter = 'none'
    }

    // 2. 人物を切り抜く（鮮明な映像 × マスク）
    this.personCtx.globalCompositeOperation = 'source-over'
    this.personCtx.clearRect(0, 0, width, height)
    this.personCtx.drawImage(video, 0, 0, width, height)
    this.personCtx.globalCompositeOperation = 'destination-in'
    this.personCtx.imageSmoothingEnabled = true
    this.personCtx.drawImage(this.maskCanvas, 0, 0, width, height)
    this.personCtx.globalCompositeOperation = 'source-over'

    // 3. 背景の上に人物を重ねる
    this.outputCtx.drawImage(this.person, 0, 0)
  }

  /**
   * 背景画像を CSS の object-fit: cover と同じ規則で敷く。
   * そのまま drawImage すると縦横比が崩れて安っぽく見える。
   */
  drawCover(ctx, image, width, height) {
    const iw = image.naturalWidth || image.width
    const ih = image.naturalHeight || image.height
    if (!iw || !ih) return

    const scale = Math.max(width / iw, height / ih)
    const dw = iw * scale
    const dh = ih * scale
    ctx.drawImage(image, (width - dw) / 2, (height - dh) / 2, dw, dh)
  }
}

/**
 * ブラウザがこの機能を動かせるか。Safari の古い版などは WASM/canvas.captureStream が
 * 揃わないので、UI 側でボタンごと隠すために使う。
 */
export function effectsSupported() {
  return (
    typeof WebAssembly === 'object' &&
    typeof HTMLCanvasElement !== 'undefined' &&
    typeof HTMLCanvasElement.prototype.captureStream === 'function'
  )
}
