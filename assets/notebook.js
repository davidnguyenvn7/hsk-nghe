/*
 * HSK Nghe — Sổ tay: câu / từ đã lưu, chia theo bài. Mỗi bài nghe được như một bài nghe thường.
 * HSKNNotebook.mount(el, C)  C = {analyze, onPlay(trackId), onAnalyze(text), onLogin(msg), onLookup()}
 * Cần me.js (HSKNMe, HSKNSpeak) và analyze.js (HSKNAnalyze.post / toItems) nạp trước.
 */
(function () {
  'use strict';
  if (window.HSKNNotebook) return;

  const ICON = {
    speak: 'M3 9v6h4l5 5V4L7 9H3zm13.5 3A4.5 4.5 0 0 0 14 7.97v8.05A4.47 4.47 0 0 0 16.5 12zM14 3.23v2.06a7 7 0 0 1 0 13.42v2.06A9 9 0 0 0 14 3.23z',
    play: 'M8 5.14v13.72a1 1 0 0 0 1.5.86l11-6.86a1 1 0 0 0 0-1.72l-11-6.86A1 1 0 0 0 8 5.14z',
    lens: 'M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z',
    del: 'M6 19a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z',
    more: 'M12 8a2 2 0 1 0 0-4 2 2 0 0 0 0 4zm0 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm0 6a2 2 0 1 0 0 4 2 2 0 0 0 0-4z',
    eye: 'M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17a5 5 0 1 1 0-10 5 5 0 0 1 0 10zm0-8a3 3 0 1 0 0 6 3 3 0 0 0 0-6z',
    add: 'M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6z',
    close: 'M6.4 5 5 6.4 10.6 12 5 17.6 6.4 19l5.6-5.6 5.6 5.6 1.4-1.4-5.6-5.6L19 6.4 17.6 5 12 10.6z',
  };
  const svg = (n) => `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="${ICON[n]}"/></svg>`;
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const Me = window.HSKNMe;
  const Speak = window.HSKNSpeak;
  const MAX_LINES = 20;

  function count(l) {
    const p = [];
    if (l.words) p.push(`${l.words} từ`);
    if (l.sentences) p.push(`${l.sentences} câu`);
    return p.join(' · ') || 'trống';
  }

  function mount(root, C) {
    const st = { list: 0, items: [], loading: false, hide: false, error: '' };
    root.classList.add('hk-nb');

    // hộp thoại tạo bài (dùng cả từ menu "+ Tạo")
    const dlg = document.createElement('dialog');
    dlg.className = 'hk-dlg hk-dlg-sm hk-dlg-create';
    dlg.setAttribute('aria-label', 'Tạo bài nghe mới');
    root.after(dlg);

    function render() {
      if (!Me.enabled) {
        root.innerHTML = `<div class="hk-nb-empty">
            <div class="hk-nb-art" aria-hidden="true">记</div>
            <h3>Sổ tay của bạn</h3>
            <p>Lưu câu, từ khi tra để học lại, và tự tạo bài nghe từ chúng. Đăng nhập một lần, nhớ 1 năm.</p>
            <button type="button" class="hk-btn primary" data-nb="login">Đăng nhập</button></div>`;
        return;
      }
      if (!Me.loaded) { root.innerHTML = `<div class="hk-empty">${esc(st.error || 'Đang tải sổ tay…')}</div>`; return; }
      if (!Me.find(st.list)) st.list = Me.listId;
      const l = Me.find(st.list);
      const chips = Me.lists.map((x) => `<button type="button" class="hk-nb-chip" data-list="${x.id}" aria-pressed="${x.id === st.list}">
          ${esc(x.name)}<small>${x.words + x.sentences}</small></button>`).join('');
      const items = st.loading ? '<div class="hk-empty">Đang tải…</div>' : st.items.length ? st.items.map(itemHtml).join('') : `
          <div class="hk-nb-empty small"><p>Bài này chưa có câu, từ nào.</p>
            <button type="button" class="hk-btn" data-nb="lookup">${svg('lens')} Tra câu để thêm</button></div>`;
      root.innerHTML = `
        <div class="hk-nb-lists" role="tablist">${chips}
          <button type="button" class="hk-nb-chip is-add" data-nb="create">${svg('add')} Bài mới</button></div>
        ${l ? `<div class="hk-nb-head">
          <div class="hk-nb-title"><b>${esc(l.name)}</b><span>${count(l)}</span></div>
          <button type="button" class="hk-btn primary" data-nb="play" ${l.words + l.sentences ? '' : 'disabled'}>${svg('play')} Nghe bài này</button>
          <button type="button" class="hk-icon" data-nb="hide" aria-pressed="${st.hide}" title="Ẩn nghĩa để tự kiểm tra" aria-label="Ẩn nghĩa">${svg('eye')}</button>
          <button type="button" class="hk-icon" data-nb="menu" title="Đổi tên / xoá bài" aria-label="Tuỳ chọn bài">${svg('more')}</button>
        </div>` : ''}
        <div class="hk-nb-items${st.hide ? ' is-quiz' : ''}">${items}</div>`;
    }

    function itemHtml(it) {
      const d = it.data || {};
      return `<article class="hk-nb-item" data-id="${it.id}">
          <div class="hk-nb-main">
            <div class="hk-nb-zh ${it.kind === 'word' ? 'is-word' : ''}">${esc(it.zh)}</div>
            <div class="hk-nb-py">${esc(it.pinyin)}${d.han_viet ? ` <span class="hk-nb-hv">${esc(d.han_viet)}</span>` : ''}</div>
            <button type="button" class="hk-nb-vi" data-nb="reveal">${d.pos ? `<span class="hk-an-pos">${esc(d.pos)}</span>` : ''}${esc(it.vi)}</button>
          </div>
          <div class="hk-nb-acts">
            <button type="button" class="hk-an-spk" data-nb="speak" aria-label="Đọc">${svg('speak')}</button>
            <button type="button" class="hk-icon" data-nb="analyze" aria-label="Phân tích" title="Phân tích lại">${svg('lens')}</button>
            <button type="button" class="hk-icon" data-nb="delete" aria-label="Xoá" title="Xoá khỏi bài">${svg('del')}</button>
          </div></article>`;
    }

    async function openList(id) {
      st.list = +id;
      Me.listId = st.list;   // lưu tiếp từ Tra câu sẽ vào bài đang xem
      st.loading = true;
      render();
      try {
        st.items = (await Me.items(st.list)).items;
      } catch (e) {
        st.items = [];
        flash(e.message);
      }
      st.loading = false;
      render();
    }

    function flash(msg) {
      let el = document.querySelector('.hk-nb-toast');
      if (!el) { el = document.createElement('div'); el.className = 'hk-nb-toast hk-an-toast'; el.setAttribute('role', 'status'); root.appendChild(el); }
      el.textContent = msg;
      el.classList.add('is-show');
      clearTimeout(el._t);
      el._t = setTimeout(() => el.classList.remove('is-show'), 3500);
    }

    // ------------------------------------------------------------ tạo bài nghe mới
    function openCreate() {
      if (!Me.enabled) { C.onLogin && C.onLogin('Đăng nhập để tạo bài nghe của riêng bạn.'); return; }
      dlg.innerHTML = `<form class="hk-dlg-form" method="dialog">
          <div class="hk-dlg-head"><strong>Tạo bài nghe mới</strong>
            <button type="button" class="hk-icon" data-c="close" aria-label="Đóng">${svg('close')}</button></div>
          <div class="hk-dlg-body">
            <label class="hk-f"><span>Tên bài</span><input name="name" required maxlength="80" placeholder="Ví dụ: Đi xem nhà, Gọi món…"></label>
            <label class="hk-f"><span>Câu / từ muốn học <small>mỗi dòng một câu hoặc một từ, tiếng Trung hay tiếng Việt — tối đa ${MAX_LINES} dòng. Có thể để trống rồi thêm dần từ “Tra câu”.</small></span>
              <textarea name="lines" rows="7" placeholder="我想看看这套房子&#10;Tiền đặt cọc là bao nhiêu?&#10;合同"></textarea></label>
            <div class="hk-c-progress" hidden><div class="hk-c-bar"><i></i></div><div class="hk-c-msg"></div></div>
            <ul class="hk-c-errors"></ul>
          </div>
          <div class="hk-dlg-foot"><button type="button" class="hk-btn" data-c="close">Huỷ</button>
            <button type="submit" class="hk-btn primary" data-c="go">Tạo bài</button></div></form>`;
      if (typeof dlg.showModal === 'function') dlg.showModal(); else dlg.setAttribute('open', '');
      setTimeout(() => dlg.querySelector('input').focus(), 30);
    }

    let creating = false;
    async function create(form) {
      if (creating) return;
      const name = form.name.value.trim();
      const lines = [...new Set(form.lines.value.split(/\n+/).map((s) => s.trim()).filter(Boolean))];
      if (!name) { form.name.focus(); return; }
      if (lines.length > MAX_LINES) { flashIn(`Tối đa ${MAX_LINES} dòng mỗi lần (đang có ${lines.length}).`); return; }
      creating = true;
      const go = form.querySelector('[data-c="go"]');
      go.disabled = true;
      const prog = form.querySelector('.hk-c-progress');
      const bar = prog.querySelector('i');
      const msg = prog.querySelector('.hk-c-msg');
      const errs = form.querySelector('.hk-c-errors');
      errs.innerHTML = '';
      let list;
      try {
        list = await Me.createList(name);
      } catch (e) { flashIn(e.message); creating = false; go.disabled = false; return; }
      prog.hidden = !lines.length;
      let ok = 0;
      for (let i = 0; i < lines.length; i++) {
        msg.textContent = `Đang phân tích ${i + 1}/${lines.length}: ${lines[i]}`;
        bar.style.width = `${(i / lines.length) * 100}%`;
        try {
          if (!C.analyze) throw new Error('Phần phân tích câu đang tắt.');
          const d = await window.HSKNAnalyze.post(C.analyze, '', { text: lines[i] });
          await Me.save(window.HSKNAnalyze.toItems(d).main, list.id);
          ok++;
        } catch (e) {
          errs.insertAdjacentHTML('beforeend', `<li><b>${esc(lines[i])}</b>: ${esc(e.message)}</li>`);
          if (e.code === 'hskn_limit') break;
        }
      }
      bar.style.width = '100%';
      msg.textContent = lines.length ? `Xong: ${ok}/${lines.length} dòng đã vào bài “${list.name}”.` : '';
      creating = false;
      await Me.load(true).catch(() => {});
      openList(list.id);
      C.onCreated && C.onCreated(list);
      if (!errs.children.length) {
        dlg.close();
        flash(ok ? `Đã tạo bài “${list.name}” (${ok} mục).` : `Đã tạo bài “${list.name}”. Vào Tra câu để thêm câu, từ.`);
        if (ok && C.onPlay) C.onPlay('my-' + list.id, true);
      } else {
        go.textContent = 'Xong';
        go.disabled = false;
        go.dataset.c = 'close';
        go.type = 'button';
      }
    }
    function flashIn(text) {
      const errs = dlg.querySelector('.hk-c-errors');
      if (errs) errs.innerHTML = `<li>${esc(text)}</li>`;
    }
    dlg.addEventListener('submit', (e) => { e.preventDefault(); create(e.target); });
    dlg.addEventListener('click', (e) => {
      if (e.target === dlg && !creating) { dlg.close(); return; }
      const b = e.target.closest('[data-c="close"]');
      if (b && !creating) dlg.close();
    });

    // ------------------------------------------------------------ menu bài: đổi tên / xoá
    async function listMenu() {
      const l = Me.find(st.list);
      if (!l) return;
      const name = window.prompt('Đổi tên bài (để trống rồi OK = xoá bài này):', l.name);
      if (name === null) return;
      try {
        if (name.trim() === '') {
          if (!window.confirm(`Xoá bài “${l.name}” cùng ${l.words + l.sentences} mục trong đó?`)) return;
          await Me.deleteList(l.id);
          st.list = Me.listId;
          flash('Đã xoá bài.');
          openList(st.list);
        } else if (name.trim() !== l.name) {
          await Me.renameList(l.id, name);
        }
      } catch (e) { flash(e.message); }
    }

    // ------------------------------------------------------------ sự kiện
    root.addEventListener('click', async (e) => {
      const b = e.target.closest('button');
      if (!b || !root.contains(b)) return;
      if (b.dataset.list) { openList(b.dataset.list); return; }
      const card = b.closest('.hk-nb-item');
      const item = card && st.items.find((x) => x.id === +card.dataset.id);
      switch (b.dataset.nb) {
        case 'login': C.onLogin && C.onLogin(); break;
        case 'lookup': C.onLookup && C.onLookup(); break;
        case 'create': openCreate(); break;
        case 'play': C.onPlay && C.onPlay('my-' + st.list, true); break;
        case 'hide': st.hide = !st.hide; render(); break;
        case 'menu': listMenu(); break;
        case 'reveal': card.classList.toggle('is-shown'); break;
        case 'speak':
          C.onSpeak && C.onSpeak();
          Speak.play(b, { url: item.audio, text: item.zh, rate: item.kind === 'word' ? 'w' : 'n' }).catch((er) => flash(er.message));
          break;
        case 'analyze': C.onAnalyze && C.onAnalyze((item.data && item.data.input) || item.zh); break;
        case 'delete':
          b.disabled = true;
          try {
            await Me.remove(item);
            st.items = st.items.filter((x) => x !== item);
            render();
          } catch (er) { b.disabled = false; flash(er.message); }
          break;
      }
    });

    let first = true;
    Me.on(() => {
      // lưu thêm từ Tra câu vào bài đang xem → tải lại danh sách mục khi quay lại tab này
      if (first || !root.offsetParent) { st.stale = true; render(); return; }
      render();
    });

    return {
      show() {
        if (!Me.enabled) { render(); return; }
        if (!Me.loaded) {
          render();
          Me.load().then(() => { first = false; openList(Me.listId); }).catch((e) => { st.error = e.message; render(); });
        } else if (st.stale || first || !st.items.length) {
          st.stale = false;
          first = false;
          openList(st.list || Me.listId);
        } else render();
      },
      create: openCreate,
    };
  }

  window.HSKNNotebook = { mount };
})();
