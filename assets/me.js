/*
 * HSK Nghe — phần dùng chung cho trình phát, Tra câu và Sổ tay.
 *
 *   HSKNSpeak  loa: phát một clip MP3 (URL có sẵn hoặc lấy từ server) hoặc đọc bằng giọng của máy.
 *              Một phần tử <audio> dùng chung, được "mở khoá" trong cú chạm đầu tiên (iOS).
 *   HSKNMe     sổ tay của người đăng nhập (REST hskn/v1/me/*): các bài, mục đã lưu, lưu / xoá.
 *              Nonce hết hạn (trang / app mở lâu) → tự xin nonce mới qua admin-ajax rồi gọi lại.
 */
(function () {
  'use strict';
  if (window.HSKNMe) return;

  // ------------------------------------------------------------ loa
  const speechOk = 'speechSynthesis' in window && 'SpeechSynthesisUtterance' in window;
  const audio = new Audio();
  audio.preload = 'auto';
  let current = null;
  let unlocked = false;
  const listeners = new Set();

  function silentUrl() {
    const frame = new Uint8Array(144);
    frame.set([0xff, 0xf3, 0x64, 0xc4]);
    const all = new Uint8Array(144 * 8);
    for (let i = 0; i < 8; i++) all.set(frame, i * 144);
    return URL.createObjectURL(new Blob([all], { type: 'audio/mpeg' }));
  }
  function unlock() {
    if (unlocked) return;
    unlocked = true;
    audio.src = silentUrl();
    audio.play().catch(() => {});
    if (speechOk) { const u = new SpeechSynthesisUtterance(' '); u.volume = 0; window.speechSynthesis.speak(u); }
  }
  function mark(btn, on) {
    if (current && current !== btn) current.classList.remove('is-on', 'is-busy');
    current = on ? btn : null;
    if (btn) { btn.classList.toggle('is-on', !!on); if (!on) btn.classList.remove('is-busy'); }
  }
  function stop() {
    audio.pause();
    if (speechOk) window.speechSynthesis.cancel();
    mark(current, false);
  }
  audio.addEventListener('ended', () => mark(current, false));
  audio.addEventListener('error', () => { if (current && !current.classList.contains('is-busy')) mark(current, false); });

  function device(text, rate, btn, lang) {
    if (!speechOk) throw new Error('Trình duyệt này không có giọng đọc.');
    window.speechSynthesis.cancel();
    const u = new SpeechSynthesisUtterance(text);
    u.lang = lang || 'zh-CN';
    const voices = window.speechSynthesis.getVoices();
    const pre = u.lang.slice(0, 2).toLowerCase();
    const v = voices.find((x) => x.lang.replace('_', '-').toLowerCase() === u.lang.toLowerCase()) || voices.find((x) => x.lang.toLowerCase().startsWith(pre));
    if (v) u.voice = v;
    u.rate = rate === 's' ? 0.7 : rate === 'w' ? 0.85 : 1;
    u.onend = u.onerror = () => { if (current === btn) mark(btn, false); };
    mark(btn, true);
    window.speechSynthesis.speak(u);
  }

  /**
   * Bấm loa. opt: {url?, fetchUrl?: async () => url, text, rate: 'n'|'w'|'s'}
   * Bấm lại đúng nút đang phát = dừng. Không lấy được clip thì đọc bằng giọng máy.
   */
  async function play(btn, opt) {
    if (current === btn) { stop(); return; }
    unlock();
    stop();
    listeners.forEach((fn) => fn());
    let url = opt.url || null;
    if (!url && opt.fetchUrl) {
      mark(btn, true);
      btn.classList.add('is-busy');
      try { url = await opt.fetchUrl(); } catch (e) { url = null; }
      if (current !== btn) return;            // đã bấm loa khác trong lúc chờ
      btn.classList.remove('is-busy');
    }
    if (!url) { device(opt.text, opt.rate, btn); return; }
    mark(btn, true);
    try {
      audio.src = url;
      await audio.play();
    } catch (e) {
      if (e.name === 'AbortError') return;
      try { device(opt.text, opt.rate, btn); } catch (e2) { mark(btn, false); throw e2; }
    }
  }

  window.HSKNSpeak = { play, stop, unlock, device, onStart: (fn) => listeners.add(fn) };

  // ------------------------------------------------------------ sổ tay
  const KEY = 'hskn:me';
  const store = {
    read() { try { return JSON.parse(localStorage.getItem(KEY)) || {}; } catch (e) { return {}; } },
    write(v) { try { localStorage.setItem(KEY, JSON.stringify(Object.assign(store.read(), v))); } catch (e) { /* bỏ qua */ } },
  };

  const me = {
    enabled: false,
    loaded: false,
    lists: [],
    keys: new Set(),
    subs: new Set(),
    cfg: null,
    loading: null,

    init(cfg) {
      if (!cfg || this.enabled) return this;
      this.cfg = Object.assign({}, cfg);
      this.enabled = true;
      return this;
    },

    on(fn) { this.subs.add(fn); return () => this.subs.delete(fn); },
    emit() { this.subs.forEach((fn) => { try { fn(this); } catch (e) { console.error(e); } }); },

    async refreshNonce() {
      const res = await fetch(this.cfg.ajax + '?action=rest-nonce', { credentials: 'same-origin' });
      const text = (await res.text()).trim();
      if (!res.ok || !text || text === '0' || text === '-1') throw new Error('Phiên đăng nhập đã hết — hãy đăng nhập lại.');
      this.cfg.nonce = text;
    },

    async api(method, path, body, retried) {
      const res = await fetch(this.cfg.rest + path, {
        method,
        credentials: 'same-origin',
        headers: Object.assign({ 'X-WP-Nonce': this.cfg.nonce }, body ? { 'Content-Type': 'application/json' } : {}),
        body: body ? JSON.stringify(body) : undefined,
      });
      const data = await res.json().catch(() => ({}));
      if ((data.code === 'rest_cookie_invalid_nonce' || (res.status === 401 && path.startsWith('me/'))) && !retried && this.cfg.ajax) {
        await this.refreshNonce();
        return this.api(method, path, body, true);
      }
      if (!res.ok) { const e = new Error(data.message || `Lỗi ${res.status}`); e.code = data.code; throw e; }
      return data;
    },

    load(force) {
      if (!this.enabled) return Promise.resolve(this);
      if (this.loading && !force) return this.loading;
      this.loading = this.api('GET', 'me/lists').then((d) => {
        this.lists = d.lists || [];
        this.keys = new Set(d.keys || []);
        this.loaded = true;
        this.emit();
        return this;
      }).catch((e) => { this.loading = null; throw e; });
      return this.loading;
    },

    key(kind, zh) { return (kind === 'word' ? 'w:' : 's:') + zh; },
    has(kind, zh) { return this.keys.has(this.key(kind, zh)); },

    /** Bài đang chọn để lưu vào (nhớ trên máy). */
    get listId() {
      const id = +store.read().list;
      return this.lists.some((l) => l.id === id) ? id : (this.lists[0] && this.lists[0].id) || 0;
    },
    set listId(id) { store.write({ list: +id }); this.emit(); },

    find(id) { return this.lists.find((l) => l.id === +id); },

    async save(item, listId) {
      const saved = await this.api('POST', 'me/items', Object.assign({}, item, { list_id: listId || this.listId }));
      if (!this.has(saved.kind, saved.zh)) {
        const l = this.find(saved.list_id);
        if (l) l[saved.kind === 'word' ? 'words' : 'sentences']++;
      }
      this.keys.add(this.key(saved.kind, saved.zh));
      this.emit();
      return saved;
    },

    async remove(item) {
      await this.api('DELETE', 'me/items/' + item.id);
      await this.load(true);   // khoá "đã lưu" có thể còn ở bài khác → tải lại cho chắc
    },

    items(listId) { return this.api('GET', `me/lists/${listId}/items`); },

    async createList(name) {
      const l = await this.api('POST', 'me/lists', { name });
      this.lists.push(l);
      this.listId = l.id;
      return l;
    },
    async renameList(id, name) {
      await this.api('POST', 'me/lists/' + id, { name });
      const l = this.find(id);
      if (l) l.name = name.trim();
      this.emit();
    },
    async deleteList(id) {
      await this.api('DELETE', 'me/lists/' + id);
      await this.load(true);
    },
  };

  window.HSKNMe = me;
})();
