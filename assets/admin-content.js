/*
 * Trang "Quản lý nội dung": duyệt + sửa chủ đề, từ, câu; ẩn/hiện; DeepSeek viết thêm câu; tạo lại giọng đọc.
 *
 * Mỗi khối sửa được có data-kind (topic | word | sentence) + data-id; ô nhập có data-f (tên trường)
 * và data-orig (giá trị ban đầu). Bấm Lưu chỉ gửi những trường đã đổi.
 */
(function () {
  'use strict';
  const C = window.HSKN_CONTENT;
  const root = document.getElementById('hskn-content');
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const st = { view: 'topics', topic: null, sub: 'words', level: 2, q: '', page: 1, pending: 0, audioBusy: false };

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

  const playerLink = (track) => `${C.player}${C.player.includes('?') ? '&' : '?'}track=${encodeURIComponent(track)}`;

  // ------------------------------------------------------------------ khung
  function shell(body) {
    const tab = (v, label) => `<a href="#" class="nav-tab${st.view === v || (v === 'topics' && st.view === 'topic') ? ' nav-tab-active' : ''}" data-nav="${v}">${label}</a>`;
    root.innerHTML = `
      <style>
        #hskn-content .hk-row{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:10px 12px;margin:8px 0}
        #hskn-content .hk-row.off{opacity:.55;background:#f6f7f7}
        #hskn-content .hk-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:6px;margin-top:6px}
        #hskn-content input.dirty{border-color:#d63638;box-shadow:0 0 0 1px #d63638}
        #hskn-content .zh{font-size:17px}
        #hskn-content .hz{font-size:26px;margin-right:10px}
        #hskn-content .msg{color:#d63638;margin-top:4px}
        #hskn-content .ok{color:#008a20;margin-left:6px}
        #hskn-content .tag{display:inline-block;font-size:11px;padding:0 6px;border-radius:8px;background:#f0f0f1;color:#50575e;margin-left:4px}
        #hskn-content .word-card{border-left:4px solid #2271b1}
        #hskn-content .sent{margin-left:18px}
        #hskn-content .hk-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:6px}
      </style>
      <nav class="nav-tab-wrapper" style="margin-bottom:12px">${tab('topics', 'Chủ đề')}${tab('hsk2', 'Từ HSK 2')}${tab('hsk3', 'Từ HSK 3')}
        <span style="float:right;padding:6px 0" class="hk-audio"></span></nav>
      <div class="hk-body">${body}</div>`;
    renderAudio();
  }

  function renderAudio() {
    const el = root.querySelector('.hk-audio');
    if (!el) return;
    el.innerHTML = st.pending
      ? `<strong>${st.pending}</strong> clip giọng đọc cần tạo lại <button class="button button-primary" data-a="audio" ${st.audioBusy ? 'disabled' : ''}>Tạo giọng đọc</button>`
      : '<span class="description">Giọng đọc đã khớp với nội dung ✓</span>';
  }

  async function refreshAudio() {
    try { st.pending = (await api('GET', 'content/audio')).pending; } catch (e) { /* bỏ qua */ }
    renderAudio();
  }

  async function runAudio() {
    st.audioBusy = true;
    let stuck = 0;
    try {
      for (;;) {
        root.querySelector('.hk-audio').innerHTML = `<span class="spinner is-active" style="float:none;margin:0 6px"></span>Đang tạo giọng đọc… còn ${st.pending}`;
        const r = await api('POST', 'author/audio');
        st.pending = r.remaining;
        if (!r.remaining) break;
        stuck = r.made + r.reused === 0 ? stuck + 1 : 0;
        if (stuck >= 2) throw new Error((r.errors || []).join(' · ') || 'không tạo được clip');
      }
    } catch (e) {
      alert('Tạo giọng đọc lỗi: ' + e.message);
    }
    st.audioBusy = false;
    renderAudio();
  }

  // ------------------------------------------------------------------ chủ đề
  async function showTopics() {
    st.view = 'topics';
    shell('<p>Đang tải…</p>');
    const list = await api('GET', 'content/topics');
    root.querySelector('.hk-body').innerHTML = `
      <p><a class="button" href="${esc(C.add)}">+ Thêm chủ đề / từ mới (DeepSeek)</a></p>
      <table class="widefat striped"><thead><tr><th>Mã</th><th>Chủ đề</th><th>Từ</th><th>Mẫu câu</th><th>Câu theo từ</th><th></th></tr></thead><tbody>
      ${list.map((t) => `<tr style="${t.is_active ? '' : 'opacity:.5'}">
        <td>${esc(t.id)}</td>
        <td><a href="#" data-open="${esc(t.id)}"><strong>${esc(t.name_vi)}</strong></a> ${esc(t.name_zh)}
          ${t.source === 'wp' ? '<span class="tag">đã sửa / tạo trong WP</span>' : ''}${t.is_active ? '' : '<span class="tag">đang ẩn</span>'}
          <br><span class="description">${esc(t.description)}</span></td>
        <td>${t.words}</td><td>${t.patterns}</td><td>${t.word_sentences}</td>
        <td style="text-align:right;white-space:nowrap"><button class="button" data-open="${esc(t.id)}">Mở</button>
          <button class="button-link" data-a="topic-toggle" data-id="${esc(t.id)}" data-on="${t.is_active}">${t.is_active ? 'Ẩn' : 'Hiện'}</button></td></tr>`).join('')}
      </tbody></table>`;
  }

  async function showTopic(id) {
    st.view = 'topic';
    shell('<p>Đang tải…</p>');
    const t = st.topic = await api('GET', 'content/topics/' + id);
    const words = t.words, pats = t.patterns;
    const sub = (v, label) => `<a href="#" class="button${st.sub === v ? ' button-primary' : ''}" data-sub="${v}">${label}</a>`;
    root.querySelector('.hk-body').innerHTML = `
      <p><a href="#" data-nav="topics">← Tất cả chủ đề</a></p>
      <div class="hk-row" data-kind="topic" data-id="${esc(t.id)}">
        <strong>Chủ đề ${esc(t.id)}</strong> ${t.is_active ? '' : '<span class="tag">đang ẩn</span>'}
        <div class="hk-grid">${inp('name_vi', t.name_vi, 'Tên tiếng Việt')}${inp('name_zh', t.name_zh, 'Tên tiếng Trung')}</div>
        <div class="hk-grid">${inp('description', t.description, 'Các tình huống')}</div>
        <div class="hk-actions"><button class="button" data-a="save">Lưu</button>
          <a class="button" target="_blank" href="${esc(playerLink('words-' + t.id + '-01'))}">Nghe từ vựng</a>
          ${pats.length ? `<a class="button" target="_blank" href="${esc(playerLink('topic-' + t.id))}">Nghe mẫu câu</a>` : ''}
          <a class="button" href="${esc(C.add)}">+ Thêm từ (DeepSeek)</a><span class="res"></span></div>
      </div>
      <p>${sub('words', `Từ vựng (${words.length})`)} ${pats.length ? sub('patterns', `Mẫu câu & hội thoại (${pats.length})`) : ''}</p>
      <div class="hk-list">${st.sub === 'patterns' && pats.length ? pats.map(sentenceRow).join('') : words.map(wordCard).join('') || '<p>Chủ đề chưa có từ.</p>'}</div>`;
  }

  function inp(field, value, placeholder, cls = '') {
    return `<input type="text" class="${cls}" data-f="${field}" data-orig="${esc(value)}" value="${esc(value)}" placeholder="${esc(placeholder)}" title="${esc(placeholder)}">`;
  }

  function wordCard(w) {
    return `<div class="hk-row word-card" data-kind="word" data-id="${esc(w.id)}">
      <div><span class="hz">${esc(w.hanzi)}</span><span class="tag">${esc(w.id)}</span>${w.level >= 4 ? '<span class="tag">chuyên ngành</span>' : `<span class="tag">HSK ${w.level}</span>`}</div>
      <div class="hk-grid">${inp('pinyin', w.pinyin, 'Pinyin')}${inp('vi', w.vi, 'Nghĩa Việt')}${inp('en', w.en, 'Nghĩa Anh')}</div>
      <div class="hk-actions"><button class="button" data-a="save">Lưu từ</button>
        <button class="button" data-a="more" data-word="${esc(w.id)}">+ DeepSeek viết thêm câu</button>
        <button class="button-link button-link-delete" data-a="remove-word" data-word="${esc(w.id)}">Bỏ khỏi chủ đề</button><span class="res"></span></div>
      <div class="sent">${(w.sentences || []).map(sentenceRow).join('')}</div>
      <div class="sent hk-suggest"></div>
    </div>`;
  }

  function sentenceRow(s) {
    return `<div class="hk-row${s.is_active ? '' : ' off'}" data-kind="sentence" data-id="${esc(s.id)}">
      <span class="tag">${esc(s.id)}</span>${s.speaker ? `<span class="tag">Người ${esc(s.speaker)}</span>` : ''}${s.is_active ? '' : '<span class="tag">đang ẩn</span>'}
      <div class="hk-grid" style="grid-template-columns:2fr 2fr">${inp('zh', s.zh, 'Câu tiếng Trung', 'zh')}${inp('pinyin', s.pinyin, 'Pinyin')}</div>
      <div class="hk-grid">${inp('vi', s.vi, 'Nghĩa Việt')}${inp('en', s.en, 'Nghĩa Anh')}${inp('grammar', s.grammar, 'Ngữ pháp')}</div>
      <div class="hk-actions"><button class="button button-small" data-a="save">Lưu câu</button>
        <button class="button-link" data-a="sent-toggle" data-on="${s.is_active}">${s.is_active ? 'Ẩn câu' : 'Hiện lại'}</button><span class="res"></span></div>
    </div>`;
  }

  // ------------------------------------------------------------------ từ HSK
  async function showLevel(level, page = 1) {
    st.view = 'hsk' + level;
    st.level = level;
    st.page = page;
    shell(`<p><input type="search" class="regular-text" data-search value="${esc(st.q)}" placeholder="Tìm chữ Hán, pinyin, nghĩa…"> <button class="button" data-a="search">Tìm</button></p><div class="hk-list"><p>Đang tải…</p></div>`);
    const r = await api('GET', `content/words?level=${level}&page=${page}&q=${encodeURIComponent(st.q)}`);
    const pages = Math.max(1, Math.ceil(r.total / r.per_page));
    const nav = `<p>${r.total} từ · trang ${page}/${pages}
      <button class="button" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>‹</button>
      <button class="button" data-page="${page + 1}" ${page >= pages ? 'disabled' : ''}>›</button></p>`;
    root.querySelector('.hk-list').innerHTML = nav + r.items.map((w) => `
      <div class="hk-row word-card" data-kind="word" data-id="${esc(w.id)}">
        <div><span class="hz">${esc(w.hanzi)}</span><span class="tag">${esc(w.id)}</span></div>
        <div class="hk-grid">${inp('pinyin', w.pinyin, 'Pinyin')}${inp('vi', w.vi, 'Nghĩa Việt')}${inp('en', w.en, 'Nghĩa Anh')}</div>
        <div class="description" style="margin-top:6px">Câu ví dụ</div>
        <div class="hk-grid" style="grid-template-columns:2fr 2fr">${inp('example_zh', w.example_zh, 'Câu ví dụ', 'zh')}${inp('example_pinyin', w.example_pinyin, 'Pinyin câu')}</div>
        <div class="hk-grid">${inp('example_vi', w.example_vi, 'Nghĩa Việt câu')}${inp('example_en', w.example_en, 'Nghĩa Anh câu')}</div>
        <div class="hk-actions"><button class="button" data-a="save">Lưu</button>
          <button class="button" data-a="example">DeepSeek viết câu ví dụ khác</button><span class="res"></span></div>
        <div class="hk-suggest"></div>
      </div>`).join('') + nav;
  }

  // ------------------------------------------------------------------ lưu
  function changes(box) {
    const out = {};
    box.querySelectorAll(':scope > .hk-grid input[data-f], :scope > .hk-grid input[data-f]').forEach((i) => {
      if (i.value !== i.dataset.orig) out[i.dataset.f] = i.value;
    });
    return out;
  }

  async function save(box, force = false) {
    const res = box.querySelector(':scope > .hk-actions .res');
    const data = changes(box);
    if (!Object.keys(data).length) { res.innerHTML = '<span class="ok">Không có gì thay đổi</span>'; return; }
    const kind = box.dataset.kind, id = box.dataset.id;
    const path = { topic: 'content/topics/', word: 'content/words/', sentence: 'content/sentences/' }[kind] + id + (force ? '?force=1' : '');
    res.innerHTML = '<span class="spinner is-active" style="float:none"></span>' + (('zh' in data || 'example_zh' in data) ? ' DeepSeek đang kiểm tra câu…' : '');
    try {
      const r = await api('PATCH', path, data);
      if (r.ok === false) {
        res.innerHTML = `<div class="msg">${esc(r.errors.join('; '))} <button class="button button-small" data-a="force">Vẫn lưu</button></div>`;
        return;
      }
      const fresh = r.sentence || r.word;
      box.querySelectorAll('input[data-f]').forEach((i) => {
        if (fresh && i.dataset.f in fresh) i.value = fresh[i.dataset.f] ?? '';
        i.dataset.orig = i.value;
        i.classList.remove('dirty');
      });
      res.innerHTML = '<span class="ok">Đã lưu ✓</span>';
      refreshAudio();
    } catch (e) {
      res.innerHTML = `<span class="msg">${esc(e.message)}</span>`;
    }
  }

  // DeepSeek viết thêm câu cho một từ trong chủ đề → xem trước → thêm (dùng API của trang Thêm nội dung)
  async function more(box, wid) {
    const t = st.topic;
    const w = t.words.find((x) => x.id === wid);
    const out = box.querySelector('.hk-suggest');
    out.innerHTML = '<p><span class="spinner is-active" style="float:none"></span> DeepSeek đang viết câu…</p>';
    try {
      const r = await api('POST', 'author/sentences', { topic: { id: t.id, name_vi: t.name_vi, name_zh: t.name_zh, desc: t.description }, words: [{ hanzi: w.hanzi, vi: w.vi, id: w.id }], per_word: 3 });
      const acc = (r.items[w.hanzi] || {}).accepted || [];
      out._acc = acc;
      out.innerHTML = acc.length ? `<div class="hk-row" style="background:#f0f6fc">
          ${acc.map((s, i) => `<label style="display:block;margin:4px 0"><input type="checkbox" data-pick="${i}" checked> <span class="zh">${esc(s.zh)}</span> <span class="description">${esc(s.pinyin)} — ${esc(s.vi)}</span></label>`).join('')}
          <div class="hk-actions"><button class="button button-primary" data-a="add-picked" data-word="${esc(w.id)}">Thêm câu đã chọn</button> <button class="button-link" data-a="clear">Bỏ</button></div></div>`
        : '<p class="msg">DeepSeek chưa viết được câu đạt yêu cầu, thử lại.</p>';
    } catch (e) {
      out.innerHTML = `<p class="msg">${esc(e.message)}</p>`;
    }
  }

  async function addPicked(box, wid) {
    const t = st.topic;
    const w = t.words.find((x) => x.id === wid);
    const out = box.querySelector('.hk-suggest');
    const picked = [...out.querySelectorAll('[data-pick]:checked')].map((c) => out._acc[+c.dataset.pick]);
    if (!picked.length) return;
    await api('POST', 'author/save', { topic: { id: t.id }, words: [{ hanzi: w.hanzi, id: w.id, sentences: picked }] });
    await showTopic(t.id);
    refreshAudio();
  }

  async function example(box) {
    const out = box.querySelector('.hk-suggest');
    out.innerHTML = '<p><span class="spinner is-active" style="float:none"></span> DeepSeek đang viết câu…</p>';
    try {
      const s = await api('POST', `content/words/${box.dataset.id}/example`);
      out._ex = s;
      out.innerHTML = `<div class="hk-row" style="background:#f0f6fc"><span class="zh">${esc(s.zh)}</span> <span class="description">${esc(s.pinyin)} — ${esc(s.vi)} / ${esc(s.en)}</span>
        <div class="hk-actions"><button class="button button-primary" data-a="use-example">Dùng câu này</button> <button class="button" data-a="example">Câu khác</button> <button class="button-link" data-a="clear">Bỏ</button></div></div>`;
    } catch (e) {
      out.innerHTML = `<p class="msg">${esc(e.message)}</p>`;
    }
  }

  // ------------------------------------------------------------------ sự kiện
  root.addEventListener('input', (e) => {
    const i = e.target;
    if (i.dataset.f !== undefined) i.classList.toggle('dirty', i.value !== i.dataset.orig);
  });
  root.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && e.target.matches('[data-search]')) { e.preventDefault(); st.q = e.target.value.trim(); showLevel(st.level, 1); }
  });
  root.addEventListener('click', async (e) => {
    const el = e.target.closest('[data-nav],[data-open],[data-sub],[data-page],[data-a]');
    if (!el) return;
    e.preventDefault();
    const box = el.closest('[data-kind]');
    try {
      if (el.dataset.nav) {
        if (el.dataset.nav === 'topics') return showTopics();
        st.q = '';
        return showLevel(el.dataset.nav === 'hsk3' ? 3 : 2, 1);
      }
      if (el.dataset.open) { st.sub = 'words'; return showTopic(el.dataset.open); }
      if (el.dataset.sub) { st.sub = el.dataset.sub; return showTopic(st.topic.id); }
      if (el.dataset.page) return showLevel(st.level, +el.dataset.page);
      switch (el.dataset.a) {
        case 'save': return save(box);
        case 'force': return save(box, true);
        case 'search': st.q = root.querySelector('[data-search]').value.trim(); return showLevel(st.level, 1);
        case 'audio': return runAudio();
        case 'topic-toggle':
          await api('PATCH', 'content/topics/' + el.dataset.id, { is_active: el.dataset.on !== '1' });
          return showTopics();
        case 'sent-toggle': {
          const on = el.dataset.on !== '1';
          await api('PATCH', 'content/sentences/' + box.dataset.id, { is_active: on });
          box.classList.toggle('off', !on);
          el.dataset.on = on ? '1' : '0';
          el.textContent = on ? 'Ẩn câu' : 'Hiện lại';
          return refreshAudio();
        }
        case 'remove-word':
          if (!confirm('Bỏ từ này khỏi chủ đề? Câu của từ trong chủ đề cũng bị ẩn.')) return;
          await api('DELETE', `content/topics/${st.topic.id}/words/${el.dataset.word}`);
          return showTopic(st.topic.id);
        case 'more': return more(box, el.dataset.word);
        case 'add-picked': return addPicked(box, el.dataset.word);
        case 'example': return example(box.closest('[data-kind="word"]'));
        case 'use-example': {
          const w = box.closest('[data-kind="word"]');
          const r = await api('PUT', `content/words/${w.dataset.id}/example`, w.querySelector('.hk-suggest')._ex);
          ['example_zh', 'example_pinyin', 'example_vi', 'example_en'].forEach((f) => {
            const i = w.querySelector(`[data-f="${f}"]`);
            i.value = i.dataset.orig = r.word[f] || '';
            i.classList.remove('dirty');
          });
          w.querySelector('.hk-suggest').innerHTML = '';
          w.querySelector(':scope > .hk-actions .res').innerHTML = '<span class="ok">Đã đổi câu ví dụ ✓</span>';
          return refreshAudio();
        }
        case 'clear': el.closest('.hk-suggest').innerHTML = ''; return;
      }
    } catch (err) {
      alert(err.message);
    }
  });

  showTopics().then(refreshAudio).catch((e) => { root.innerHTML = `<div class="notice notice-error"><p>${esc(e.message)}</p></div>`; });
})();
