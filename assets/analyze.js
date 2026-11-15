/*
 * HSK Nghe — phân tích câu.
 * Nhập câu / cụm từ tiếng Trung hoặc tiếng Việt → POST hskn/v1/analyze → các ý, từ vựng (pinyin, Hán Việt, nghĩa, từng chữ),
 * ngữ pháp; mỗi chữ Trung có loa. Clip do server tạo (Google/Azure TTS) theo chữ ký trong kết quả;
 * dịch vụ giọng đọc chưa cấu hình thì đọc bằng giọng của máy (Web Speech API).
 *
 * Dùng ở shortcode [hskn_phan_tich] và trong trình phát (nút "Phân tích câu"): HSKNAnalyze.mount(el, config).
 */
(function () {
  'use strict';
  if (window.HSKNAnalyze) return;

  const ICON = {
    speak: 'M3 9v6h4l5 5V4L7 9H3zm13.5 3A4.5 4.5 0 0 0 14 7.97v8.05A4.47 4.47 0 0 0 16.5 12zM14 3.23v2.06a7 7 0 0 1 0 13.42v2.06A9 9 0 0 0 14 3.23z',
    slow: 'M12 4a8 8 0 1 0 0 16 8 8 0 0 0 0-16zm0 2a6 6 0 0 1 6 6h-6V6z',
    stop: 'M7 7h10v10H7z',
  };
  const svg = (n) => `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="${ICON[n]}"/></svg>`;
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const STORE = 'hskn:an';
  const storage = {
    read() { try { return JSON.parse(localStorage.getItem(STORE)) || {}; } catch (e) { return {}; } },
    write(v) { try { localStorage.setItem(STORE, JSON.stringify(v)); } catch (e) { /* chế độ riêng tư */ } },
  };
  const EXAMPLES = ['我想租一套两室一厅的房子', '这套房子离地铁站很近', 'Căn hộ này giá bao nhiêu một tháng?', '虽然有点贵，但是环境很好'];

  // ------------------------------------------------------------ audio dùng chung (một phần tử, mở khoá trong cú chạm cho iOS)
  const player = new Audio();
  player.preload = 'auto';
  let current = null;            // nút đang phát
  let unlocked = false;
  let serverTts = true;          // false sau lần đầu server báo chưa có dịch vụ giọng đọc
  const speechOk = 'speechSynthesis' in window && 'SpeechSynthesisUtterance' in window;

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
    player.src = silentUrl();
    player.play().catch(() => {});
    if (speechOk) { const u = new SpeechSynthesisUtterance(' '); u.volume = 0; window.speechSynthesis.speak(u); }
  }
  function mark(btn, on) {
    if (current && current !== btn) current.classList.remove('is-on', 'is-busy');
    current = on ? btn : null;
    if (btn) { btn.classList.toggle('is-on', !!on); if (!on) btn.classList.remove('is-busy'); }
  }
  function stop() {
    player.pause();
    if (speechOk) window.speechSynthesis.cancel();
    mark(current, false);
  }
  player.addEventListener('ended', () => mark(current, false));
  player.addEventListener('error', () => { if (current && !current.classList.contains('is-busy')) mark(current, false); });

  function speakDevice(text, rate, btn) {
    if (!speechOk) throw new Error('Chưa có giọng đọc: trình duyệt này không hỗ trợ, và site chưa cấu hình dịch vụ giọng đọc.');
    window.speechSynthesis.cancel();
    const u = new SpeechSynthesisUtterance(text);
    u.lang = 'zh-CN';
    const v = window.speechSynthesis.getVoices().find((x) => /^zh[-_](CN|Hans)/i.test(x.lang)) || window.speechSynthesis.getVoices().find((x) => /^zh/i.test(x.lang));
    if (v) u.voice = v;
    u.rate = rate === 's' ? 0.7 : rate === 'w' ? 0.85 : 1;
    u.onend = u.onerror = () => { if (current === btn) mark(btn, false); };
    mark(btn, true);
    window.speechSynthesis.speak(u);
  }

  function mount(root, C) {
    const saved = storage.read();
    const st = { busy: false, data: null, history: Array.isArray(saved.history) ? saved.history.slice(0, 8) : [], py: saved.py !== false, speak: {} };

    root.classList.add('hk-an');
    root.innerHTML = `
      <form class="hk-an-form">
        <label class="hk-an-label" for="hk-an-${Date.now()}">Nhập câu hoặc cụm từ bằng tiếng Trung hay tiếng Việt</label>
        <div class="hk-an-box">
          <textarea rows="2" maxlength="200" placeholder="Ví dụ: 我想租一套房子 · Tôi muốn thuê một căn hộ"></textarea>
          <button type="submit" class="hk-an-go">Phân tích</button>
        </div>
        <div class="hk-an-hint"></div>
      </form>
      <div class="hk-an-out" aria-live="polite"></div>`;
    const form = root.querySelector('form');
    const input = root.querySelector('textarea');
    const go = root.querySelector('.hk-an-go');
    const out = root.querySelector('.hk-an-out');
    const hint = root.querySelector('.hk-an-hint');
    input.id = root.querySelector('.hk-an-label').htmlFor;

    function renderHint() {
      const list = st.history.length ? st.history : EXAMPLES;
      hint.innerHTML = `<span>${st.history.length ? 'Gần đây:' : 'Thử:'}</span>` + list.map((t) => `<button type="button" class="hk-an-pill" data-q="${esc(t)}">${esc(t)}</button>`).join('');
    }

    async function post(path, body) {
      const send = (withCookie) => fetch(C.rest + path, {
        method: 'POST',
        credentials: withCookie ? 'same-origin' : 'omit',
        headers: Object.assign({ 'Content-Type': 'application/json' }, withCookie ? { 'X-WP-Nonce': C.nonce } : {}),
        body: JSON.stringify(body),
      });
      let res = await send(true);
      let data = await res.json().catch(() => ({}));
      // trang bị cache lâu → nonce hết hạn: thử lại không kèm cookie (được nếu mọi người đều dùng được)
      if (res.status === 403 && data.code === 'rest_cookie_invalid_nonce') {
        res = await send(false);
        data = await res.json().catch(() => ({}));
      }
      if (!res.ok) { const e = new Error(data.message || res.statusText || 'Lỗi mạng'); e.code = data.code; throw e; }
      return data;
    }

    async function analyze(text) {
      text = text.trim();
      if (!text || st.busy) return;
      st.busy = true;
      go.disabled = true;
      go.textContent = 'Đang phân tích…';
      out.innerHTML = '<div class="hk-an-wait"><span></span><span></span><span></span></div>';
      try {
        const data = await post('', { text });
        st.data = data;
        st.speak = {};
        st.history = [text].concat(st.history.filter((x) => x !== text)).slice(0, 8);
        storage.write({ history: st.history, py: st.py });
        renderResult();
        renderHint();
      } catch (e) {
        out.innerHTML = `<div class="hk-an-err">${esc(e.message)}</div>`;
      } finally {
        st.busy = false;
        go.disabled = false;
        go.textContent = 'Phân tích';
      }
    }

    // ------------------------------------------------------------ hiển thị
    let n = 0;
    const spk = (a, label, kind) => {
      if (!a) return '';
      const id = 'a' + (n++);
      st.speak[id] = a;
      return `<button type="button" class="hk-an-spk${kind === 'slow' ? ' is-slow' : ''}" data-spk="${id}" aria-label="${esc(label)}" title="${esc(label)}">${svg(kind === 'slow' ? 'slow' : 'speak')}${kind === 'slow' ? '<i>chậm</i>' : ''}</button>`;
    };
    const py = (s) => (s ? `<div class="hk-an-py">${esc(s)}</div>` : '');

    function renderResult() {
      const d = st.data;
      n = 0;
      const translated = d.lang === 'vi'
        ? `<div class="hk-an-from">Bạn nhập: “${esc(d.input)}” → tiếng Trung tự nhiên:</div>` : '';
      const head = `
        <section class="hk-an-card hk-an-main">
          ${translated}
          <div class="hk-an-row">
            <div class="hk-an-zh hk-an-big">${esc(d.zh)}</div>
            <div class="hk-an-spks">${spk(d.audio, 'Đọc cả câu')}${spk(d.audio_slow, 'Đọc chậm', 'slow')}</div>
          </div>
          ${py(d.pinyin)}
          ${d.han_viet ? `<div class="hk-an-hv"><span class="hk-an-k">Hán Việt</span> ${esc(d.han_viet)}</div>` : ''}
          <div class="hk-an-vi">${esc(d.vi)}</div>
          ${d.note ? `<div class="hk-an-note">${esc(d.note)}</div>` : ''}
        </section>`;

      const parts = d.parts.length > 1 || (d.parts[0] && d.parts[0].explain) ? `
        <h3 class="hk-an-h">Các ý trong câu <span>${d.parts.length}</span></h3>
        <ol class="hk-an-parts">${d.parts.map((p) => `
          <li class="hk-an-card">
            <div class="hk-an-row"><div class="hk-an-zh">${esc(p.zh)}</div>${spk(p.audio, 'Đọc ý này')}</div>
            ${py(p.pinyin)}
            <div class="hk-an-vi">${esc(p.vi)}</div>
            ${p.explain ? `<div class="hk-an-ex">${esc(p.explain)}</div>` : ''}
          </li>`).join('')}</ol>` : '';

      const words = d.words.length ? `
        <h3 class="hk-an-h">Từ vựng <span>${d.words.length}</span></h3>
        <div class="hk-an-words">${d.words.map((w) => `
          <article class="hk-an-card hk-an-word">
            <div class="hk-an-row">
              <div class="hk-an-zh hk-an-wz">${esc(w.hanzi)}</div>
              <div class="hk-an-wmeta">
                ${w.pinyin ? `<div class="hk-an-wpy">${esc(w.pinyin)}</div>` : ''}
                ${w.han_viet ? `<div class="hk-an-whv">${esc(w.han_viet)}</div>` : ''}
              </div>
              ${spk(w.audio, 'Đọc từ ' + w.hanzi)}
            </div>
            <div class="hk-an-vi">${w.pos ? `<span class="hk-an-pos">${esc(w.pos)}</span>` : ''}${esc(w.vi)}</div>
            ${w.explain ? `<div class="hk-an-ex">${esc(w.explain)}</div>` : ''}
            ${w.chars.length ? `<div class="hk-an-chars">${w.chars.map((c) => `
              <div class="hk-an-char"><b class="hk-an-zh">${esc(c.c)}</b><span>${esc(c.pinyin)}</span><em>${esc(c.han_viet)}</em><small>${esc(c.vi)}</small></div>`).join('')}</div>` : ''}
            ${w.example ? `<div class="hk-an-eg">
              <div class="hk-an-row"><div class="hk-an-zh">${esc(w.example.zh)}</div>${spk(w.example.audio, 'Đọc câu ví dụ')}</div>
              ${py(w.example.pinyin)}<div class="hk-an-egvi">${esc(w.example.vi)}</div></div>` : ''}
          </article>`).join('')}</div>` : '';

      const grammar = d.grammar.length ? `
        <h3 class="hk-an-h">Ngữ pháp</h3>
        ${d.grammar.map((g) => `
          <section class="hk-an-card">
            <div class="hk-an-zh hk-an-pat">${esc(g.pattern)}</div>
            <div class="hk-an-ex">${esc(g.explain)}</div>
            ${g.example_zh ? `<div class="hk-an-eg"><div class="hk-an-row"><div class="hk-an-zh">${esc(g.example_zh)}</div>${spk(g.audio, 'Đọc câu ví dụ')}</div>
              <div class="hk-an-egvi">${esc(g.example_vi)}</div></div>` : ''}
          </section>`).join('')}` : '';

      out.innerHTML = `
        <div class="hk-an-tools"><button type="button" class="hk-chip" data-an="py" aria-pressed="${st.py}">Pinyin</button></div>
        ${head}${parts}${words}${grammar}
        <p class="hk-an-foot">Phân tích do AI tạo, có thể chưa chính xác tuyệt đối — nhất là âm Hán Việt của chữ đa âm.</p>`;
      out.classList.toggle('no-py', !st.py);
    }

    // ------------------------------------------------------------ loa
    async function speak(btn) {
      const a = st.speak[btn.dataset.spk];
      if (!a) return;
      if (current === btn) { stop(); return; }   // bấm lại = dừng
      unlock();
      stop();
      if (C.onSpeak) C.onSpeak();
      if (!serverTts) { try { speakDevice(a.t, a.r, btn); } catch (e) { flash(e.message); } return; }
      mark(btn, true);
      btn.classList.add('is-busy');
      try {
        if (!a.u) a.u = (await post('/audio', { t: a.t, r: a.r, k: a.k })).url;
        if (current !== btn) return;            // người dùng đã bấm loa khác trong lúc chờ
        btn.classList.remove('is-busy');
        player.src = a.u;
        await player.play();
      } catch (e) {
        btn.classList.remove('is-busy');
        if (e.code === 'hskn_tts_off' || e.name === 'NotSupportedError') {
          serverTts = e.code !== 'hskn_tts_off';
          try { speakDevice(a.t, a.r, btn); } catch (e2) { mark(btn, false); flash(e2.message); }
        } else {
          mark(btn, false);
          if (e.name !== 'AbortError') flash(e.message);
        }
      }
    }
    function flash(msg) {
      let el = root.querySelector('.hk-an-toast');
      if (!el) { el = document.createElement('div'); el.className = 'hk-an-toast'; el.setAttribute('role', 'status'); root.appendChild(el); }
      el.textContent = msg;
      el.classList.add('is-show');
      clearTimeout(el._t);
      el._t = setTimeout(() => el.classList.remove('is-show'), 4000);
    }

    // ------------------------------------------------------------ sự kiện
    form.addEventListener('submit', (e) => { e.preventDefault(); analyze(input.value); });
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); analyze(input.value); }   // isComposing: đang gõ bộ gõ pinyin/Telex
    });
    root.addEventListener('click', (e) => {
      const b = e.target.closest('button');
      if (!b || !root.contains(b)) return;
      if (b.dataset.q) { input.value = b.dataset.q; analyze(b.dataset.q); return; }
      if (b.dataset.spk) { speak(b); return; }
      if (b.dataset.an === 'py') {
        st.py = !st.py;
        b.setAttribute('aria-pressed', st.py);
        out.classList.toggle('no-py', !st.py);
        storage.write({ history: st.history, py: st.py });
      }
    });

    renderHint();
    const api = { focus: () => input.focus(), analyze: (t) => { input.value = t; return analyze(t); } };
    setTimeout(api.focus, 50);
    return api;
  }

  window.HSKNAnalyze = { mount, stop };

  function boot() {
    document.querySelectorAll('.hskn[data-analyze]').forEach((el) => {
      if (el.dataset.ready) return;
      el.dataset.ready = '1';
      mount(el, JSON.parse(el.dataset.analyze));
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
