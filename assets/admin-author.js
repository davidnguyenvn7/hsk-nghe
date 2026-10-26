/*
 * Trang "Thêm nội dung": 1. đề xuất từ → 2. duyệt từ → 3. sinh + duyệt câu → 4. lưu + tạo giọng đọc.
 * Mọi bước gọi REST hskn/v1/admin/author; sinh câu và tạo audio chạy theo lô nhỏ.
 */
(function () {
  'use strict';
  const C = window.HSKN_AUTHOR;
  const root = document.getElementById('hskn-author');
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const st = { cfg: null, form: { mode: 'new', topic_id: '', name_vi: '', desc: '', count: 10, list: '', per_word: 2, max_len: 18 }, topic: null, words: [], tokens: 0, busy: false };

  async function api(method, path, body) {
    const res = await fetch(C.rest + path, {
      method, credentials: 'same-origin',
      headers: { 'X-WP-Nonce': C.nonce, 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || res.statusText);
    return data;
  }

  function notice(type, html) {
    return `<div class="notice notice-${type} inline" style="margin:12px 0"><p>${html}</p></div>`;
  }

  function levelBadge(w) {
    const color = { 1: '#2e7d5b', 2: '#2f6fb0', 3: '#8a4fb5', 4: '#b7791f' }[w.level] || '#666';
    const label = w.level >= 4 ? 'chuyên ngành' : `HSK ${w.level}`;
    return `<span style="color:${color};font-size:12px">${label}</span>${w.existing ? ' <span style="color:#666;font-size:12px">· đã có, giữ nghĩa cũ</span>' : ''}`;
  }

  function setBusy(on, text) {
    st.busy = on;
    root.querySelectorAll('button').forEach((b) => { b.disabled = on; });
    const s = root.querySelector('.hskn-status');
    if (s) s.innerHTML = on ? `<span class="spinner is-active" style="float:none;margin:0 6px 0 0"></span>${esc(text || '')}` : esc(text || '');
  }

  // ------------------------------------------------------------------ 0. trang đầu
  async function start() {
    try {
      st.cfg = await api('GET', '');
    } catch (e) {
      root.innerHTML = notice('error', esc(e.message));
      return;
    }
    renderStart();
  }

  function renderStart() {
    st.busy = false;   // mỗi lần vẽ bước mới thì nhả khoá
    const c = st.cfg, f = st.form;
    let html = '';
    if (!c.deepseek) html += notice('warning', `Chưa có DeepSeek API key. <a href="${esc(C.settings)}#api">Nhập ở trang Cài đặt</a>.`);
    if (c.tts_error) html += notice('warning', `${esc(c.tts_error)} <a href="${esc(C.settings)}#api">Cài đặt</a>`);
    if (c.pending_audio) html += notice('info', `Còn <strong>${c.pending_audio}</strong> clip giọng đọc chưa tạo. <button class="button" data-a="audio">Tạo giọng đọc còn thiếu</button>`);
    const opts = c.topics.map((t) => `<option value="${esc(t.id)}" ${t.id === f.topic_id ? 'selected' : ''}>${esc(t.id)} · ${esc(t.name_vi)} ${esc(t.name_zh)}${t.source === 'wp' ? ' (tạo trong WP)' : ''}</option>`).join('');
    html += `
      <h2>1. Chọn chủ đề</h2>
      <table class="form-table" role="presentation">
        <tr><th>Chủ đề</th><td>
          <label><input type="radio" name="mode" value="new" ${f.mode === 'new' ? 'checked' : ''}> Chủ đề mới</label>
          &nbsp; <label><input type="radio" name="mode" value="existing" ${f.mode === 'existing' ? 'checked' : ''}> Thêm từ vào chủ đề có sẵn</label></td></tr>
        <tr class="m-new" ${f.mode === 'new' ? '' : 'hidden'}><th>Tên chủ đề (tiếng Việt)</th><td><input class="regular-text" name="name_vi" value="${esc(f.name_vi)}" placeholder="Nhà hàng"></td></tr>
        <tr class="m-new" ${f.mode === 'new' ? '' : 'hidden'}><th>Các tình huống</th><td><input class="large-text" name="desc" value="${esc(f.desc)}" placeholder="Gọi món, đặt bàn, thanh toán, gọi phục vụ"></td></tr>
        <tr class="m-existing" ${f.mode === 'existing' ? '' : 'hidden'}><th>Chủ đề</th><td><select name="topic_id">${opts}</select></td></tr>
        <tr><th>Số từ muốn thêm</th><td><input type="number" name="count" min="1" max="50" value="${f.count}" class="small-text">
          <p class="description">Hoặc nhập đúng các từ bạn muốn (cách nhau bằng dấu phẩy) — khi đó bỏ qua số từ:</p>
          <input class="large-text" name="list" value="${esc(f.list)}" placeholder="菜单, 服务员, 买单"></td></tr>
        <tr><th>Số câu cho mỗi từ</th><td><input type="number" name="per_word" min="1" max="5" value="${f.per_word}" class="small-text">
          &nbsp; tối đa <input type="number" name="max_len" min="8" max="30" value="${f.max_len}" class="small-text"> chữ Hán mỗi câu</td></tr>
      </table>
      <p><button class="button button-primary" data-a="propose" ${c.deepseek ? '' : 'disabled'}>Đề xuất từ vựng →</button> <span class="hskn-status"></span></p>`;
    if (c.wp_topics.length) {
      html += `<h2>Chủ đề đã tạo trong WP</h2><table class="widefat striped" style="max-width:900px"><thead><tr><th>Mã</th><th>Chủ đề</th><th>Từ</th><th>Câu</th><th></th></tr></thead><tbody>
        ${c.wp_topics.map((t) => `<tr><td>${esc(t.id)}</td><td><strong>${esc(t.name_vi)}</strong> ${esc(t.name_zh)}<br><span class="description">${esc(t.description)}</span></td>
          <td>${t.words}</td><td>${t.sentences}</td><td style="text-align:right">
          <a class="button" href="${esc(C.player)}${C.player.includes('?') ? '&' : '?'}track=words-${esc(t.id)}-01" target="_blank">Nghe</a>
          <button class="button" data-a="more" data-id="${esc(t.id)}">Thêm từ</button>
          <button class="button-link-delete button-link" data-a="hide" data-id="${esc(t.id)}">Ẩn</button></td></tr>`).join('')}
        </tbody></table>`;
    }
    root.innerHTML = html;
  }

  function readForm() {
    const v = (n) => { const el = root.querySelector(`[name="${n}"]`); return el ? el.value.trim() : ''; };
    const f = st.form;
    f.mode = (root.querySelector('[name="mode"]:checked') || {}).value || 'new';
    f.name_vi = v('name_vi'); f.desc = v('desc'); f.topic_id = v('topic_id');
    f.count = +v('count') || 10; f.list = v('list'); f.per_word = +v('per_word') || 2; f.max_len = +v('max_len') || 18;
  }

  // ------------------------------------------------------------------ 1 → 2. đề xuất từ
  async function propose() {
    readForm();
    const f = st.form;
    if (f.mode === 'new' && !f.name_vi) return setBusy(false, 'Nhập tên chủ đề.');
    setBusy(true, 'DeepSeek đang đề xuất từ…');
    try {
      const body = f.mode === 'new' ? { name_vi: f.name_vi, desc: f.desc, count: f.count, list: f.list } : { topic_id: f.topic_id, count: f.count, list: f.list };
      const res = await api('POST', '/words', body);
      st.topic = res.topic;
      st.tokens += res.tokens || 0;
      st.words = res.words.map((w) => Object.assign(w, { selected: true, sentences: [], rejected: [] }));
      if (!st.words.length) return setBusy(false, 'DeepSeek không đề xuất được từ mới (có thể chủ đề đã đủ từ).');
      renderWords();
    } catch (e) {
      setBusy(false, 'Lỗi: ' + e.message);
    }
  }

  function renderWords() {
    st.busy = false;   // mỗi lần vẽ bước mới thì nhả khoá
    const t = st.topic;
    root.innerHTML = `
      <h2>2. Duyệt từ vựng</h2>
      ${t.id ? `<p>Chủ đề <strong>${esc(t.id)} · ${esc(t.name_vi)}</strong> ${esc(t.name_zh)}</p>` : `
        <table class="form-table" role="presentation">
          <tr><th>Tên chủ đề</th><td><input class="regular-text" data-t="name_vi" value="${esc(t.name_vi)}"> <input class="regular-text" data-t="name_zh" value="${esc(t.name_zh)}" placeholder="Tên tiếng Trung"></td></tr>
          <tr><th>Mô tả</th><td><input class="large-text" data-t="desc" value="${esc(t.desc)}"></td></tr></table>`}
      <table class="widefat striped" style="max-width:1000px"><thead><tr>
        <th style="width:30px"><input type="checkbox" data-all checked></th><th>Từ</th><th>Pinyin</th><th>Nghĩa Việt</th><th>Nghĩa Anh</th></tr></thead><tbody>
        ${st.words.map((w, i) => `<tr>
          <td><input type="checkbox" data-sel="${i}" ${w.selected ? 'checked' : ''}></td>
          <td style="font-size:20px">${esc(w.hanzi)}<br>${levelBadge(w)}</td>
          <td><input data-w="${i}" data-k="pinyin" value="${esc(w.pinyin)}" ${w.existing ? 'readonly' : ''} style="width:130px"></td>
          <td><input data-w="${i}" data-k="vi" value="${esc(w.vi)}" ${w.existing ? 'readonly' : ''} class="regular-text" style="width:100%"></td>
          <td><input data-w="${i}" data-k="en" value="${esc(w.en)}" ${w.existing ? 'readonly' : ''} style="width:100%"></td></tr>`).join('')}
      </tbody></table>
      <p><button class="button" data-a="back">← Quay lại</button>
         <button class="button button-primary" data-a="gen">Sinh ${st.form.per_word} câu cho mỗi từ đã chọn →</button>
         <span class="hskn-status"></span></p>`;
  }

  // ------------------------------------------------------------------ 2 → 3. sinh câu
  async function generate(only) {
    const list = (only || st.words.filter((w) => w.selected));
    if (!list.length) return setBusy(false, 'Chưa chọn từ nào.');
    for (let i = 0; i < list.length; i += 8) {
      const batch = list.slice(i, i + 8);
      setBusy(true, `DeepSeek đang viết câu… ${Math.min(i + 8, list.length)}/${list.length} từ`);
      try {
        const res = await api('POST', '/sentences', {
          topic: st.topic, per_word: only ? Math.max(1, st.form.per_word - countKept(batch[0])) : st.form.per_word, max_len: st.form.max_len,
          words: batch.map((w) => ({ hanzi: w.hanzi, vi: w.vi, id: w.id })),
        });
        st.tokens += res.tokens || 0;
        batch.forEach((w) => {
          const r = res.items[w.hanzi] || { accepted: [], rejected: [] };
          w.sentences = (only ? w.sentences : []).concat(r.accepted.map((s) => Object.assign(s, { keep: true })));
          w.rejected = r.rejected;
        });
      } catch (e) {
        setBusy(false, 'Lỗi: ' + e.message);
        return;
      }
    }
    renderSentences();
  }

  const countKept = (w) => w.sentences.filter((s) => s.keep).length;

  function renderSentences() {
    st.busy = false;   // mỗi lần vẽ bước mới thì nhả khoá
    const words = st.words.filter((w) => w.selected);
    const total = words.reduce((n, w) => n + countKept(w), 0);
    root.innerHTML = `
      <h2>3. Duyệt câu</h2>
      <p>Bỏ chọn câu không ưng, sửa nghĩa nếu cần. Câu chữ Hán không sửa được (pinyin và tách từ đi theo câu) — muốn câu khác thì bấm <em>Sinh thêm</em>.</p>
      ${words.map((w) => {
        const wi = st.words.indexOf(w);
        return `<div class="card" style="max-width:1000px;padding:8px 16px;margin:10px 0">
          <h3 style="margin:8px 0">${esc(w.hanzi)} <span style="font-weight:normal;color:#666">${esc(w.pinyin)} · ${esc(w.vi)}</span>
            <button class="button button-small" data-a="regen" data-i="${wi}" style="float:right">Sinh thêm</button></h3>
          ${w.sentences.length ? w.sentences.map((s, si) => `
            <div style="display:grid;grid-template-columns:24px 1fr;gap:4px 8px;padding:6px 0;border-top:1px solid #eee">
              <input type="checkbox" data-keep="${wi}:${si}" ${s.keep ? 'checked' : ''}>
              <div><div style="font-size:18px">${esc(s.zh)} <span style="color:#666;font-size:13px">${esc(s.pinyin)}</span>${s.grammar ? ` <code>${esc(s.grammar)}</code>` : ''}</div>
                <input data-s="${wi}:${si}" data-k="vi" value="${esc(s.vi)}" style="width:49%"> <input data-s="${wi}:${si}" data-k="en" value="${esc(s.en)}" style="width:49%"></div>
            </div>`).join('') : '<p class="description">Chưa có câu đạt yêu cầu.</p>'}
          ${w.rejected.length ? `<details style="margin:4px 0 8px"><summary class="description">${w.rejected.length} câu bị loại</summary>
            ${w.rejected.map((r) => `<div class="description">「${esc(r.zh)}」 → ${esc(r.errors.join('; '))}</div>`).join('')}</details>` : ''}
        </div>`;
      }).join('')}
      <p><button class="button" data-a="words">← Sửa danh sách từ</button>
         <button class="button button-primary" data-a="save">Lưu ${words.length} từ, ${total} câu và tạo giọng đọc →</button>
         <span class="hskn-status">${st.tokens ? `DeepSeek đã dùng ${st.tokens.toLocaleString()} token` : ''}</span></p>`;
  }

  // ------------------------------------------------------------------ 3 → 4. lưu + audio
  async function save() {
    setBusy(true, 'Đang lưu…');
    try {
      const words = st.words.filter((w) => w.selected).map((w) => ({
        hanzi: w.hanzi, id: w.id, pinyin: w.pinyin, vi: w.vi, en: w.en,
        sentences: w.sentences.filter((s) => s.keep).map((s) => ({ zh: s.zh, pinyin: s.pinyin, vi: s.vi, en: s.en, grammar: s.grammar, tokens: s.tokens })),
      }));
      const res = await api('POST', '/save', { topic: st.topic, words });
      st.saved = res;
      root.innerHTML = `<h2>4. Tạo giọng đọc</h2>
        ${notice('success', `Đã lưu chủ đề <strong>${esc(res.topic_id)}</strong>: thêm ${res.words} từ, ${res.sentences} câu.`)}
        ${res.skipped.length ? notice('warning', 'Bỏ qua: ' + res.skipped.map(esc).join('<br>')) : ''}
        <progress max="100" value="0" style="width:100%;max-width:700px;height:18px"></progress>
        <p class="hskn-status"></p><p class="hskn-done"></p>`;
      await audio();
    } catch (e) {
      setBusy(false, 'Lỗi: ' + e.message);
    }
  }

  async function audio() {
    const bar = root.querySelector('progress');
    let first = null, made = 0, stuck = 0;
    for (;;) {
      setBusy(true, `Đang tạo giọng đọc… đã tạo ${made} clip`);
      let res;
      try {
        res = await api('POST', '/audio');
      } catch (e) {
        setBusy(false, 'Lỗi: ' + e.message);
        return;
      }
      made += res.made;
      first = first ?? res.remaining + res.made + res.reused;
      if (bar && first) bar.value = Math.round(100 * (first - res.remaining) / first);
      if (res.remaining === 0) break;
      stuck = res.made + res.reused === 0 ? stuck + 1 : 0;
      if (stuck >= 2) {
        setBusy(false, 'Dừng vì lỗi: ' + (res.errors || []).join(' · '));
        return;
      }
    }
    setBusy(false, `Xong: tạo ${made} clip mới.`);
    const id = st.saved ? st.saved.topic_id : '';
    const done = root.querySelector('.hskn-done') || root;
    done.insertAdjacentHTML('beforeend', `<p>${id ? `<a class="button button-primary" target="_blank" href="${esc(C.player)}${C.player.includes('?') ? '&' : '?'}track=words-${esc(id)}-01">Nghe thử chủ đề ${esc(id)}</a> ` : ''}
      <button class="button" data-a="restart">Thêm nội dung khác</button></p>`);
  }

  // ------------------------------------------------------------------ events
  root.addEventListener('change', (e) => {
    const t = e.target;
    if (t.name === 'mode') { readForm(); renderStart(); }
    else if (t.dataset.all !== undefined) { st.words.forEach((w) => { w.selected = t.checked; }); renderWords(); }
    else if (t.dataset.sel) st.words[+t.dataset.sel].selected = t.checked;
    else if (t.dataset.keep) { const [wi, si] = t.dataset.keep.split(':').map(Number); st.words[wi].sentences[si].keep = t.checked; }
  });
  root.addEventListener('input', (e) => {
    const t = e.target;
    if (t.dataset.w) st.words[+t.dataset.w][t.dataset.k] = t.value;
    else if (t.dataset.s) { const [wi, si] = t.dataset.s.split(':').map(Number); st.words[wi].sentences[si][t.dataset.k] = t.value; }
    else if (t.dataset.t) st.topic[t.dataset.t] = t.value;
  });
  root.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-a]');
    if (!b || st.busy) return;
    e.preventDefault();
    switch (b.dataset.a) {
      case 'propose': propose(); break;
      case 'back': case 'restart': st.words = []; await start(); break;
      case 'words': renderWords(); break;
      case 'gen': generate(); break;
      case 'regen': generate([st.words[+b.dataset.i]]); break;
      case 'save': save(); break;
      case 'audio':
        root.insertAdjacentHTML('afterbegin', '<progress max="100" value="0" style="width:100%;max-width:700px;height:18px"></progress><p class="hskn-status"></p><p class="hskn-done"></p>');
        audio();
        break;
      case 'more': st.form.mode = 'existing'; st.form.topic_id = b.dataset.id; renderStart(); window.scrollTo(0, 0); break;
      case 'hide':
        if (!confirm('Ẩn chủ đề này khỏi trình phát? (Không xoá dữ liệu.)')) return;
        await api('DELETE', '/topics/' + b.dataset.id);
        await start();
        break;
    }
  });

  start();
})();
