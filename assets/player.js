/*
 * HSK Nghe — trình phát.
 *
 * Mỗi bài là MỘT file MP3 do server ghép sẵn theo cài đặt (phát nền ổn định khi khoá màn hình),
 * kèm cues [ms, unit, loại] để biết đang đọc từ/câu nào. Không dùng setTimeout cho khoảng lặng:
 * khoảng lặng nằm sẵn trong file.
 */
(function () {
  'use strict';

  const ICON = {
    play: 'M8 5.14v13.72a1 1 0 0 0 1.5.86l11-6.86a1 1 0 0 0 0-1.72l-11-6.86A1 1 0 0 0 8 5.14z',
    pause: 'M7 5h3.5v14H7zM13.5 5H17v14h-3.5z',
    prev: 'M6 6h2v12H6zm3.5 6 8.5 6V6z',
    next: 'M16 6h2v12h-2zM6 18l8.5-6L6 6z',
    replay: 'M12 5V2L7 6l5 4V7a5 5 0 1 1-5 5H5a7 7 0 1 0 7-7z',
    tune: 'M3 17v2h6v-2H3zM3 5v2h10V5H3zm10 16v-2h8v-2h-8v-2h-2v6h2zM7 9v2H3v2h4v2h2V9H7zm14 4v-2H11v2h10zm-6-4h2V7h4V5h-4V3h-2v6z',
    list: 'M4 6h16v2H4zm0 5h16v2H4zm0 5h10v2H4z',
    loop: 'M7 7h10v3l4-4-4-4v3H5v6h2V7zm10 10H7v-3l-4 4 4 4v-3h12v-6h-2v4z',
  };
  const SPEEDS = [0.75, 0.9, 1, 1.15, 1.3];
  const STORE = 'hskn:v1';
  const LISTEN_KEYS = ['word_repeat', 'meaning_lang', 'sentence_repeat', 'sentence_meaning', 'slow_repeat', 'gap_ms', 'end_gap_ms'];

  const svg = (name) => `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="${ICON[name]}"/></svg>`;
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmt = (sec) => {
    sec = Math.max(0, Math.floor(sec || 0));
    return `${Math.floor(sec / 60)}:${String(sec % 60).padStart(2, '0')}`;
  };

  // localStorage có thể bị chặn (chế độ riêng tư) → luôn bọc try/catch, mất thì dùng mặc định
  const storage = {
    read() { try { return JSON.parse(localStorage.getItem(STORE)) || {}; } catch (e) { return {}; } },
    write(v) { try { localStorage.setItem(STORE, JSON.stringify(v)); } catch (e) { /* bỏ qua */ } },
  };

  // Vài frame MP3 im lặng (24 kHz mono 48 kbps) để "mở khoá" audio trên iOS ngay trong cú chạm của người dùng.
  function silentUrl() {
    const frame = new Uint8Array(144);
    frame.set([0xff, 0xf3, 0x64, 0xc4]);
    const all = new Uint8Array(144 * 8);
    for (let i = 0; i < 8; i++) all.set(frame, i * 144);
    return URL.createObjectURL(new Blob([all], { type: 'audio/mpeg' }));
  }

  /*
   * Đọc bằng giọng có sẵn của máy (Web Speech API) khi bài chưa có file audio — không cần API nào.
   * Giả lập giao diện của <audio> (play, pause, currentTime, duration, playbackRate, các sự kiện
   * play / pause / timeupdate / ended / loadedmetadata) nên trình phát dùng lại nguyên logic cue, tua, chuyển bài.
   * Thời gian là ước lượng theo số chữ. Trình duyệt ngừng đọc khi khoá màn hình → giữ màn hình sáng (Wake Lock).
   */
  const LANG = { zh: 'zh-CN', vi: 'vi-VN', en: 'en-US' };
  const speechOk = 'speechSynthesis' in window && 'SpeechSynthesisUtterance' in window;

  function pickVoices() {
    const all = speechOk ? window.speechSynthesis.getVoices() : [];
    const good = /natural|online|neural|google|premium|enhanced|siri/i;
    const of = (pre) => all.filter((v) => v.lang.replace('_', '-').toLowerCase().startsWith(pre))
      .sort((a, b) => (good.test(b.name) ? 1 : 0) - (good.test(a.name) ? 1 : 0));
    const zh = of('zh-cn').length ? of('zh-cn') : of('zh');
    const male = zh.find((v) => /male|yunxi|yunyang|kangkang|yunjian|男/i.test(v.name) && !/female/i.test(v.name));
    return { any: all.length > 0, zh: zh[0], zh_m: male || zh[1] || zh[0], vi: of('vi')[0], en: (of('en-us')[0] || of('en')[0]) };
  }

  class SpeechAudio extends EventTarget {
    constructor(script, src) {
      super();
      this.src = src;
      this.script = script;
      this.paused = true;
      this.i = 0;
      this.gen = 0;
      this._rate = 1;
      this._t = 0;
      this.voices = pickVoices();
      if (speechOk) window.speechSynthesis.addEventListener('voiceschanged', () => { this.voices = pickVoices(); });
      // dòng thời gian ước lượng: chữ Hán ~ 0,26 s/chữ, Việt/Anh ~ 0,065 s/ký tự
      let t = 0;
      this.times = [];
      this.cues = [];
      script.forEach((s) => {
        this.times.push(t);
        if (s.p != null) { t += s.p; return; }
        this.cues.push([Math.round(t), s.u, s.k]);
        t += 300 + (s.l === 'zh' ? 260 : 65) * [...s.t].length / (s.r || 1);
      });
      this.total = t;
      this.duration = t / 1000;
      setTimeout(() => this.emit('loadedmetadata'), 0);
    }

    emit(name) { this.dispatchEvent(new Event(name)); }
    load() {}
    get playbackRate() { return this._rate; }
    set playbackRate(r) { this._rate = r; this.emit('ratechange'); }
    get currentTime() { return this._t / 1000; }
    set currentTime(sec) {
      const ms = Math.max(0, sec * 1000) + 2;   // cue đã làm tròn tới ms → cho lệch một chút để không rơi vào khoảng lặng phía trước
      let i = 0;
      while (i + 1 < this.times.length && this.times[i + 1] <= ms) i++;
      this.i = i;
      this._t = this.times[i] || 0;
      if (!this.paused) this.run();
      this.emit('timeupdate');
    }

    play() {
      if (!speechOk) return Promise.reject(new Error('Trình duyệt này không có giọng đọc.'));
      if (this.paused) {
        if (this.i >= this.script.length) { this.i = 0; this._t = 0; }
        this.paused = false;
        this.emit('play');
        this.wake(true);
        this.run();
      }
      return Promise.resolve();
    }

    pause() {
      if (this.paused) return;
      this.paused = true;
      this.stop();
      this.wake(false);
      this.emit('pause');
    }

    dispose() {
      this.paused = true;
      this.stop();
      this.wake(false);
    }

    stop() {
      this.gen++;
      clearTimeout(this.timer);
      clearInterval(this.tick);
      if (speechOk) window.speechSynthesis.cancel();
    }

    async wake(on) {
      try {
        if (on && 'wakeLock' in navigator && !this.lock) this.lock = await navigator.wakeLock.request('screen');
        if (!on && this.lock) { await this.lock.release(); this.lock = null; }
      } catch (e) { /* trình duyệt không hỗ trợ / chưa được phép */ }
    }

    run() {
      this.stop();
      const gen = this.gen;
      const next = () => { if (gen === this.gen) { this.i++; step(); } };
      const step = () => {
        if (gen !== this.gen) return;
        if (this.i >= this.script.length) {
          this.paused = true;
          this.wake(false);
          clearInterval(this.tick);
          this._t = this.total;
          this.emit('timeupdate');
          this.emit('pause');
          this.emit('ended');
          return;
        }
        const s = this.script[this.i];
        this._t = this.times[this.i];
        this.started = performance.now();
        this.emit('timeupdate');
        if (s.p != null) { this.timer = setTimeout(next, s.p / this._rate); return; }
        const voice = s.l === 'zh' ? (s.v === 'm' ? this.voices.zh_m : this.voices.zh) : this.voices[s.l];
        if (this.voices.any && !voice) { this.timer = setTimeout(next, 200); return; }   // máy không có giọng ngôn ngữ này
        const u = new SpeechSynthesisUtterance(s.t);
        u.lang = LANG[s.l];
        if (voice) u.voice = voice;
        u.rate = Math.max(0.5, Math.min(2, (s.r || 1) * this._rate));
        u.onend = next;
        u.onerror = next;
        window.speechSynthesis.speak(u);
        // Chrome đôi khi không bắn onend → canh giờ: quá 3 lần thời lượng ước tính thì bỏ qua câu này
        const idx = this.i;
        const est = (this.times[idx + 1] ?? this.total) - this.times[idx];
        this.timer = setTimeout(() => {
          if (gen !== this.gen || this.i !== idx) return;
          window.speechSynthesis.cancel();
          setTimeout(() => { if (gen === this.gen && this.i === idx) next(); }, 300);
        }, est * 3 / this._rate + 4000);
      };
      // nội suy currentTime trong lúc đang đọc để thanh tiến độ chạy mượt
      this.tick = setInterval(() => {
        if (this.paused || this.started == null) return;
        const end = this.times[this.i + 1] ?? this.total;
        this._t = Math.min(end - 1, this.times[this.i] + (performance.now() - this.started) * this._rate);
        this.emit('timeupdate');
      }, 300);
      step();
    }
  }

  function Player(root) {
    const cfg = JSON.parse(root.dataset.config);
    const saved = storage.read();
    const st = {
      tracks: [],
      topics: {},
      track: null,
      pl: null,
      order: [],          // [{u, t}] unit theo thứ tự thời gian
      cueIdx: -1,
      unit: -1,
      loading: false,
      unlocked: false,
      prefetch: {},
      over: cfg.allowOverride ? (saved.over || {}) : {},
      speed: saved.speed || 1,
      py: saved.py !== false,
      mean: saved.mean !== false,
      auto: saved.auto ?? cfg.defaults.auto_next,
      pos: saved.pos || {},
      lastSave: 0,
      tab: saved.tab || 'topics',
      forceSpeech: {},     // bài có file MP3 lỗi → đọc bằng giọng máy
      speechWarm: false,
    };
    // `audio` là phần tử <audio> thật (bài có đủ clip) hoặc SpeechAudio (đọc bằng giọng của máy)
    const realAudio = new Audio();
    realAudio.preload = 'auto';
    let audio = realAudio;
    root.hskn = { get audio() { return audio; }, st };   // để debug trong console
    let pendingSeek = null;
    let pendingPlay = false;

    const settings = () => Object.assign({}, cfg.defaults, st.over);
    const persist = () => storage.write({
      track: st.track && st.track.id, over: st.over, speed: st.speed, py: st.py, mean: st.mean,
      auto: st.auto, pos: st.pos, tab: st.tab,
    });

    // ------------------------------------------------------------ skeleton
    root.innerHTML = `
      <section class="hk-player" aria-label="Trình phát">
        <div class="hk-head">
          <div class="hk-head-text"><div class="hk-title">Chọn bài nghe</div><div class="hk-sub"></div></div>
          <button class="hk-icon" data-act="settings" title="Cài đặt nghe" aria-label="Cài đặt nghe">${svg('tune')}</button>
          <button class="hk-icon" data-act="library" title="Danh sách bài" aria-label="Danh sách bài">${svg('list')}</button>
        </div>
        <div class="hk-stage" aria-live="polite">
          <div class="hk-parent" hidden></div>
          <div class="hk-zh"></div>
          <div class="hk-py"></div>
          <div class="hk-mean"></div>
          <div class="hk-tags"></div>
        </div>
        <div class="hk-seek">
          <span class="hk-cur">0:00</span>
          <input type="range" min="0" max="1000" value="0" step="1" aria-label="Vị trí">
          <span class="hk-dur">0:00</span>
        </div>
        <div class="hk-count"></div>
        <div class="hk-controls">
          <button class="hk-ctl" data-act="prev" aria-label="Mục trước">${svg('prev')}</button>
          <button class="hk-ctl" data-act="replay" aria-label="Nghe lại mục này">${svg('replay')}</button>
          <button class="hk-play" data-act="toggle" aria-label="Phát">${svg('play')}</button>
          <button class="hk-ctl hk-speed" data-act="speed" aria-label="Tốc độ" style="font-weight:650;font-size:15px">1×</button>
          <button class="hk-ctl" data-act="next" aria-label="Mục sau">${svg('next')}</button>
        </div>
        <div class="hk-bar">
          <button class="hk-chip" data-act="track-prev" aria-label="Bài trước" title="Bài trước">‹</button>
          <button class="hk-chip" data-act="py" aria-pressed="true">Pinyin</button>
          <button class="hk-chip" data-act="mean" aria-pressed="true">Nghĩa</button>
          <button class="hk-chip" data-act="auto" aria-pressed="true">${svg('loop')} Liên tục</button>
          <button class="hk-chip" data-act="track-next" aria-label="Bài sau" title="Bài sau">›</button>
        </div>
        <div class="hk-msg" role="status"></div>
      </section>
      <section class="hk-lib" aria-label="Danh sách bài nghe"></section>
      <dialog class="hk-sheet"></dialog>`;

    const $ = (s) => root.querySelector(s);
    const ui = {
      title: $('.hk-title'), sub: $('.hk-sub'), stage: $('.hk-stage'), parent: $('.hk-parent'), zh: $('.hk-zh'),
      py: $('.hk-py'), mean: $('.hk-mean'), tags: $('.hk-tags'), cur: $('.hk-cur'), dur: $('.hk-dur'),
      range: $('.hk-seek input'), count: $('.hk-count'), play: $('.hk-play'), speed: $('.hk-speed'),
      msg: $('.hk-msg'), lib: $('.hk-lib'), sheet: $('.hk-sheet'),
    };

    // ------------------------------------------------------------ data
    // Bài nghe là API công khai: không gửi nonce (nonce cũ + cookie đăng nhập mới ở tab khác → WP trả 403).
    // Nonce chỉ dùng cho các endpoint me/* về sau.
    async function api(path) {
      const res = await fetch(cfg.rest + path, { credentials: 'omit' });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.message || `Lỗi ${res.status}`);
      return data;
    }

    function playlistPath(id) {
      const s = settings();
      const q = new URLSearchParams();
      if (cfg.allowOverride) LISTEN_KEYS.forEach((k) => q.set(k, String(s[k])));
      return `tracks/${id}/playlist?${q}`;
    }

    function getPlaylist(id) {
      const path = playlistPath(id);
      if (!st.prefetch[path]) {
        st.prefetch[path] = api(path).catch((e) => { delete st.prefetch[path]; throw e; });
      }
      return st.prefetch[path];
    }

    // ------------------------------------------------------------ audio
    // Gọi ĐỒNG BỘ trong cú chạm trước khi chuyển bài (fetch xong mới play() thì iOS coi là tự phát và chặn).
    // Nút ▶ không cần: bản thân play() trong cú chạm đã mở khoá phần tử audio.
    function unlock() {
      warmSpeech();
      if (st.unlocked) return;
      st.unlocked = true;
      if (audio === realAudio) {
        realAudio.src = silentUrl();
        realAudio.play().catch(() => {});
      }
    }

    // iOS chỉ cho đọc giọng máy nếu lần đọc đầu tiên nằm trong cú chạm của người dùng
    function warmSpeech() {
      if (!speechOk || st.speechWarm) return;
      st.speechWarm = true;
      const u = new SpeechSynthesisUtterance(' ');
      u.volume = 0;
      window.speechSynthesis.speak(u);
    }

    // Chọn cách phát cho bài vừa tải: MP3 ghép sẵn nếu đủ clip, ngược lại đọc bằng giọng của máy.
    function useMedia(pl, id) {
      const speech = !pl.audio || pl.missing > 0 || st.forceSpeech[id];
      if (audio !== realAudio) audio.dispose();
      if (!speech) {
        audio = realAudio;
        return false;
      }
      realAudio.pause();
      const sp = new SpeechAudio(pl.speech, 'speech:' + id + ':' + Date.now());
      bindMedia(sp);
      audio = sp;
      pl.audio = { src: sp.src, duration_ms: sp.total };
      pl.cues = sp.cues;
      return true;
    }

    async function load(id, opts = {}) {
      const track = st.tracks.find((t) => t.id === id);
      if (!track || st.loading) return;
      st.loading = true;
      ui.play.classList.add('is-busy');
      say('');
      try {
        // bản sao: useMedia() sửa audio/cues của playlist, không được làm hỏng bản trong cache prefetch
        const pl = Object.assign({}, await getPlaylist(id));
        const speech = useMedia(pl, id);
        st.pl = pl;
        st.track = Object.assign({}, track, pl.track);
        st.order = [];
        const seen = new Set();
        pl.cues.forEach(([t, u]) => { if (!seen.has(u)) { seen.add(u); st.order.push({ u, t }); } });
        st.cueIdx = -1;
        st.unit = -1;
        let at = opts.time ?? (opts.resume ? (st.pos[id] || 0) : 0);
        if (opts.unitId) {
          const u = pl.units.findIndex((x) => x.id === opts.unitId);
          const o = st.order.find((x) => x.u === u);
          if (o) at = o.t / 1000;
        }
        pendingSeek = at > 0 ? at : null;
        pendingPlay = !!opts.autoplay || !!st.wantPlay;
        st.wantPlay = false;
        if (!speech) {
          audio.src = pl.audio.src;
          audio.load();
        }
        say(speech ? (speechOk
          ? `Đang đọc bằng giọng của máy${pl.missing ? ` (${pl.missing} mục chưa có file giọng đọc)` : ''} — giữ màn hình sáng khi nghe.`
          : 'Bài này chưa có file giọng đọc và trình duyệt không hỗ trợ đọc bằng giọng máy.') : '');
        ui.title.textContent = st.track.title;
        ui.sub.textContent = st.track.subtitle;
        ui.dur.textContent = fmt(pl.audio.duration_ms / 1000);
        renderUnit(st.order.length ? st.order[0].u : -1, null);
        renderLibrary();
        updateBar();
        persist();
        try {
          const url = new URL(location.href);
          url.searchParams.set('track', id);
          history.replaceState(history.state, '', url);
        } catch (e) { /* bỏ qua */ }
      } catch (e) {
        say(e.message);
      } finally {
        st.loading = false;
        ui.play.classList.remove('is-busy');
      }
    }

    // Gắn sự kiện cho <audio> thật và cho mỗi SpeechAudio (cùng một bộ xử lý).
    // Sự kiện của nguồn không còn được dùng bị bỏ qua (target !== audio).
    function bindMedia(m) {
      const on = (name, fn) => m.addEventListener(name, (e) => { if (m === audio) fn(e); });
      on('loadedmetadata', () => {
        audio.playbackRate = st.speed;
        if (pendingSeek != null) {
          try { audio.currentTime = pendingSeek; } catch (e) { /* bỏ qua */ }
          pendingSeek = null;
        }
        if (pendingPlay) {
          pendingPlay = false;
          audio.play().catch(() => say('Bấm ▶ để phát.'));
        }
        updatePosition();
      });
      on('play', () => { ui.play.innerHTML = svg('pause'); ui.play.setAttribute('aria-label', 'Tạm dừng'); setState('playing'); });
      on('pause', () => { ui.play.innerHTML = svg('play'); ui.play.setAttribute('aria-label', 'Phát'); setState('paused'); savePos(true); });
      on('emptied', () => { ui.play.innerHTML = svg('play'); ui.play.setAttribute('aria-label', 'Phát'); });
      on('ratechange', updatePosition);
      on('error', () => {
        // file MP3 hỏng / bị xoá → đọc bằng giọng máy thay vì im lặng
        if (!st.pl || audio.src !== st.pl.audio.src || !st.track) return;
        if (st.pl.speech && st.pl.speech.length && !st.forceSpeech[st.track.id]) {
          st.forceSpeech[st.track.id] = true;
          load(st.track.id, { autoplay: true, time: audio.currentTime });
        } else {
          say('Không tải được file audio.');
        }
      });
      on('timeupdate', onTime);
      on('ended', () => {
        if (!isTrackSrc()) return;
        st.pos[st.track.id] = 0;
        persist();
        if (st.auto && st.track.next) load(st.track.next, { autoplay: true });
      });
    }
    bindMedia(realAudio);

    // false khi đang phát clip im lặng dùng để mở khoá, hoặc file của bài cũ chưa kịp đổi
    function isTrackSrc() {
      return !!(st.pl && st.track && !st.loading && audio.src === st.pl.audio.src);
    }

    function onTime() {
      if (!isTrackSrc()) return;
      const t = audio.currentTime;
      const d = audio.duration || st.pl.audio.duration_ms / 1000;
      ui.cur.textContent = fmt(t);
      if (!ui.range.matches(':active')) ui.range.value = d ? Math.round((t / d) * 1000) : 0;
      // cue hiện tại (tìm nhị phân)
      const cues = st.pl.cues;
      const ms = t * 1000;
      let lo = 0, hi = cues.length - 1, idx = -1;
      while (lo <= hi) {
        const mid = (lo + hi) >> 1;
        if (cues[mid][0] <= ms + 30) { idx = mid; lo = mid + 1; } else hi = mid - 1;
      }
      if (idx !== st.cueIdx && idx >= 0) {
        st.cueIdx = idx;
        renderUnit(cues[idx][1], cues[idx][2]);
      }
      savePos(false);
      // tải trước bài kế tiếp (server ghép sẵn) khi đã nghe 80%
      if (st.auto && st.track.next && d && t / d > 0.8) getPlaylist(st.track.next).catch(() => {});
    }

    function savePos(force) {
      if (!isTrackSrc()) return;
      const now = Date.now();
      if (!force && now - st.lastSave < 5000) return;
      st.lastSave = now;
      st.pos[st.track.id] = Math.floor(audio.currentTime);
      persist();
    }

    function seekTo(sec) {
      if (!st.pl) return;
      audio.currentTime = Math.max(0, Math.min(sec, (audio.duration || 0) - 0.05));
      onTime();
    }

    function orderIndex() {
      const ms = audio.currentTime * 1000 + 30;
      let i = -1;
      for (let k = 0; k < st.order.length; k++) { if (st.order[k].t <= ms) i = k; else break; }
      return i;
    }

    function unitStep(dir) {
      if (!st.order.length) return;
      const i = orderIndex();
      if (dir < 0) {
        const into = audio.currentTime - (st.order[Math.max(i, 0)].t / 1000);
        const j = into > 1.5 ? i : i - 1;
        if (j < 0 && st.track.prev && into <= 1.5) return load(st.track.prev, { autoplay: !audio.paused });
        seekTo(st.order[Math.max(j, 0)].t / 1000);
      } else if (i + 1 < st.order.length) {
        seekTo(st.order[i + 1].t / 1000);
      } else if (st.track.next) {
        load(st.track.next, { autoplay: !audio.paused });
      }
    }

    function replayUnit() {
      const i = orderIndex();
      if (i >= 0) seekTo(st.order[i].t / 1000);
      if (audio.paused) audio.play().catch(() => {});
    }

    // ------------------------------------------------------------ render
    function say(text) { ui.msg.textContent = text || ''; }

    function renderUnit(u, kind) {
      const pl = st.pl;
      const unit = pl && pl.units[u];
      if (!unit) {
        ui.zh.textContent = ''; ui.py.textContent = ''; ui.mean.textContent = ''; ui.tags.innerHTML = ''; ui.parent.hidden = true;
        return;
      }
      const lang = settings().meaning_lang === 'en' && unit.en ? 'en' : 'vi';
      if (u !== st.unit) {
        st.unit = u;
        ui.stage.dataset.kind = unit.kind;
        ui.zh.textContent = unit.zh;
        ui.py.textContent = unit.pinyin;
        const other = lang === 'en' ? unit.vi : unit.en;
        ui.mean.innerHTML = esc(unit[lang] || unit.vi) + (unit.kind === 'word' && other ? `<small>${esc(other)}</small>` : '');
        const parent = unit.parent != null ? pl.units[unit.parent] : null;
        ui.parent.hidden = !parent;
        if (parent) ui.parent.innerHTML = `<b>${esc(parent.zh)}</b> ${esc(parent.pinyin)}`;
        renderCount(u);
        updateMedia(unit);
      }
      ui.zh.classList.toggle('is-on', kind === 'word' || kind === 'zh' || kind === 'zh_slow');
      ui.mean.classList.toggle('is-on', kind === 'vi' || kind === 'en');
      const tags = [];
      if (unit.kind === 'word') tags.push(unit.is_term ? `<span class="hk-tag lv4">chuyên ngành</span>` : `<span class="hk-tag lv${unit.level}">HSK ${unit.level}</span>`);
      if (unit.speaker) tags.push(`<span class="hk-tag">Người ${esc(unit.speaker)}</span>`);
      if (unit.grammar) tags.push(`<span class="hk-tag">${esc(unit.grammar)}</span>`);
      if (kind === 'zh_slow') tags.push('<span class="hk-tag slow">chậm</span>');
      ui.tags.innerHTML = tags.join('');
    }

    function renderCount(u) {
      const units = st.pl.units;
      const isWords = units.some((x) => x.kind === 'word');
      const head = isWords ? 'word' : 'sentence';
      const list = units.map((x, i) => (x.kind === head ? i : -1)).filter((i) => i >= 0);
      const top = isWords && units[u].kind !== 'word' ? units[u].parent : u;
      const n = list.indexOf(top) + 1;
      ui.count.textContent = n ? `${isWords ? 'Từ' : 'Câu'} ${n}/${list.length}` : '';
    }

    function updateBar() {
      const b = (act) => root.querySelector(`[data-act="${act}"]`);
      b('py').setAttribute('aria-pressed', String(st.py));
      b('mean').setAttribute('aria-pressed', String(st.mean));
      b('auto').setAttribute('aria-pressed', String(st.auto));
      b('track-prev').disabled = !(st.track && st.track.prev);
      b('track-next').disabled = !(st.track && st.track.next);
      root.classList.toggle('no-py', !st.py);
      root.classList.toggle('no-mean', !st.mean);
      ui.speed.textContent = `${st.speed}×`;
    }

    function renderLibrary() {
      const tracks = st.tracks.filter((t) => !cfg.group || t.group === cfg.group);
      if (!tracks.length) {
        ui.lib.innerHTML = '<div class="hk-empty">Chưa có bài nghe nào. Hãy import gói nội dung trong trang quản trị.</div>';
        return;
      }
      const current = st.track && st.track.id;
      const btn = (t, label, small) => `<button class="hk-track${t.id === current ? ' is-current' : ''}" data-track="${esc(t.id)}"
          ${t.items ? '' : 'disabled title="Chưa có nội dung"'} ${t.playable < t.items ? 'title="Một số mục chưa có file giọng đọc: sẽ đọc bằng giọng của máy"' : ''}>${label}${small ? ` <small>${esc(small)}</small>` : ''}${t.items && t.playable < t.items ? ' <small>· giọng máy</small>' : ''}</button>`;
      // Map giữ đúng thứ tự server trả về (object sẽ đưa khoá "10", "11" lên trước "01")
      const map = new Map();
      tracks.forEach((t) => { if (!map.has(t.group)) map.set(t.group, []); map.get(t.group).push(t); });
      const groups = Object.fromEntries(map);
      const topicIds = [...map.keys()].filter((g) => /^\d{2}$/.test(g));
      const tabs = [];
      if (topicIds.length) tabs.push(['topics', 'Chủ đề']);
      if (groups.hsk2) tabs.push(['hsk2', 'HSK 2']);
      if (groups.hsk3) tabs.push(['hsk3', 'HSK 3']);
      if (!tabs.some(([k]) => k === st.tab)) st.tab = tabs[0][0];

      let body = '';
      if (st.tab === 'topics') {
        body = topicIds.map((g) => {
          const info = st.topics[g] || {};
          const items = groups[g].map((t) => t.type === 'topic'
            ? btn(t, 'Mẫu câu', `${t.items} câu`)
            : btn(t, esc(t.subtitle), `${t.items} từ`)).join('');
          return `<div class="hk-topic"><div class="hk-topic-head"><span class="hk-topic-id">${esc(g)}</span>
              <span class="hk-topic-name">${esc(info.name_vi || groups[g][0].title)}</span>
              <span class="hk-topic-zh">${esc(info.name_zh || '')}</span></div>
              ${info.description ? `<div class="hk-topic-desc">${esc(info.description)}</div>` : ''}
              <div class="hk-tracks">${items}</div></div>`;
        }).join('');
      } else {
        body = `<div class="hk-grid">${groups[st.tab].map((t) => btn(t, esc(t.title.replace(/^HSK \d · /, '')), t.subtitle)).join('')}</div>`;
      }
      ui.lib.innerHTML = (tabs.length > 1 ? `<div class="hk-tabs" role="tablist">${tabs.map(([k, l]) =>
        `<button class="hk-tab" role="tab" data-tab="${k}" aria-selected="${k === st.tab}">${l}</button>`).join('')}</div>` : '') + body;
    }

    // ------------------------------------------------------------ settings sheet
    function openSettings() {
      const s = settings();
      const stepper = (k, min, max, step, show) => `<span class="hk-step" data-k="${k}" data-min="${min}" data-max="${max}" data-step="${step}">
          <button type="button" data-d="-1" aria-label="Giảm">−</button><output>${show(s[k])}</output><button type="button" data-d="1" aria-label="Tăng">+</button></span>`;
      const sw = (k, on) => `<button type="button" class="hk-switch" role="switch" data-k="${k}" aria-checked="${!!on}"></button>`;
      const sec = (v) => `${(v / 1000).toFixed(1)}s`;
      const times = (v) => `${v}×`;
      const listen = cfg.allowOverride ? `
          <div class="hk-field"><label>Đọc từ (tiếng Trung)</label>${stepper('word_repeat', 1, 5, 1, times)}</div>
          <div class="hk-field"><label>Nghĩa của từ<small>đọc 1 lần sau từ</small></label>
            <span class="hk-seg" data-k="meaning_lang">${[['vi', 'Việt'], ['en', 'Anh'], ['none', 'Không']].map(([v, l]) =>
              `<button type="button" data-v="${v}" aria-pressed="${s.meaning_lang === v}">${l}</button>`).join('')}</span></div>
          <div class="hk-field"><label>Đọc mỗi câu<small>0 = chỉ nghe từ</small></label>${stepper('sentence_repeat', 0, 5, 1, times)}</div>
          <div class="hk-field"><label>Đọc nghĩa câu</label>${sw('sentence_meaning', s.sentence_meaning)}</div>
          <div class="hk-field"><label>Lần lặp đọc chậm</label>${sw('slow_repeat', s.slow_repeat)}</div>
          <div class="hk-field"><label>Khoảng lặng</label>${stepper('gap_ms', 300, 5000, 100, sec)}</div>
          <div class="hk-field"><label>Nghỉ sau mỗi mục</label>${stepper('end_gap_ms', 300, 8000, 100, sec)}</div>`
        : '<p class="hk-hint">Cách đọc do quản trị viên cài đặt.</p>';
      ui.sheet.innerHTML = `<form method="dialog" class="hk-sheet-body">
          <h3>Cài đặt nghe</h3>
          <p class="hk-hint">Từ × số lần → nghĩa → mỗi câu: câu → nghĩa câu → câu × (số lần − 1)</p>
          ${listen}
          <div class="hk-field"><label>Nghe liên tục<small>hết bài tự sang bài kế tiếp</small></label>${sw('auto', st.auto)}</div>
          <div class="hk-sheet-foot">
            <button type="button" class="hk-btn" data-sheet="reset">Mặc định</button>
            <span><button type="button" class="hk-btn" data-sheet="close">Đóng</button>
            <button type="button" class="hk-btn primary" data-sheet="apply">Áp dụng</button></span>
          </div></form>`;
      ui.sheet.draft = Object.assign({}, s, { auto: st.auto });
      if (typeof ui.sheet.showModal === 'function') ui.sheet.showModal(); else ui.sheet.setAttribute('open', '');
    }

    ui.sheet.addEventListener('click', (e) => {
      const d = ui.sheet.draft;
      const t = e.target.closest('button');
      if (e.target === ui.sheet) return ui.sheet.close();
      if (!t) return;
      const step = t.closest('.hk-step');
      if (step && t.dataset.d) {
        const k = step.dataset.k, min = +step.dataset.min, max = +step.dataset.max, inc = +step.dataset.step;
        d[k] = Math.max(min, Math.min(max, (+d[k] || 0) + inc * +t.dataset.d));
        step.querySelector('output').textContent = k.endsWith('_ms') ? `${(d[k] / 1000).toFixed(1)}s` : `${d[k]}×`;
      } else if (t.closest('.hk-seg')) {
        const seg = t.closest('.hk-seg');
        d[seg.dataset.k] = t.dataset.v;
        seg.querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b === t)));
      } else if (t.classList.contains('hk-switch')) {
        d[t.dataset.k] = t.getAttribute('aria-checked') !== 'true';
        t.setAttribute('aria-checked', String(d[t.dataset.k]));
      } else if (t.dataset.sheet === 'close') {
        ui.sheet.close();
      } else if (t.dataset.sheet === 'reset') {
        st.over = {};
        st.auto = cfg.defaults.auto_next;
        ui.sheet.close();
        applySettings();
      } else if (t.dataset.sheet === 'apply') {
        if (cfg.allowOverride) {
          st.over = {};
          LISTEN_KEYS.forEach((k) => { if (d[k] !== cfg.defaults[k]) st.over[k] = d[k]; });
        }
        st.auto = !!d.auto;
        ui.sheet.close();
        applySettings();
      }
    });

    function applySettings() {
      persist();
      updateBar();
      if (!st.track) return;
      const unit = st.pl && st.pl.units[st.unit];
      const wasPlaying = !audio.paused;
      const target = unit && unit.parent != null ? st.pl.units[unit.parent] : unit;
      load(st.track.id, { autoplay: wasPlaying, unitId: target && target.id });
    }

    // ------------------------------------------------------------ media session (màn hình khoá, tai nghe)
    function updateMedia(unit) {
      if (!('mediaSession' in navigator) || !st.track) return;
      try {
        navigator.mediaSession.metadata = new MediaMetadata({
          title: unit ? `${unit.zh}  ${unit.pinyin || ''}`.trim() : st.track.title,
          artist: unit ? (unit.vi || '') : '',
          album: `${st.track.title} · ${st.track.subtitle}`,
        });
      } catch (e) { /* bỏ qua */ }
    }
    function setState(s) {
      if ('mediaSession' in navigator) navigator.mediaSession.playbackState = s;
    }
    function updatePosition() {
      if (!('mediaSession' in navigator) || !navigator.mediaSession.setPositionState || !audio.duration) return;
      try {
        navigator.mediaSession.setPositionState({ duration: audio.duration, playbackRate: audio.playbackRate, position: Math.min(audio.currentTime, audio.duration) });
      } catch (e) { /* bỏ qua */ }
    }
    if ('mediaSession' in navigator) {
      const h = (a, fn) => { try { navigator.mediaSession.setActionHandler(a, fn); } catch (e) { /* không hỗ trợ */ } };
      h('play', () => audio.play());
      h('pause', () => audio.pause());
      h('previoustrack', () => unitStep(-1));
      h('nexttrack', () => unitStep(1));
      h('seekbackward', (d) => seekTo(audio.currentTime - (d.seekOffset || 10)));
      h('seekforward', (d) => seekTo(audio.currentTime + (d.seekOffset || 10)));
      h('seekto', (d) => seekTo(d.seekTime));
    }

    // ------------------------------------------------------------ events
    root.addEventListener('click', (e) => {
      const t = e.target.closest('button');
      if (!t || ui.sheet.contains(t)) return;
      if (t.dataset.track) {
        unlock();
        if (st.track && t.dataset.track === st.track.id) { audio.play().catch(() => {}); return; }
        load(t.dataset.track, { autoplay: true, resume: true });
        root.querySelector('.hk-player').scrollIntoView({ behavior: 'smooth', block: 'start' });
        return;
      }
      if (t.dataset.tab) { st.tab = t.dataset.tab; persist(); renderLibrary(); return; }
      switch (t.dataset.act) {
        case 'toggle':
          if (st.loading || !st.pl) {
            // bài đang tải: nhớ ý muốn phát, load() xong sẽ tự phát
            unlock();
            st.wantPlay = true;
            ui.play.classList.add('is-busy');
            if (!st.loading) { const first = st.tracks.find((x) => x.items); if (first) load(first.id, { autoplay: true }); }
            return;
          }
          st.unlocked = true;
          if (audio.paused) audio.play().catch((err) => say(err.message)); else audio.pause();
          break;
        case 'prev': unitStep(-1); break;
        case 'next': unitStep(1); break;
        case 'replay': st.unlocked = true; replayUnit(); break;
        case 'speed':
          st.speed = SPEEDS[(SPEEDS.indexOf(st.speed) + 1) % SPEEDS.length] || 1;
          audio.playbackRate = st.speed;
          persist(); updateBar();
          break;
        case 'py': st.py = !st.py; persist(); updateBar(); break;
        case 'mean': st.mean = !st.mean; persist(); updateBar(); break;
        case 'auto': st.auto = !st.auto; persist(); updateBar(); break;
        case 'track-prev': if (st.track && st.track.prev) { unlock(); load(st.track.prev, { autoplay: !audio.paused }); } break;
        case 'track-next': if (st.track && st.track.next) { unlock(); load(st.track.next, { autoplay: !audio.paused }); } break;
        case 'settings': openSettings(); break;
        case 'library': ui.lib.scrollIntoView({ behavior: 'smooth', block: 'start' }); break;
      }
    });
    ui.range.addEventListener('input', () => {
      const d = audio.duration || 0;
      ui.cur.textContent = fmt((ui.range.value / 1000) * d);
    });
    ui.range.addEventListener('change', () => seekTo((ui.range.value / 1000) * (audio.duration || 0)));
    // màn hình học trên máy tính: phím tắt
    document.addEventListener('keydown', (e) => {
      if (e.target.closest('input, textarea, select, [contenteditable]') || ui.sheet.open || !st.pl) return;
      if (e.code === 'Space') { e.preventDefault(); st.unlocked = true; audio.paused ? audio.play() : audio.pause(); }
      else if (e.key === 'ArrowLeft') unitStep(-1);
      else if (e.key === 'ArrowRight') unitStep(1);
      else if (e.key === 'r') replayUnit();
    });
    window.addEventListener('pagehide', () => savePos(true));

    // ------------------------------------------------------------ start
    updateBar();
    Promise.all([api('tracks'), api('topics').catch(() => [])]).then(([tracks, topics]) => {
      st.tracks = tracks;
      topics.forEach((t) => { st.topics[t.id] = t; });
      renderLibrary();
      const want = [cfg.track, saved.track].find((id) => id && tracks.some((t) => t.id === id && t.items));
      const scoped = tracks.filter((t) => t.items && (!cfg.group || t.group === cfg.group));
      const id = want && (!cfg.group || tracks.find((t) => t.id === want).group === cfg.group) ? want : (scoped[0] && scoped[0].id);
      if (id) load(id, { resume: true });
    }).catch((e) => {
      ui.lib.innerHTML = `<div class="hk-empty">${esc(e.message)}</div>`;
    });
  }

  function boot() {
    document.querySelectorAll('.hskn[data-config]').forEach((el) => {
      if (!el.dataset.ready) { el.dataset.ready = '1'; Player(el); }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
