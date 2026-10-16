/*
 * ビデオ通話の中身。
 *
 * 構成:
 *   - 映像/音声/画面共有/チャット/ファイルは全てブラウザ間の P2P（WebRTC）。
 *     サーバーは中身を一切見ないし、中継もしない。
 *   - 接続を成立させるための SDP と ICE 候補のやり取り（シグナリング）だけを
 *     Laravel 経由の HTTP ポーリングで行う。WebSocket を使っていないのは、移行先の
 *     Dreamhost 共有ホスティングが常駐プロセスを持てないため。やり取りは接続確立の
 *     数秒間に集中し、その後はほぼ空振りのポーリングになるので実用上これで足りる。
 *   - 4 人までの想定なのでフルメッシュ（各自が相手全員と 1 本ずつ張る）。SFU は要らない。
 */
import api from '../api'

// シグナルの送信はまとめる。ICE 候補は接続直前に十数個まとめて出るので、
// 1 件ずつ POST すると無駄なリクエストが並ぶ。
const SIGNAL_FLUSH_MS = 120

// ファイル送信のチャンクサイズ。SCTP の実用上の上限に対して十分小さい値。
const FILE_CHUNK_SIZE = 16 * 1024

// 送信バッファがこれを超えたら、掃けるまで送信を止める（メモリを食い潰さないため）。
const DC_BUFFER_HIGH = 1 * 1024 * 1024
const DC_BUFFER_LOW = 256 * 1024

/*
 * 受信ファイルの上限。
 *
 * 受け取った塊はメモリ上の配列に積んでから Blob にするので、上限が無いと
 * 相手が延々と送りつけるだけでこちら側のタブを落とせる。相手は P2P で
 * 直接つながっている「顧客」だが、通話相手を無条件に信頼する理由は無い。
 */
const MAX_INCOMING_FILE = 64 * 1024 * 1024

// heartbeat がサーバーエラーを返し続けるときに諦める回数。
// 再試行し続けると、原因が「応答が大きすぎる」場合に永久に直らない。
const MAX_CONSECUTIVE_POLL_ERRORS = 3

/**
 * 相手が付けたファイル名を表示・保存に使える形に均す。
 *
 * 名前は完全に相手の言いなりなので、制御文字と Unicode の書字方向反転
 * （U+202A-202E / U+2066-2069）を落とす。これを残すと `見積書<RLO>fdp.exe` が
 * 画面上は `見積書exe.pdf` に見え、実行ファイルを PDF と誤認させられる。
 * パス区切りは <a download> 側が処理するので、ここでは触らない。
 */
function safeFileName(raw) {
  const name = String(raw ?? 'file')
    // eslint-disable-next-line no-control-regex
    .replace(/[\u0000-\u001f\u007f-\u009f\u202a-\u202e\u2066-\u2069]/g, '')
    .trim()

  return (name || 'file').slice(0, 120)
}

export class CallSession {
  constructor({ projectId, callId, selfId, handlers = {} }) {
    this.projectId = projectId
    this.callId = callId
    this.selfId = selfId
    this.handlers = handlers

    this.peers = new Map()
    this.iceConfig = { iceServers: [], iceTransportPolicy: 'all' }

    this.localStream = null
    this.rawCameraTrack = null
    this.outboundVideoTrack = null
    this.screenStream = null

    this.lastSignalId = 0
    this.pollTimer = null
    this.pollIntervalMs = 1000
    this.flushTimer = null
    this.outbox = []

    this.running = false
    this.polling = false
    this.sendingFile = false
    this.fileQueue = []
  }

  // ---------------------------------------------------------------- 起動

  async start(localStream) {
    this.localStream = localStream
    this.rawCameraTrack = localStream?.getVideoTracks()[0] || null
    this.outboundVideoTrack = this.rawCameraTrack

    const { data } = await api.get('/calls/ice')
    this.iceConfig = {
      iceServers: data.ice_servers || [],
      iceTransportPolicy: data.ice_transport_policy || 'all',
    }
    this.hasTurn = !!data.has_turn

    const joined = await api.post(this.url('join'))
    this.applyRoomState(joined.data)

    this.running = true
    this.scheduleTick(0)
  }

  url(suffix = '') {
    const base = `/projects/${this.projectId}/calls/${this.callId}`
    return suffix ? `${base}/${suffix}` : base
  }

  // ------------------------------------------------------- ポーリング本体

  scheduleTick(delay = this.pollIntervalMs) {
    if (!this.running) return
    clearTimeout(this.pollTimer)
    this.pollTimer = setTimeout(() => this.tick(), delay)
  }

  async tick() {
    if (!this.running || this.polling) return
    this.polling = true

    try {
      const { data } = await api.post(this.url('heartbeat'), { after: this.lastSignalId })
      this.applyRoomState(data)
      this.pollErrors = 0
    } catch (e) {
      // 一時的な通信断でセッションを畳まない。相手側から見ると last_seen_at が
      // 古くなるだけで、復帰すれば名簿に戻る。
      if (e.response?.status === 403 || e.response?.status === 404) {
        this.handlers.onEnded?.('removed')
        this.running = false
        return
      }

      /*
       * サーバーエラーが続くときは諦める。
       *
       * 同じ `after` で投げ直すので、原因が「返そうとしている応答が大きすぎる」
       * 類だと永久に直らないまま毎秒叩き続けることになる。止めて知らせた方が
       * 相手にもサーバーにも優しい。
       */
      this.pollErrors = (this.pollErrors || 0) + 1
      if ((e.response?.status ?? 0) >= 500 && this.pollErrors >= MAX_CONSECUTIVE_POLL_ERRORS) {
        this.running = false
        this.handlers.onWarning?.(e, 'poll-failed')
        return
      }

      this.handlers.onWarning?.(e)
    } finally {
      this.polling = false
      this.scheduleTick()
    }
  }

  applyRoomState(data) {
    if (typeof data.poll_interval_ms === 'number') {
      this.pollIntervalMs = data.poll_interval_ms
    }
    if (typeof data.last_signal_id === 'number') {
      this.lastSignalId = Math.max(this.lastSignalId, data.last_signal_id)
    }

    if (data.call && data.call.status !== 'live') {
      this.handlers.onEnded?.(data.call.status)
      this.running = false
      return
    }

    this.syncRoster(data.participants || [])

    for (const signal of data.signals || []) {
      this.enqueueSignal(signal)
    }
  }

  /**
   * 名簿に合わせて接続を張ったり畳んだりする。
   * 「今つながっているべき相手」の唯一の情報源がこれ。
   */
  syncRoster(participants) {
    const live = new Set()

    for (const p of participants) {
      if (p.user_id === this.selfId || !p.is_live) continue
      live.add(p.user_id)
      this.ensurePeer(p.user_id, p.user)
    }

    for (const userId of [...this.peers.keys()]) {
      if (!live.has(userId)) this.dropPeer(userId)
    }

    this.emitPeers()
  }

  // ------------------------------------------------------------ ピア管理

  ensurePeer(userId, user) {
    let peer = this.peers.get(userId)

    if (!peer) {
      peer = {
        userId,
        user,
        pc: null,
        dc: null,
        // Perfect negotiation の役割。id の大小で決めるので双方が必ず逆の値になり、
        // 相談なしで一意に決まる。
        polite: this.selfId > userId,
        makingOffer: false,
        ignoreOffer: false,
        connectionState: 'new',
        cameraStream: null,
        screenStream: null,
        remoteIds: { camera: null, screen: null },
        pendingStreams: new Map(),
        remoteState: { mic: true, cam: true, screen: false },
        incomingFile: null,
        pendingCandidates: [],
        queue: Promise.resolve(),
      }
      this.peers.set(userId, peer)
    } else if (user && !peer.user) {
      peer.user = user
    }

    // 発信は id の小さい方だけが行う。両方が同時に offer を投げると（glare）
    // 復旧に往復が要り、1 秒ポーリングでは体感できるほど遅くなる。
    // 大きい方は相手の offer が届いた時点で接続を作る。
    if (!peer.pc && !peer.polite) this.createConnection(peer)

    return peer
  }

  createConnection(peer) {
    const pc = new RTCPeerConnection(this.iceConfig)
    peer.pc = pc

    if (this.localStream && this.localStream.getTracks().length) {
      for (const track of this.localStream.getTracks()) {
        // 映像はエフェクト適用後のトラックを送る（生のカメラではなく）。
        const outgoing = track.kind === 'video' ? this.outboundVideoTrack || track : track
        pc.addTrack(outgoing, this.localStream)
      }
    } else {
      // カメラ/マイクを拒否した人も、相手の映像は見られるようにする。
      pc.addTransceiver('audio', { direction: 'recvonly' })
      pc.addTransceiver('video', { direction: 'recvonly' })
    }

    // 画面共有中に後から入ってきた相手にも、その場で画面を流す。
    if (this.screenStream) {
      for (const track of this.screenStream.getVideoTracks()) {
        pc.addTrack(track, this.screenStream)
      }
    }

    if (!peer.polite) {
      // データチャネルは片側だけが作る。両側で作ると 2 本張られる。
      this.setupDataChannel(peer, pc.createDataChannel('parlour', { ordered: true }))
    }
    pc.ondatachannel = (e) => this.setupDataChannel(peer, e.channel)

    pc.onicecandidate = (e) => {
      if (e.candidate) this.queueSignal(peer.userId, 'candidate', e.candidate.toJSON())
    }

    pc.ontrack = (e) => {
      const stream = e.streams[0]
      if (!stream) return
      peer.pendingStreams.set(stream.id, stream)
      this.resolveStreams(peer)
      this.emitPeers()
    }

    pc.onnegotiationneeded = async () => {
      try {
        peer.makingOffer = true
        await pc.setLocalDescription()
        this.queueSignal(peer.userId, 'offer', pc.localDescription.toJSON())
      } catch (e) {
        this.handlers.onWarning?.(e)
      } finally {
        peer.makingOffer = false
      }
    }

    pc.onconnectionstatechange = () => {
      peer.connectionState = pc.connectionState
      if (pc.connectionState === 'failed') {
        // ICE のやり直し。TURN が無い構成では、経路が変わると復帰できることがある。
        try { pc.restartIce() } catch { /* 未対応ブラウザは次の再入室で拾う */ }
      }
      this.emitPeers()
    }

    return pc
  }

  dropPeer(userId) {
    const peer = this.peers.get(userId)
    if (!peer) return
    try { peer.dc?.close() } catch { /* 既に閉じている */ }
    try { peer.pc?.close() } catch { /* 既に閉じている */ }
    this.peers.delete(userId)
  }

  // ------------------------------------------------------------ シグナル

  queueSignal(toUserId, type, payload) {
    this.outbox.push({ to_user_id: toUserId, type, payload })
    if (this.flushTimer) return
    this.flushTimer = setTimeout(() => this.flushSignals(), SIGNAL_FLUSH_MS)
  }

  async flushSignals() {
    this.flushTimer = null
    if (!this.outbox.length || !this.running) return

    const batch = this.outbox.splice(0, 50)
    try {
      await api.post(this.url('signals'), { signals: batch })
    } catch (e) {
      this.handlers.onWarning?.(e)
    }

    if (this.outbox.length) {
      this.flushTimer = setTimeout(() => this.flushSignals(), SIGNAL_FLUSH_MS)
    }
  }

  /**
   * シグナルは相手ごとに 1 本の列に並べて順番に処理する。
   *
   * 1 秒間隔のポーリングでは offer と ICE 候補が同じ応答にまとめて入ってくる。
   * 並行に処理すると candidate が setRemoteDescription より先に走って
   * 「remote description が無い」で落ちるので、相手ごとに直列化する。
   * 相手が違えば並行のままなので、1 人が詰まっても他の接続は止まらない。
   */
  enqueueSignal(signal) {
    const peer = this.ensurePeer(signal.from_user_id)
    peer.queue = (peer.queue || Promise.resolve())
      .then(() => this.handleSignal(peer, signal))
      .catch((e) => this.handlers.onWarning?.(e))
  }

  /**
   * Perfect negotiation（W3C が示している衝突処理）そのまま。
   * 双方が同時に offer を出しても、polite 側が自分の offer を引っ込めることで
   * 必ず片方向に収束する。画面共有の開始/停止で再交渉が走るので、初回だけでなく
   * 通話中ずっとこの経路を通る。
   */
  async handleSignal(peer, signal) {
    if (!peer.pc) this.createConnection(peer)
    const pc = peer.pc

    if (signal.type === 'candidate') {
      // remote description が入る前の候補は捨てずに取っておく。捨てると
      // その経路が候補から漏れて、接続に時間がかかったり失敗したりする。
      if (!pc.remoteDescription) {
        peer.pendingCandidates.push(signal.payload)
        return
      }
      await this.addCandidate(peer, signal.payload)
      return
    }

    if (signal.type !== 'offer' && signal.type !== 'answer') return

    const description = signal.payload
    const collision =
      description.type === 'offer' && (peer.makingOffer || pc.signalingState !== 'stable')

    peer.ignoreOffer = !peer.polite && collision
    if (peer.ignoreOffer) return

    await pc.setRemoteDescription(description)

    // 溜めておいた候補をここで流し込む。
    const buffered = peer.pendingCandidates.splice(0)
    for (const candidate of buffered) {
      await this.addCandidate(peer, candidate)
    }

    if (description.type === 'offer') {
      await pc.setLocalDescription()
      this.queueSignal(peer.userId, 'answer', pc.localDescription.toJSON())
    }
  }

  async addCandidate(peer, candidate) {
    try {
      await peer.pc.addIceCandidate(candidate)
    } catch (e) {
      // 無視した offer に対応する候補は当然入らない。それ以外は上に投げる。
      if (!peer.ignoreOffer) throw e
    }
  }

  // -------------------------------------------------- ストリームの振り分け

  /**
   * 届いた映像がカメラなのか画面共有なのかを決める。
   *
   * SDP からは判別できないので、送り手が自分の MediaStream の id をデータチャネルで
   * 知らせ、受け手はそれと ontrack の stream.id を突き合わせる。データチャネルが
   * まだ開いていない場合に備えて、届いた順（最初がカメラ）で暫定的に割り当て、
   * 通知が来たら正しい割り当てに直す。
   */
  resolveStreams(peer) {
    // 生きているトラックが 1 本も無いストリームは残しておいても表示できない。
    for (const [id, stream] of peer.pendingStreams) {
      if (!stream.getTracks().some((t) => t.readyState === 'live')) {
        peer.pendingStreams.delete(id)
      }
    }

    const { camera, screen } = peer.remoteIds

    // 通知が届いていれば、それが唯一の正解。届いた id をそのまま引き当てる。
    // 画面共有をやめた相手は screen が null で送り直してくるので、ここで消える。
    if (camera || screen) {
      peer.cameraStream = camera ? peer.pendingStreams.get(camera) || null : null
      peer.screenStream = screen ? peer.pendingStreams.get(screen) || null : null
      return
    }

    // データチャネルがまだ開いていない場合の暫定表示。届いた順に
    // 1 本目をカメラ、2 本目を画面とみなす。通知が来た時点で上の分岐が上書きする。
    const streams = [...peer.pendingStreams.values()]
    peer.cameraStream = streams[0] || null
    peer.screenStream = streams[1] || null
  }

  // -------------------------------------------------------- データチャネル

  setupDataChannel(peer, channel) {
    peer.dc = channel
    channel.binaryType = 'arraybuffer'
    channel.bufferedAmountLowThreshold = DC_BUFFER_LOW

    channel.onopen = () => {
      this.announceStreams(peer)
      this.emitPeers()
    }
    channel.onclose = () => this.emitPeers()
    channel.onmessage = (e) => this.onDataMessage(peer, e.data)
  }

  announceStreams(peer) {
    this.sendData(peer, {
      kind: 'streams',
      camera: this.localStream?.id || null,
      screen: this.screenStream?.id || null,
      mic: this.micEnabled(),
      cam: this.camEnabled(),
    })
  }

  announceAll() {
    for (const peer of this.peers.values()) this.announceStreams(peer)
  }

  sendData(peer, object) {
    if (peer.dc?.readyState !== 'open') return
    try {
      peer.dc.send(JSON.stringify(object))
    } catch (e) {
      this.handlers.onWarning?.(e)
    }
  }

  broadcast(object) {
    for (const peer of this.peers.values()) this.sendData(peer, object)
  }

  onDataMessage(peer, raw) {
    // バイナリはファイルの中身。直前の file-meta が宛先を決めている。
    if (typeof raw !== 'string') {
      const incoming = peer.incomingFile
      if (!incoming) return

      /*
       * 申告サイズと絶対上限を **別々に** 見る。
       * Math.min(申告, 上限) をしきい値にすると、size=1 と偽って本物の転送を
       * 殺せてしまうため。どちらを超えても受信を捨てて打ち切る。
       */
      const next = incoming.received + raw.byteLength
      if (next > incoming.size || next > MAX_INCOMING_FILE) {
        incoming.chunks.length = 0
        peer.incomingFile = null
        this.handlers.onWarning?.(new Error('file-too-large'), 'file-too-large')
        return
      }

      incoming.chunks.push(raw)
      incoming.received += raw.byteLength
      this.handlers.onFileProgress?.({
        userId: peer.userId,
        name: incoming.name,
        received: incoming.received,
        size: incoming.size,
        direction: 'in',
      })
      return
    }

    let message
    try {
      message = JSON.parse(raw)
    } catch {
      return
    }

    switch (message.kind) {
      case 'streams':
        peer.remoteIds = { camera: message.camera ?? null, screen: message.screen ?? null }
        peer.remoteState = {
          ...peer.remoteState,
          mic: message.mic !== false,
          cam: message.cam !== false,
          screen: !!message.screen,
        }
        this.resolveStreams(peer)
        this.emitPeers()
        break

      case 'chat':
        this.handlers.onChat?.({
          userId: peer.userId,
          name: peer.user?.name,
          text: String(message.text ?? '').slice(0, 4000),
          at: new Date().toISOString(),
          mine: false,
        })
        break

      case 'file-meta': {
        const size = Number(message.size)
        if (!Number.isFinite(size) || size <= 0 || size > MAX_INCOMING_FILE) {
          // 受け取る前に断る。ここで弾けば 1 バイトも積まない。
          peer.incomingFile = null
          this.handlers.onWarning?.(new Error('file-too-large'), 'file-too-large')
          break
        }
        peer.incomingFile = {
          name: safeFileName(message.name),
          size,
          mime: String(message.mime ?? 'application/octet-stream').slice(0, 128),
          chunks: [],
          received: 0,
        }
        break
      }

      case 'file-end': {
        const incoming = peer.incomingFile
        peer.incomingFile = null
        if (!incoming) break
        const blob = new Blob(incoming.chunks, { type: incoming.mime })
        this.handlers.onFile?.({
          userId: peer.userId,
          name: peer.user?.name,
          fileName: incoming.name,
          size: blob.size,
          url: URL.createObjectURL(blob),
          at: new Date().toISOString(),
          mine: false,
        })
        break
      }
    }
  }

  sendChat(text) {
    const trimmed = String(text ?? '').trim()
    if (!trimmed) return
    this.broadcast({ kind: 'chat', text: trimmed.slice(0, 4000) })
    this.handlers.onChat?.({
      userId: this.selfId,
      text: trimmed,
      at: new Date().toISOString(),
      mine: true,
    })
  }

  /**
   * ファイルは相手へ直接流す。サーバーには一切残らない。
   * 同時に複数流すとチャネルが混ざるので、1 本ずつ順番に送る。
   */
  async sendFile(file) {
    this.fileQueue.push(file)
    if (this.sendingFile) return
    this.sendingFile = true

    try {
      while (this.fileQueue.length) {
        const next = this.fileQueue.shift()
        await this.sendFileNow(next)
      }
    } finally {
      this.sendingFile = false
    }
  }

  async sendFileNow(file) {
    const targets = [...this.peers.values()].filter((p) => p.dc?.readyState === 'open')
    if (!targets.length) throw new Error('no-peer')

    for (const peer of targets) {
      this.sendData(peer, { kind: 'file-meta', name: file.name, size: file.size, mime: file.type })
    }

    const buffer = await file.arrayBuffer()
    let sent = 0

    while (sent < buffer.byteLength) {
      const chunk = buffer.slice(sent, sent + FILE_CHUNK_SIZE)

      for (const peer of targets) {
        if (peer.dc?.readyState !== 'open') continue
        await this.waitForDrain(peer)
        peer.dc.send(chunk)
      }

      sent += chunk.byteLength
      this.handlers.onFileProgress?.({
        userId: this.selfId,
        name: file.name,
        received: sent,
        size: buffer.byteLength,
        direction: 'out',
      })
    }

    for (const peer of targets) {
      this.sendData(peer, { kind: 'file-end' })
    }

    this.handlers.onFile?.({
      userId: this.selfId,
      fileName: file.name,
      size: file.size,
      url: URL.createObjectURL(file),
      at: new Date().toISOString(),
      mine: true,
    })
  }

  waitForDrain(peer) {
    if (peer.dc.bufferedAmount < DC_BUFFER_HIGH) return Promise.resolve()
    return new Promise((resolve) => {
      const done = () => {
        peer.dc.removeEventListener('bufferedamountlow', done)
        resolve()
      }
      peer.dc.addEventListener('bufferedamountlow', done)
    })
  }

  // ---------------------------------------------------------- メディア操作

  micEnabled() {
    return this.localStream?.getAudioTracks()[0]?.enabled ?? false
  }

  camEnabled() {
    return this.outboundVideoTrack?.enabled ?? false
  }

  toggleMic(on) {
    const track = this.localStream?.getAudioTracks()[0]
    if (!track) return false
    track.enabled = on ?? !track.enabled
    this.announceAll()
    return track.enabled
  }

  toggleCam(on) {
    const next = on ?? !this.camEnabled()
    // 生のカメラと送信中のトラックの両方を止める。エフェクト中は別物なので、
    // 片方だけだと canvas が最後のフレームを送り続けてしまう。
    if (this.rawCameraTrack) this.rawCameraTrack.enabled = next
    if (this.outboundVideoTrack) this.outboundVideoTrack.enabled = next
    this.announceAll()
    return next
  }

  /**
   * 送信中の映像トラックを差し替える。エフェクトの入切とカメラ切替の両方で使う。
   * replaceTrack は再交渉を伴わないので、相手側に途切れが出ない。
   */
  async replaceVideoTrack(track) {
    this.outboundVideoTrack = track

    for (const peer of this.peers.values()) {
      if (!peer.pc) continue
      const sender = peer.pc
        .getSenders()
        .find((s) => s.track && s.track.kind === 'video' && !this.isScreenTrack(s.track))
      if (sender) await sender.replaceTrack(track)
    }
  }

  isScreenTrack(track) {
    return !!this.screenStream && this.screenStream.getTracks().includes(track)
  }

  /**
   * 画面共有。カメラを置き換えるのではなく 2 本目の映像として足すので、
   * 相手は資料と話し手の顔を同時に見られる。
   */
  async startScreenShare() {
    const stream = await navigator.mediaDevices.getDisplayMedia({
      video: { frameRate: 15 },
      audio: false,
    })

    this.screenStream = stream

    // ブラウザ側の「共有を停止」バーで止められたときも状態を合わせる。
    stream.getVideoTracks()[0].addEventListener('ended', () => this.stopScreenShare())

    for (const peer of this.peers.values()) {
      if (!peer.pc) continue
      for (const track of stream.getVideoTracks()) {
        peer.pc.addTrack(track, stream)
      }
    }

    this.announceAll()
    return stream
  }

  async stopScreenShare() {
    const stream = this.screenStream
    if (!stream) return
    this.screenStream = null

    for (const peer of this.peers.values()) {
      if (!peer.pc) continue
      for (const sender of peer.pc.getSenders()) {
        if (sender.track && stream.getTracks().includes(sender.track)) {
          peer.pc.removeTrack(sender)
        }
      }
    }

    stream.getTracks().forEach((t) => t.stop())
    this.announceAll()
    this.handlers.onScreenShareEnded?.()
  }

  // ------------------------------------------------------------------ 終了

  emitPeers() {
    this.handlers.onPeers?.(
      [...this.peers.values()].map((p) => ({
        userId: p.userId,
        user: p.user,
        connectionState: p.connectionState,
        cameraStream: p.cameraStream,
        screenStream: p.screenStream,
        chatReady: p.dc?.readyState === 'open',
        mic: p.remoteState.mic,
        cam: p.remoteState.cam,
        screen: !!p.screenStream,
      }))
    )
  }

  async leave({ end = false } = {}) {
    this.running = false
    clearTimeout(this.pollTimer)
    clearTimeout(this.flushTimer)

    for (const userId of [...this.peers.keys()]) this.dropPeer(userId)

    if (this.screenStream) {
      this.screenStream.getTracks().forEach((t) => t.stop())
      this.screenStream = null
    }

    try {
      await api.post(this.url(end ? 'end' : 'leave'))
    } catch {
      // 退出の通知が届かなくても、相手側は last_seen_at が古くなった時点で気づく。
    }
  }
}
