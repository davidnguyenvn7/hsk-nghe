/*
 * HSK Nghe — Tra câu (phân tích câu).
 * Nhập câu / cụm từ tiếng Trung hoặc tiếng Việt → POST hskn/v1/analyze → các ý, từ vựng (pinyin, Hán Việt, nghĩa, từng chữ),
 * ngữ pháp; mỗi chữ Trung có loa. Clip do server tạo (Google/Azure TTS) theo chữ ký trong kết quả;
 * dịch vụ giọng đọc chưa cấu hình thì đọc bằng giọng của máy. Đăng nhập rồi thì lưu được câu / từ vào sổ tay (HSKNMe).
 *
 * Dùng ở shortcode [hskn_phan_tich] và tab "Tra câu" của trình phát: HSKNAnalyze.mount(el, config).
 * Cần me.js nạp trước.
 */
(function () {
  'use strict';
  if (window.HSKNAnalyze) return;

  const ICON = {
    speak: 'M3 9v6h4l5 5V4L7 9H3zm13.5 3A4.5 4.5 0 0 0 14 7.97v8.05A4.47 4.47 0 0 0 16.5 12zM14 3.23v2.06a7 7 0 0 1 0 13.42v2.06A9 9 0 0 0 14 3.23z',
    slow: 'M12 4a8 8 0 1 0 0 16 8 8 0 0 0 0-16zm0 2a6 6 0 0 1 6 6h-6V6z',
    star: 'M12 17.27 18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z',
    starOff: 'M22 9.24l-7.19-.62L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21 12 17.27 18.18 21l-1.63-7.03L22 9.24zM12 15.4l-3.76 2.27 1-4.28-3.32-2.88 4.38-.38L12 6.1l1.71 4.04 4.38.38-3.32 2.88 1 4.28L12 15.4z',
  };
  const svg = (n) => `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="${ICON[n]}"/></svg>`;
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const STORE = 'hskn:an';
  const storage = {
    read() { try { return JSON.parse(localStorage.getItem(STORE)) || {}; } catch (e) { return {}; } },
    write(v) { try { localStorage.setItem(STORE, JSON.stringify(v)); } catch (e) { /* chế độ riêng tư */ } },
  };
  const EXAMPLES = ['我想租一套两室一厅的房子', '这套房子离地铁站很近', 'Căn hộ này giá bao nhiêu một tháng?', '虽然有点贵，但是环境很好'];
  const Speak = window.HSKNSpeak;
  const Me = window.HSKNMe;

  /** Gọi REST phân tích (dùng cả cho "Tạo bài nghe mới" nhiều dòng). Nonce cũ → thử lại không kèm cookie. */
  async function post(C, path, body) {
    const send = (withCookie) => fetch(C.rest + path, {
      method: 'POST',
      credentials: withCookie ? 'same-origin' : 'omit',
      headers: Object.assign({ 'Content-Type': 'application/json' }, withCookie ? { 'X-WP-Nonce': (Me.enabled && Me.cfg.nonce) || C.nonce } : {}),
      body: JSON.stringify(body),
    });
    let res = await send(true);
    let data = await res.json().catch(() => ({}));
    if (res.status === 403 && data.code === 'rest_cookie_invalid_nonce') {
      if (Me.enabled) { try { await Me.refreshNonce(); res = await send(true); } catch (e) { res = await send(false); } } else res = await send(false);
      data = await res.json().catch(() => ({}));
    }
    if (!res.ok) { const e = new Error(data.message || res.statusText || 'Lỗi mạng'); e.code = data.code; throw e; }
    return data;
  }

  /** Kết quả phân tích → mục để lưu sổ tay. Câu chỉ gồm đúng một từ thì lưu như từ. */
  function toItems(d) {
    const bare = d.zh.replace(/[\s。，、！？!?,.；;：:“”"']/g, '');
    const one = d.words.length === 1 && d.words[0].hanzi === bare ? d.words[0] : null;
    const main = one ? wordItem(one, d.input) : {
      kind: 'sentence', zh: d.zh, pinyin: d.pinyin, vi: d.vi, data: { han_viet: d.han_viet, note: d.note, input: d.input },
    };
    return { main, words: d.words.map((w) => wordItem(w, d.input)) };
  }
  function wordItem(w, input) {
    return {
      kind: 'word', zh: w.hanzi, pinyin: w.pinyin, vi: w.vi,
      data: { han_viet: w.han_viet, pos: w.pos, explain: w.explain, chars: w.chars, example: w.example && { zh: w.example.zh, pinyin: w.example.pinyin, vi: w.example.vi }, input },
    };
  }

  function mount(root, C) {
    const saved = storage.read();
    const st = { busy: false, data: null, items: null, history: Array.isArray(saved.history) ? saved.history.slice(0, 8) : [], py: saved.py !== false, speak: {} };

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

    async function analyze(text) {
      text = text.trim();
      if (!text || st.busy) return;
      st.busy = true;
      go.disabled = true;
      go.textContent = 'Đang phân tích…';
      out.innerHTML = '<div class="hk-an-wait"><span></span><span></span><span></span></div>';
      try {
        const data = await post(C, '', { text });
        st.data = data;
        st.items = toItems(data);
        st.history = [text].concat(st.history.filter((x) => x !== text)).slice(0, 8);
        storage.write({ history: st.history, py: st.py });
        renderResult();
        renderHint();
        root.dispatchEvent(new CustomEvent('hskn:analyzed', { bubbles: true }));
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
    const saveBtn = (ref, item) => {
      const on = Me.enabled && Me.loaded && Me.has(item.kind, item.zh);
      const label = item.kind === 'word' ? 'từ' : 'câu';
      return `<button type="button" class="hk-an-save${on ? ' is-saved' : ''}" data-save="${ref}" aria-pressed="${on}" title="${on ? 'Đã lưu vào sổ tay' : 'Lưu ' + label + ' vào sổ tay'}">${svg(on ? 'star' : 'starOff')}<span>${on ? 'Đã lưu' : 'Lưu ' + label}</span></button>`;
    };
    const listPicker = () => {
      if (!Me.enabled || !Me.lists.length) return '';
      return `<label class="hk-an-to">Lưu vào <select data-an="list">${Me.lists.map((l) =>
        `<option value="${l.id}"${l.id === Me.listId ? ' selected' : ''}>${esc(l.name)}</option>`).join('')}</select></label>`;
    };

    function renderResult() {
      const d = st.data;
      st.speak = {};
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
          <div class="hk-an-acts">${saveBtn('main', st.items.main)}</div>
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
        <div class="hk-an-words">${d.words.map((w, i) => `
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
            <div class="hk-an-acts">${saveBtn('w' + i, st.items.words[i])}</div>
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
        <div class="hk-an-tools">${listPicker()}<button type="button" class="hk-chip" data-an="py" aria-pressed="${st.py}">Pinyin</button></div>
        ${head}${parts}${words}${grammar}
        <p class="hk-an-foot">Phân tích do AI tạo, có thể chưa chính xác tuyệt đối — nhất là âm Hán Việt của chữ đa âm.</p>`;
      out.classList.toggle('no-py', !st.py);
    }

    // trạng thái "đã lưu" / danh sách bài thay đổi ở nơi khác (Sổ tay) → vẽ lại nút
    if (Me.enabled) {
      Me.on(() => {
        if (!st.data) return;
        out.querySelectorAll('[data-save]').forEach((b) => {
          const item = b.dataset.save === 'main' ? st.items.main : st.items.words[+b.dataset.save.slice(1)];
          if (item) b.outerHTML = saveBtn(b.dataset.save, item);
        });
        const pick = out.querySelector('.hk-an-to');
        const html = listPicker();
        if (pick) pick.outerHTML = html || '';
        else if (html) out.querySelector('.hk-an-tools').insertAdjacentHTML('afterbegin', html);
      });
      Me.load().catch(() => {});
    }

    async function save(btn) {
      const ref = btn.dataset.save;
      const item = ref === 'main' ? st.items.main : st.items.words[+ref.slice(1)];
      if (!item) return;
      if (!Me.enabled) {
        if (C.onNeedLogin) C.onNeedLogin('Đăng nhập để lưu câu, từ vào sổ tay và nghe lại sau.');
        else flash('Hãy đăng nhập để lưu vào sổ tay.');
        return;
      }
      if (Me.has(item.kind, item.zh)) { flash('Mục này đã có trong sổ tay.'); return; }
      btn.disabled = true;
      btn.classList.add('is-busy');
      try {
        if (!Me.loaded) await Me.load();
        await Me.save(item);
        const l = Me.find(Me.listId);
        flash(`Đã lưu vào “${l ? l.name : 'Sổ tay'}”.`);
      } catch (e) {
        flash(e.message);
        btn.disabled = false;
        btn.classList.remove('is-busy');
      }
    }

    // ------------------------------------------------------------ loa
    function speak(btn) {
      const a = st.speak[btn.dataset.spk];
      if (!a) return;
      if (C.onSpeak) C.onSpeak();
      Speak.play(btn, {
        url: a.u, text: a.t, rate: a.r,
        fetchUrl: async () => {
          try { a.u = (await post(C, '/audio', { t: a.t, r: a.r, k: a.k })).url; return a.u; } catch (e) { return null; }
        },
      }).catch((e) => flash(e.message));
    }
    function flash(msg) {
      let el = root.querySelector('.hk-an-toast');
      if (!el) { el = document.createElement('div'); el.className = 'hk-an-toast'; el.setAttribute('role', 'status'); root.appendChild(el); }
      el.textContent = msg;
      el.classList.add('is-show');
      clearTimeout(el._t);
      el._t = setTimeout(() => el.classList.remove('is-show'), 3500);
    }

    // ------------------------------------------------------------ sự kiện
    form.addEventListener('submit', (e) => { e.preventDefault(); analyze(input.value); });
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); analyze(input.value); }   // isComposing: đang gõ bộ gõ pinyin/Telex
    });
    root.addEventListener('change', (e) => {
      if (e.target.matches('[data-an="list"]')) Me.listId = +e.target.value;
    });
    root.addEventListener('click', (e) => {
      const b = e.target.closest('button');
      if (!b || !root.contains(b)) return;
      if (b.dataset.q) { input.value = b.dataset.q; analyze(b.dataset.q); return; }
      if (b.dataset.spk) { speak(b); return; }
      if (b.dataset.save) { save(b); return; }
      if (b.dataset.an === 'py') {
        st.py = !st.py;
        b.setAttribute('aria-pressed', st.py);
        out.classList.toggle('no-py', !st.py);
        storage.write({ history: st.history, py: st.py });
      }
    });

    renderHint();
    return {
      focus: () => input.focus(),
      analyze: (t) => { input.value = t; return analyze(t); },
    };
  }

  window.HSKNAnalyze = { mount, stop: () => Speak.stop(), post, toItems };

  function boot() {
    document.querySelectorAll('.hskn[data-analyze]').forEach((el) => {
      if (el.dataset.ready) return;
      el.dataset.ready = '1';
      const me = el.dataset.me && JSON.parse(el.dataset.me);
      if (me) Me.init(me);
      mount(el, JSON.parse(el.dataset.analyze));
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
