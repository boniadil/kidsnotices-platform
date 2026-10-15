(() => {
  const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const fmt = (v) => Number(v).toLocaleString('fr-FR', { maximumFractionDigits: 2 });
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

  async function api(url, data) {
    const opts = data ? { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body: new URLSearchParams({ ...data, csrf: CSRF }) } : {};
    const res = await fetch(url, opts);
    const body = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(body.error || 'تعذّر الاتصال بالخادم.');
    return body;
  }

  // ---- Line chart (SVG). series: [{name, unit, points: [{year, value}]}]
  function chart(series) {
    series = (series || []).filter((s) => s.points && s.points.length > 1);
    if (!series.length) return null;
    // Drawn at the size it is shown, so labels stay readable on phones.
    const W = Math.max(300, Math.min(720, window.innerWidth - 72)), H = W < 500 ? 220 : 260, L = 58, R = 12, T = 14, B = 30;
    const years = [...new Set(series.flatMap((s) => s.points.map((p) => p.year)))].sort((a, b) => a - b);
    const vals = series.flatMap((s) => s.points.map((p) => p.value));
    let min = Math.min(0, ...vals), max = Math.max(...vals);
    if (max === min) max = min + 1;
    const x = (y) => L + (years.length === 1 ? 0 : (years.indexOf(y) / (years.length - 1)) * (W - L - R));
    const yv = (v) => T + (1 - (v - min) / (max - min)) * (H - T - B);
    const colors = ['var(--chart)', 'var(--chart2)', '#7a5bd1', '#1a9a8a'];
    let g = '';
    for (let i = 0; i <= 4; i++) {
      const v = min + (max - min) * (i / 4), yy = yv(v);
      g += `<line x1="${L}" x2="${W - R}" y1="${yy}" y2="${yy}" stroke="var(--border)"/><text x="${L - 8}" y="${yy + 4}" text-anchor="end" font-size="11" fill="var(--muted)">${esc(fmt(v))}</text>`;
    }
    const step = Math.ceil(years.length / 10);
    years.forEach((y, i) => { if (i % step === 0 || i === years.length - 1) g += `<text x="${x(y)}" y="${H - 8}" text-anchor="middle" font-size="11" fill="var(--muted)">${y}</text>`; });
    series.forEach((s, i) => {
      const pts = s.points.slice().sort((a, b) => a.year - b.year);
      g += `<polyline fill="none" stroke="${colors[i % 4]}" stroke-width="2.5" points="${pts.map((p) => `${x(p.year)},${yv(p.value)}`).join(' ')}"/>`;
      pts.forEach((p) => { g += `<circle cx="${x(p.year)}" cy="${yv(p.value)}" r="3.5" fill="${colors[i % 4]}"><title>${esc(s.name)} — ${p.year}: ${esc(fmt(p.value))} ${esc(s.unit)}</title></circle>`; });
    });
    const div = document.createElement('div');
    div.className = 'chart';
    div.innerHTML = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(series.map((s) => s.name).join('، '))}">${g}</svg>`
      + `<div class="legend">${series.map((s, i) => `<span><i style="background:${colors[i % 4]}"></i>${esc(s.name)}${s.unit ? ' (' + esc(s.unit) + ')' : ''}</span>`).join('')}</div>`;
    return div;
  }

  // ---- Dashboard: follow processing progress
  const reports = $('#reports');
  if (reports && reports.dataset.busy === '1') {
    const poll = setInterval(async () => {
      try {
        const rows = await api('/api/status');
        if (!rows.some((r) => !['ready', 'error'].includes(r.status))) { clearInterval(poll); location.reload(); return; }
        rows.forEach((r) => { const bar = $(`tr[data-id="${r.id}"] progress`); if (bar) bar.value = r.progress; });
      } catch (_) {}
    }, 4000);
  }

  // ---- Ask
  const askForm = $('#ask');
  if (askForm) {
    const chat = $('#chat');
    $$('.msg.bot[data-series]').forEach((m) => { const c = chart(JSON.parse(m.dataset.series || '[]')); if (c) m.append(c); });
    const send = async (question) => {
      $('.examples')?.remove();
      const u = document.createElement('div'); u.className = 'msg user'; u.dir = 'auto'; u.textContent = question; chat.append(u);
      const b = document.createElement('div'); b.className = 'msg bot'; b.innerHTML = '<div class="thinking"><div class="spinner"></div>أبحث في التقارير…</div>'; chat.append(b);
      b.scrollIntoView({ behavior: 'smooth', block: 'end' });
      askForm.querySelector('button').disabled = true;
      try {
        const r = await api('/api/ask', { question });
        b.innerHTML = `<div class="answer">${r.html}</div>`;
        const c = chart(r.series); if (c) b.append(c);
      } catch (e) {
        b.innerHTML = `<p class="err">${esc(e.message)}</p>`;
      } finally {
        askForm.querySelector('button').disabled = false;
      }
    };
    askForm.addEventListener('submit', (ev) => { ev.preventDefault(); const t = askForm.question; if (t.value.trim()) { send(t.value.trim()); t.value = ''; } });
    askForm.question.addEventListener('keydown', (ev) => { if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); askForm.requestSubmit(); } });
    $$('.examples .chip').forEach((c) => c.addEventListener('click', () => send(c.textContent)));
    $('#reset')?.addEventListener('click', async () => { await api('/api/ask/reset', {}); location.reload(); });
  }

  // ---- Statistics explorer
  const list = $('#ind-list');
  if (list) {
    const panel = $('#series-panel');
    let category = '', timer = null, current = null;
    const load = async () => {
      const q = $('#ind-search').value.trim();
      const items = await api('/api/indicators?' + new URLSearchParams({ q, category }));
      list.innerHTML = items.length ? '' : '<li class="muted">لا توجد نتائج.</li>';
      items.forEach((it) => {
        const li = document.createElement('li');
        const span = it.years.length ? `${it.years[0]}–${it.years[it.years.length - 1]} · ${it.years.length} سنوات` : '';
        li.innerHTML = `<span dir="auto">${esc(it.name_ar)}</span><small dir="auto">${esc(it.name_fr)}${it.unit ? ' · ' + esc(it.unit) : ''} · ${span}</small>`;
        li.addEventListener('click', () => { $$('li', list).forEach((x) => x.classList.remove('active')); li.classList.add('active'); show(it.id); });
        list.append(li);
      });
    };
    const show = async (id, from = '', to = '', scope = '') => {
      current = id;
      panel.innerHTML = '<div class="thinking"><div class="spinner"></div></div>';
      const s = await api('/api/series?' + new URLSearchParams({ id, from, to, scope }));
      const st = s.stats || {};
      const unit = s.indicator.unit ? ' ' + esc(s.indicator.unit) : '';
      const csv = '/stats/csv?' + new URLSearchParams({ id, from, to, scope });
      panel.innerHTML = `
        <h2 dir="auto">${esc(s.indicator.name_ar)}</h2><p class="muted" dir="auto">${esc(s.indicator.name_fr)}${unit ? ' · الوحدة:' + unit : ''}</p>
        <div class="filters">
          <label>من<input type="number" id="f-from" value="${esc(from)}"></label>
          <label>إلى<input type="number" id="f-to" value="${esc(to)}"></label>
          ${s.available_scopes.length > 1 ? `<label>النطاق<select id="f-scope">${s.available_scopes.map((x) => `<option value="${esc(x)}" ${x === s.scope ? 'selected' : ''}>${esc(x || 'المجموع')}</option>`).join('')}</select></label>` : ''}
          <button class="btn ghost" id="f-apply">تطبيق</button>
          <a class="btn ghost" href="${csv}">تصدير CSV (Excel)</a>
        </div>
        ${st.count ? `<div class="stat-tiles">
          <div><span>${st.first.year} → ${st.last.year}</span><b>${fmt(st.first.value)} → ${fmt(st.last.value)}</b></div>
          <div><span>التغير الإجمالي</span><b>${st.total_change_pct === null ? '—' : (st.total_change_pct > 0 ? '+' : '') + fmt(st.total_change_pct) + '%'}</b></div>
          <div><span>معدل النمو السنوي</span><b>${st.cagr_pct === null ? '—' : fmt(st.cagr_pct) + '%'}</b></div>
          <div><span>الأعلى / الأدنى</span><b>${st.max.year} / ${st.min.year}</b></div>
        </div>` : '<p class="muted">لا توجد قيم في هذه الفترة.</p>'}
        ${st.missing_years && st.missing_years.length ? `<p class="hint warn">سنوات بدون معطى: ${st.missing_years.join('، ')}</p>` : ''}
        <div id="chart-slot"></div>
        <div class="table-wrap"><table><thead><tr><th>السنة</th><th>القيمة</th><th>التغير</th><th>المصدر</th></tr></thead><tbody>
        ${s.points.map((p, i) => {
          const ch = i ? st.year_on_year[i - 1] : null;
          const other = p.other_values.length ? `<div class="hint warn">قيم أخرى: ${p.other_values.map((o) => `${fmt(o.value)} (تقرير ${o.report_year})`).join('، ')}</div>` : '';
          const ref = p.source.ref.match(/R(\d+):p(\d+)/);
          return `<tr><td class="num">${p.year}</td><td class="num">${fmt(p.value)}</td><td class="num">${ch && ch.change_pct !== null ? (ch.change_pct > 0 ? '+' : '') + fmt(ch.change_pct) + '%' : '—'}</td>
            <td><a class="cite" href="/source?report=${ref[1]}&page=${ref[2]}" target="_blank">${p.source.report_year} · ص ${p.source.page}</a>${p.source.verified ? '' : ' <span class="hint warn" title="لم يتم العثور على الرقم حرفياً في الصفحة">⚠ للتحقق</span>'}
            <div class="hint" dir="auto">${esc(p.source.quote)}</div>${other}</td></tr>`;
        }).join('')}
        </tbody></table></div>`;
      const c = chart([{ name: s.indicator.name_ar, unit: s.indicator.unit, points: s.points }]);
      if (c) $('#chart-slot').append(c);
      $('#f-apply').addEventListener('click', () => show(current, $('#f-from').value, $('#f-to').value, $('#f-scope')?.value || ''));
    };
    $('#ind-search').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(load, 300); });
    $$('.cats .chip').forEach((c) => c.addEventListener('click', () => { $$('.cats .chip').forEach((x) => x.classList.remove('active')); c.classList.add('active'); category = c.dataset.cat; load(); }));
    load();
  }

  // ---- Write: form switch and progress
  const writeForm = $('#write-form');
  if (writeForm) {
    const sync = () => { const k = writeForm.kind.value; $$('[data-kind]', writeForm).forEach((el) => { el.hidden = el.dataset.kind !== k; }); };
    $$('input[name="kind"]', writeForm).forEach((r) => r.addEventListener('change', sync));
    sync();
  }
  const job = $('#job-status');
  if (job && !['ready', 'error'].includes(job.dataset.status)) {
    const poll = setInterval(async () => {
      try { const s = await api('/api/write?id=' + job.dataset.id); if (['ready', 'error'].includes(s.status)) { clearInterval(poll); location.reload(); } } catch (_) {}
    }, 5000);
  }
})();
