(() => {
  const root = document.getElementById('wrb-restore');
  if (!root) { return; }
  const form = root.querySelector('#wrb-r-form');
  const q = selector => root.querySelector(selector);
  const message = text => { q('[data-message]').textContent = text || ''; };
  let plan = null, job = null, poll = null, context = null, current = null;

  const element = (tag, className, text) => { const node = document.createElement(tag); if (className) { node.className = className; } if (text !== undefined) { node.textContent = text; } return node; };

  async function call(command, fields) {
    const body = new FormData();
    if (fields) { for (const [name, value] of fields) { body.append(name, value); } }
    body.append('command', command);
    body.append(root.dataset.tokenName, root.dataset.token);
    for (const [name, value] of Object.entries(root.dataset)) { if (name.startsWith('extra')) { body.append(name.slice(5).toLowerCase(), value); } }
    const response = await fetch(root.dataset.endpoint, { method: 'POST', credentials: 'same-origin', body, cache: 'no-store' });
    let data = null;
    try { data = await response.json(); } catch (error) { throw new Error('サーバーの応答を読み取れません。画面を開き直してください。'); }
    const payload = data.data !== undefined ? data.data : data;
    if (!response.ok || data.success === false) { const failure = new Error(payload.error || '処理を完了できませんでした。'); failure.field = payload.field; throw failure; }
    return payload;
  }

  function clearErrors() {
    root.querySelectorAll('[data-error-for]').forEach(node => { node.textContent = ''; });
    root.querySelectorAll('.wrb-r-invalid').forEach(node => { node.classList.remove('wrb-r-invalid'); node.removeAttribute('aria-invalid'); });
  }

  function focusField(field) {
    const input = form.elements[field];
    if (!input || !input.classList) { return; }
    const details = input.closest('details'); if (details) { details.open = true; }
    input.focus();
  }

  function showError(field, text, focus = true) {
    if (!field) { return; }
    const slot = root.querySelector('[data-error-for="' + field + '"]');
    if (slot && text) { slot.textContent = text; }
    const input = form.elements[field];
    if (input && input.classList) {
      input.classList.add('wrb-r-invalid'); input.setAttribute('aria-invalid', 'true');
      const details = input.closest('details'); if (details) { details.open = true; }
      if (focus) { input.focus(); }
    }
  }

  function applyVisibility() {
    root.querySelectorAll('[data-show-when]').forEach(block => {
      const [name, value] = block.dataset.showWhen.split('=');
      const checked = form.querySelector('input[name="' + name + '"]:checked');
      const visible = checked && checked.value === value;
      block.hidden = !visible;
      block.querySelectorAll('input,select,textarea').forEach(input => { input.disabled = !visible; });
    });
    const set = context && context.connections.find(item => item.id === form.elements.connection.value);
    const ready = !!(set && set.accountsRegistered && set.database);
    q('[data-connection-detail]').textContent = set ? (ready ? 'データベース ' + set.database + ' へ復元します。アカウントは準備済みです。' : '下でデータベース名とアカウントを入力してください。') : '';
    q('[data-connection-empty]').hidden = !context || context.connections.length > 0;
    const manual = q('[data-manual-accounts]');
    if (manual) { manual.hidden = ready || !set; manual.open = !!set && !ready; }
    const port = form.elements.previewPort.value;
    q('[data-preview-url]').textContent = 'http://127.0.0.1:' + (port || '…');
    updateNext();
  }

  function invalidate() {
    if (plan === null) { return; }
    plan = null; q('[data-confirm]').hidden = true; q('[data-checks]').replaceChildren();
    form.elements.confirmName.value = '';
    message('内容を変更しました。もう一度「内容を検査」を押してください。');
  }

  function fields() { const data = new FormData(form); data.delete('confirmName'); data.delete('reauthPassword'); return data; }

  function clearSecrets() {
    root.querySelectorAll('[data-secret]').forEach(input => { input.value = ''; });
    if (form.elements.reauthPassword) { form.elements.reauthPassword.value = ''; }
  }

  const stateText = { ok: '問題なし', failed: '要修正', warning: '注意', verified: '完了', pending: '待機中', running: '実行中' };

  function keyHint() {
    const value = form.elements.identity.value.trim();
    const slot = root.querySelector('[data-error-for="identity"]');
    if (value === '') { slot.textContent = ''; return; }
    if (value.startsWith('age1')) { slot.textContent = 'これは公開鍵です。AGE-SECRET-KEY-1 で始まる秘密鍵を入れてください。'; return; }
    slot.textContent = value.includes('AGE-SECRET-KEY-1') ? '' : 'AGE-SECRET-KEY-1 で始まる行が見つかりません。';
  }

  function updateNext() {
    const next = q('[data-next]');
    const set = text => { next.replaceChildren(element('strong', '', '次にすること'), element('span', '', text)); };
    const state = current ? current.state : 'none';
    if (['queued', 'running'].includes(state)) { set('完了までお待ちください。この画面を閉じても処理は続きます。'); return; }
    const choosingAnother = !!root.dataset.formRequested && !form.hidden;
    if (state === 'complete' && !choosingAnother) {
      const site = current.site || {};
      if (site.state === 'unavailable') { set('この環境では確認用サイトを起動できません。下の案内を確認してください。'); return; }
      if (site.openable) { set('確認用サイトを開き、' + (site.adminLogin || 'restore-admin') + ' でログインして確認してください。'); return; }
      if (['running', 'starting'].includes(site.state)) { set(site.state === 'starting' ? '確認用サイトの起動をお待ちください。' : '下の案内に沿って確認用サイトを開いてください。'); return; }
      if (site.state === 'failed') { set('確認用サイトをもう一度起動してください。'); return; }
      set('「確認用サイトを起動」を押してください。'); return;
    }
    if (q('[data-form-closed]') && !choosingAnother) { set('結果を確認してください。別の復元は「別の復元を始める」から行えます。'); return; }
    if (state === 'startup-failed' && !choosingAnother) { set('下の案内を確認し、確認用サイトの準備だけをやり直してください。'); return; }
    if (form.elements.snapshot.value === '') { set('復元するバックアップを選んでください。'); return; }
    if (form.elements.identity.value.trim() === '') { set('秘密鍵を入れてください。'); return; }
    if (form.elements.adminPassword.value.length < 12) { set('確認用ログインのパスワードを決めてください。'); return; }
    if (plan === null) { set('「内容を検査」を押してください。'); return; }
    set('内容を確かめ、復元の名前を入力して開始してください。');
  }

  async function preflight(button) {
    clearErrors(); keyHint(); message('検査しています…'); button.disabled = true; plan = null; q('[data-confirm]').hidden = true;
    try {
      const result = await call('preflight', fields());
      const area = q('[data-checks]'); area.replaceChildren();
      const failed = result.checks.filter(check => check.state === 'failed');
      const attention = result.checks.filter(check => check.state !== 'ok');
      const list = element('ul', 'wrb-r-checks');
      for (const check of attention) { list.append(checkRow(check)); }
      if (attention.length) { area.append(list); }
      const passed = result.checks.filter(check => check.state === 'ok');
      if (passed.length) {
        const details = element('details'); details.append(element('summary', '', passed.length + '項目は問題ありません'));
        const okList = element('ul', 'wrb-r-checks'); for (const check of passed) { okList.append(checkRow(check)); }
        details.append(okList); area.append(details);
      }
      failed.forEach((check, index) => { if (check.field) { showError(check.field, check.message, index === 0); } });
      if (result.ok) { plan = result.planHash; showSummary(result.summary, result.startup); message('確認の期限は15分です。'); }
      else { message('「要修正」の項目を直して、もう一度検査してください。'); }
    } catch (error) { message(error.message); showError(error.field, error.message); }
    finally { button.disabled = false; updateNext(); }
  }

  function checkRow(check) {
    const row = element('li');
    row.append(element('span', 'wrb-r-state wrb-r-state-' + check.state, stateText[check.state] || check.state));
    const detail = element('span'); detail.append(element('strong', '', check.label));
    if (check.message) { detail.append(element('span', 'wrb-r-help', check.message)); }
    if (check.state === 'failed' && check.field && form.elements[check.field]) { detail.append(actionButton('この項目を直す', 'wrb-r-link', () => focusField(check.field))); }
    row.append(detail);
    return row;
  }

  function showSummary(summary, startup) {
    const list = q('[data-summary]'); list.replaceChildren();
    const add = (term, value) => { list.append(element('dt', '', term)); list.append(element('dd', '', value)); };
    add('元のサイト', summary.sourceUrl);
    const captured = summary.capturedAt ? new Date(summary.capturedAt) : null;
    add('バックアップ', (captured && !Number.isNaN(captured.getTime()) ? captured.toLocaleString('ja-JP') + 'に取得' : '取得日時は不明') + '（' + summary.snapshot.slice(0, 8) + '…）');
    if (summary.consistency && summary.consistency.mode === 'online') {
      add('取得時の注意', '保守なしで取得しています。データベースとファイルは異なる時点の状態である可能性があります。');
      add('データベースの固定時刻', new Date(summary.consistency.snapshotAt).toLocaleString('ja-JP'));
      add('ファイルの最終確認時刻', new Date(summary.consistency.filesVerifiedAt).toLocaleString('ja-JP'));
    }
    add('件数', summary.counts.files + 'ファイル／' + summary.counts.tables + '表');
    add('メディア', summary.purpose === 'full' ? 'すべて配置する' : '中身を検証するだけ（配置しない）');
    add('保存先（新規）', summary.destination);
    add('データベース', summary.database.mode);
    add('元データ', summary.database.original);
    add('確認用コピー', summary.database.running);
    add('新しいサイトのアドレス', summary.targetUrl || summary.previewUrl);
    add('確認用アドレス', summary.previewUrl + '（この機械だけで開けます）');
    add('確認用ログイン', summary.adminLogin);
    if (!startup.available) { add('確認用サイト', startup.reason); }
    const name = summary.name;
    const label = form.querySelector('label[for="wrb-r-confirmName"]');
    label.textContent = '確認のため「' + name + '」と入力';
    q('[data-confirm]').hidden = false;
    form.elements.confirmName.value = '';
    form.elements.confirmName.focus();
  }

  async function start(button) {
    if (plan === null) { message('先に「内容を検査」を押してください。'); return; }
    clearErrors(); message('開始しています…'); button.disabled = true;
    const data = fields(); data.append('planHash', plan); data.append('confirmName', form.elements.confirmName.value);
    if (form.elements.reauthPassword) { data.append('reauthPassword', form.elements.reauthPassword.value); }
    try {
      const status = await call('start', data);
      clearSecrets();
      delete root.dataset.formRequested;
      plan = null; q('[data-confirm]').hidden = true; q('[data-checks]').replaceChildren();
      message(''); renderStatus(status); schedule();
      q('[data-status]').scrollIntoView({ block: 'nearest' });
    } catch (error) { message(error.message); showError(error.field, error.message); }
    finally { button.disabled = false; }
  }

  function setFormOpen(open) {
    form.hidden = !open;
    root.classList.toggle('wrb-r-running', !open);
    const old = q('[data-form-closed]');
    if (old) { old.remove(); }
  }

  function renderStatus(status) {
    current = status && status.state !== 'none' ? status : null;
    const panel = q('[data-status]');
    // Progress redraws every few seconds; a keyboard user must stay on the control they reached.
    const focused = panel.contains(document.activeElement) ? document.activeElement.textContent : null;
    panel.replaceChildren();
    if (!current) { job = null; setFormOpen(true); updateNext(); return; }
    job = status.name;
    const active = ['queued', 'running'].includes(status.state);
    const site = status.site || {};
    const labels = { queued: '開始を待っています', running: '復元しています', complete: '復元と照合が完了しました', failed: '復元できませんでした', 'startup-failed': '元データの復元は完了しましたが、確認用サイトを準備できませんでした', interrupted: '途中で中断しました', stopped: '依頼により停止しました' };
    panel.append(element('p', 'wrb-r-headline' + (['failed', 'startup-failed', 'interrupted'].includes(status.state) ? ' wrb-r-bad' : ''), labels[status.state] || status.state));
    panel.append(element('p', 'wrb-r-help', '復元の名前：' + status.name));
    if (active) {
      const seconds = Math.max(0, Math.floor(Date.now() / 1000) - (status.startedAt || 0));
      panel.append(element('p', 'wrb-r-note', status.phaseLabel + '（経過 ' + Math.floor(seconds / 60) + '分' + (seconds % 60) + '秒）'));
    }
    if (status.message) { panel.append(element('p', 'wrb-r-note', status.message)); }
    const results = element('ul', 'wrb-r-results');
    for (const item of Object.values(status.results || {})) {
      const row = element('li');
      row.append(element('span', 'wrb-r-state wrb-r-state-' + item.state, stateText[item.state] || item.state));
      let detail = item.label;
      if (typeof item.files === 'number') { detail += '（' + item.files.toLocaleString() + '件' + (item.placed === 0 ? '・配置0件' : '') + '）'; }
      row.append(element('span', '', detail)); results.append(row);
    }
    panel.append(results);
    const actions = element('div', 'wrb-r-actions');
    if (active) { actions.append(actionButton('処理を停止', 'wrb-r-danger', () => act('stop'))); }
    if (status.startup && status.startup.state === 'prepared') {
      const urls = element('dl', 'wrb-r-summary');
      const add = (term, value) => { urls.append(element('dt', '', term)); urls.append(element('dd', '', value)); };
      add('新しいサイトのアドレス', status.startup.storedUrl);
      add('確認用アドレス', status.startup.previewUrl);
      const siteLabels = { 'not-started': '未起動', starting: '起動中', running: site.response === 'responding' ? '動作中（応答あり）' : '動作中（応答を確認中）', stopped: '停止中', failed: '起動できませんでした', unavailable: 'この環境では起動できません' };
      add('確認用サイト', siteLabels[site.state] || site.state);
      if (site.adminLogin && site.state !== 'unavailable') { add('確認用ログイン', site.adminLogin); }
      panel.append(urls);
      if (status.startup.notCarriedOver && status.startup.notCarriedOver.length) { panel.append(element('p', 'wrb-r-note wrb-r-warn', '元の設定の ' + status.startup.notCarriedOver.join('、') + ' は引き継いでいません。独自の配置先は自動で対応しません。')); }
      if (site.state !== 'unavailable') {
        if (['not-started', 'stopped', 'failed'].includes(site.state)) { actions.append(actionButton('確認用サイトを起動（外部通信なし）', 'wrb-r-primary', () => act('site_start'))); }
        if (['running', 'starting'].includes(site.state)) { actions.append(actionButton('確認用サイトを停止', 'wrb-r-danger', () => act('site_stop'))); }
      }
      if (site.openable) {
        const link = element('a', 'wrb-r-button wrb-r-primary', '確認用サイトを開く'); link.href = site.previewUrl + '/wp-login.php'; link.target = '_blank'; link.rel = 'noopener noreferrer'; actions.prepend(link);
      }
    }
    if (site.reason) { panel.append(element('p', 'wrb-r-note wrb-r-warn', site.reason)); }
    if (site.guidance) { panel.append(element('p', 'wrb-r-help', site.guidance)); }
    if (status.state === 'startup-failed') { panel.append(element('p', 'wrb-r-help', '「詳細設定」の表の接頭辞と、確認用ログインのパスワードを入力して、準備だけをやり直せます。データベースは取り込み直しません。')); actions.append(actionButton('確認用サイトの準備をやり直す', '', retry)); }
    panel.append(actions);
    if (focused !== null) { const again = [...actions.children].find(node => node.textContent === focused); if (again) { again.focus(); } }
    const formNeeded = status.state === 'startup-failed';
    if (active || !formNeeded) {
      if (!root.dataset.formRequested || active) {
        setFormOpen(false);
        if (!active && !['running', 'starting'].includes(site.state)) {
          const again = element('p', '', ''); again.dataset.formClosed = '1';
          again.append(actionButton('別の復元を始める', '', newRestore));
          q('[data-status]').after(again);
        }
      }
    } else { setFormOpen(true); }
    updateNext();
    if (!active && !['running', 'starting'].includes(site.state) && poll) { clearInterval(poll); poll = null; }
  }

  async function newRestore() {
    root.dataset.formRequested = '1';
    setFormOpen(true);
    const latest = await call('context'); context = latest; fillSuggestions();
    updateNext(); form.elements.snapshot.focus();
  }

  function actionButton(label, className, handler) {
    const node = element('button', 'wrb-r-button ' + className, label); node.type = 'button';
    node.addEventListener('click', async () => { node.disabled = true; try { await handler(); } finally { node.disabled = false; } });
    return node;
  }

  async function act(command) {
    try { const data = new FormData(); data.append('job', job); renderStatus(await call(command, data)); schedule(); }
    catch (error) { message(error.message); }
  }

  async function retry() {
    const data = new FormData(); data.append('job', job); data.append('tablePrefix', form.elements.tablePrefix.value); data.append('adminPassword', form.elements.adminPassword.value);
    try { renderStatus(await call('startup_retry', data)); form.elements.adminPassword.value = ''; schedule(); }
    catch (error) { message(error.message); showError(error.field, error.message); }
  }

  function schedule() {
    if (poll) { return; }
    poll = setInterval(async () => {
      try { renderStatus(await call('status')); }
      catch (error) { message(error.message); clearInterval(poll); poll = null; }
    }, 3000);
  }

  function fillSuggestions() {
    if (context.suggestedName) { form.elements.name.value = context.suggestedName; }
    if (context.suggestedPort) { form.elements.previewPort.value = String(context.suggestedPort); }
    applyVisibility();
  }

  async function init() {
    try {
      context = await call('context');
      const select = form.elements.connection; select.replaceChildren();
      for (const set of context.connections) { const option = element('option', '', set.label); option.value = set.id; select.append(option); }
      fillSuggestions();
      renderStatus(context.latest);
      if (context.latest && (['queued', 'running'].includes(context.latest.state) || ['running', 'starting'].includes((context.latest.site || {}).state))) { schedule(); }
    } catch (error) { message(error.message); }
    try {
      const { snapshots } = await call('snapshots');
      const select = form.elements.snapshot; select.replaceChildren();
      const usable = snapshots.filter(item => item.valid);
      if (usable.length !== 1) { const placeholder = element('option', '', snapshots.length ? '選んでください' : '保存済みのバックアップがありません'); placeholder.value = ''; select.append(placeholder); }
      for (const item of snapshots) { const option = element('option', '', 'バックアップ ' + item.snapshotId.slice(0, 8) + '…' + (item.valid ? '' : '（検証に失敗）')); option.value = item.snapshotId; option.disabled = !item.valid; select.append(option); }
      updateNext();
    } catch (error) { message(error.message); }
  }

  root.querySelectorAll('[data-reveal]').forEach(input => {
    const toggle = element('button', 'wrb-r-link', '表示'); toggle.type = 'button'; toggle.setAttribute('aria-pressed', 'false'); toggle.setAttribute('aria-label', 'パスワードを表示');
    toggle.addEventListener('click', () => { const show = input.type === 'password'; input.type = show ? 'text' : 'password'; toggle.textContent = show ? '隠す' : '表示'; toggle.setAttribute('aria-pressed', String(show)); });
    input.after(toggle);
  });
  const keyFile = q('[data-key-file]');
  keyFile.addEventListener('change', async () => {
    const [file] = keyFile.files; keyFile.value = '';
    if (!file) { return; }
    if (file.size > 65536) { showError('identity', '鍵ファイルとしては大きすぎます。ファイルを確認してください。'); return; }
    form.elements.identity.value = await file.text();
    form.elements.identity.dispatchEvent(new Event('input', { bubbles: true }));
  });
  form.addEventListener('input', event => {
    if (event.target.name === 'identity') { keyHint(); }
    if (event.target.name && event.target.name !== 'confirmName' && event.target.name !== 'reauthPassword') { invalidate(); }
    applyVisibility();
  });
  form.addEventListener('submit', event => event.preventDefault());
  root.querySelector('[data-action="preflight"]').addEventListener('click', event => preflight(event.currentTarget));
  root.querySelector('[data-action="start"]').addEventListener('click', event => start(event.currentTarget));
  init();
})();
