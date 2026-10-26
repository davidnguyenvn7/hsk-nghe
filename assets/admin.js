/* Trang Import: chạy import theo từng bước qua REST, hiển thị tiến độ. */
(function () {
  const C = window.HSKN_ADMIN;
  const $ = (s) => document.querySelector(s);
  const bar = $('#hskn-bar'), phase = $('#hskn-phase'), log = $('#hskn-log');
  const PHASES = { content: 'Ghi nội dung', clips: 'Copy clip audio', audio: 'Ghi bảng audio', finalize: 'Hoàn tất', done: 'Xong' };
  let running = false;

  async function api(method, path = '', body) {
    const res = await fetch(C.rest + path, {
      method, headers: { 'X-WP-Nonce': C.nonce, 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined, credentials: 'same-origin',
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || res.statusText);
    return data;
  }

  function show(job) {
    if (!job) return;
    const pct = job.phase === 'done' ? 100
      : job.phase === 'clips' && job.total ? 10 + Math.round(80 * job.offset / job.total)
      : { content: 5, audio: 92, finalize: 97 }[job.phase] || 0;
    bar.value = pct;
    const clips = job.total ? ` · clip ${job.offset}/${job.total}` : '';
    phase.textContent = `Gói ${job.version}: ${PHASES[job.phase] || job.phase}${job.phase === 'clips' ? clips : ''}${job.busy ? ' (đang bận, thử lại…)' : ''}`;
    log.textContent = (job.log || []).join('\n');
    log.scrollTop = log.scrollHeight;
    $('#hskn-resume').disabled = job.phase === 'done' || running;
    $('#hskn-cancel').disabled = job.phase === 'done';
  }

  async function loop() {
    if (running) return;
    running = true;
    try {
      for (;;) {
        const { job } = await api('POST', '/step');
        show(job);
        if (job.phase === 'done') break;
        if (job.busy) await new Promise((r) => setTimeout(r, 1500));
      }
    } catch (e) {
      phase.textContent = 'Lỗi: ' + e.message + ' — bấm "Chạy tiếp" để thử lại.';
    } finally {
      running = false;
      $('#hskn-resume').disabled = false;
    }
  }

  document.addEventListener('click', async (e) => {
    const t = e.target;
    const skip = $('#hskn-skip-audio').checked;
    try {
      if (t.dataset.hsknDry) {
        e.preventDefault();
        phase.textContent = 'Đang kiểm tra…';
        const { report } = await api('POST', '', { source: t.dataset.hsknDry, skip_audio: skip, dry_run: true });
        const ch = Object.entries(report.changes).map(([k, v]) => `${k}: mới ${v.new}, đổi ${v.changed}, giữ ${v.unchanged}, ngừng dùng ${v.removed}`);
        phase.textContent = `Gói ${report.version} hợp lệ.`;
        log.textContent = ch.join('\n') + `\nclip: ${report.clips}`;
      } else if (t.dataset.hsknImport) {
        e.preventDefault();
        if (!confirm('Import gói ' + t.dataset.hsknImport + '?')) return;
        const { job } = await api('POST', '', { source: t.dataset.hsknImport, skip_audio: skip });
        show(job);
        loop();
      } else if (t.id === 'hskn-resume') {
        e.preventDefault();
        loop();
      } else if (t.id === 'hskn-cancel') {
        e.preventDefault();
        if (!confirm('Huỷ lần import này? Nội dung đã ghi vẫn giữ nguyên.')) return;
        await api('DELETE');
        phase.textContent = 'Đã huỷ.';
      }
    } catch (err) {
      phase.textContent = 'Lỗi';
      log.textContent = err.message;
    }
  });
})();
