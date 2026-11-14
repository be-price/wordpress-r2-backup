(() => {
    'use strict';
    document.querySelectorAll('[data-wrb-presets]').forEach(presets => {
        const form = presets.closest('form');
        const rules = form?.querySelector('[data-wrb-optional-rules]');
        if (!rules) return;
        const update = () => { rules.disabled = presets.querySelector('input[name="preset"]:checked')?.value === 'full'; };
        presets.addEventListener('change', update);
        update();
    });
    document.querySelectorAll('[data-wrb-rule-type]').forEach(selector => {
        const form = selector.closest('form');
        if (!form) return;
        const groups = Array.from(form.querySelectorAll('[data-wrb-rule-kind]'));
        const disabled = new WeakMap();
        groups.forEach(group => group.querySelectorAll('input, select').forEach(input => disabled.set(input, input.disabled)));
        const update = () => groups.forEach(group => {
            const shown = group.dataset.wrbRuleKind.split(' ').includes(selector.value);
            group.hidden = !shown;
            group.querySelectorAll('input, select').forEach(input => { input.disabled = !shown || disabled.get(input); });
        });
        selector.addEventListener('change', update);
        update();
    });
    const panel = document.getElementById('wrb-live-status');
    if (!panel || !window.wrbLive) return;
    const progressPanel = document.getElementById('wrb-progress');
    const detail = document.getElementById('wrb-live-detail');
    const checked = document.getElementById('wrb-live-checked');
    const ACTIVE = ['queued', 'capturing', 'uploading'];
    const STATUS_LABELS = { queued: '開始待ち', capturing: '取得中', uploading: '転送・照合中', complete: '完了', failed: '失敗', cancelled: '取り消し済み' };
    let running = false;
    const buttons = Array.from(document.querySelectorAll('[data-wrb-command]'));
    const media = document.querySelector('[data-wrb-media]');
    const mediaButton = document.querySelector('[data-wrb-media-check]');
    const mediaStatus = document.getElementById('wrb-media-status');
    const mediaAck = document.getElementById('wrb-media-ack');
    const mediaAckWrap = document.querySelector('[data-wrb-media-ack]');
    const mode = () => document.querySelector('[name="wrb_manual_mode"]:checked')?.value || 'maintenance';
    const maintenanceConfirm = document.querySelector('[data-wrb-maintenance-confirm]');
    const onlineConfirm = document.querySelector('[data-wrb-online-confirm]');
    const updateMode = () => {
        if (maintenanceConfirm) maintenanceConfirm.hidden = mode() === 'online';
        if (onlineConfirm) onlineConfirm.hidden = mode() !== 'online';
    };
    document.querySelectorAll('[name="wrb_manual_mode"]').forEach(input => input.addEventListener('change', updateMode));
    updateMode();
    const controls = [...buttons, ...(mediaButton ? [mediaButton] : [])];
    const disabled = new WeakMap(controls.map(button => [button, button.disabled]));
    let lastState = null;
    // reachable: whether the last attempt to read the state succeeded. Without it, start and resume stay disabled.
    let lastReach = { reachable: true, checkedAt: null, problem: '' };
    try { lastState = JSON.parse(progressPanel?.dataset.wrbState || 'null'); } catch (_) { lastState = null; }
    const isActive = state => !!state && ACTIVE.includes(state.status);
    // The cancel form is shown only for a stopped, unfinished run in a confirmed state: the rule the server render uses.
    const cancel = document.querySelector('[data-wrb-cancel]');
    const cancelSubmit = cancel?.querySelector('input[type="submit"], button[type="submit"]');
    const CANCELLABLE = ['capturing', 'uploading', 'failed'];
    const updateControls = () => {
        if (cancel) {
            const show = lastReach.reachable && !!lastState && CANCELLABLE.includes(lastState.status);
            cancel.hidden = !show;
            if (cancelSubmit) cancelSubmit.disabled = !show || running;
        }
        buttons.forEach(button => {
            if (button.dataset.wrbCommand === 'start') { button.disabled = running || !lastReach.reachable || isActive(lastState) || button.dataset.wrbReady !== '1'; }
            else if (button.dataset.wrbCommand === 'step') { button.disabled = running || !lastReach.reachable || lastState?.status !== 'uploading'; }
            else { button.disabled = running || disabled.get(button); }
        });
        if (mediaButton) mediaButton.disabled = running || disabled.get(mediaButton);
    };
    const setRunning = value => { running = value; updateControls(); };
    // Announcements go to one polite status region, only when something changes; nothing here moves the focus.
    const announcer = document.getElementById('wrb-live-announce');
    const announce = message => { if (announcer && message) { announcer.textContent = ''; announcer.textContent = message; } };
    const display = (message) => { panel.textContent = message; announce(message); };

    // Formatting: only numbers the server reported; nothing here estimates remaining time or an overall percentage.
    const count = value => Math.max(0, Number(value) || 0).toLocaleString('ja-JP');
    const bytes = value => {
        let size = Math.max(0, Number(value) || 0); const units = ['バイト', 'KB', 'MB', 'GB', 'TB']; let unit = 0;
        while (size >= 1024 && unit < units.length - 1) { size /= 1024; unit += 1; }
        return unit === 0 ? `${count(size)} バイト` : `${size.toLocaleString('ja-JP', { maximumFractionDigits: 1 })} ${units[unit]}`;
    };
    const duration = value => {
        const seconds = Math.round(Math.max(0, Number(value) || 0) / 1000);
        const h = Math.floor(seconds / 3600), m = Math.floor(seconds % 3600 / 60), s = seconds % 60;
        if (seconds === 0 && Number(value) > 0) return '1秒未満';
        return (h ? `${h}時間` : '') + (h || m ? `${m}分` : '') + `${s}秒`;
    };
    const when = iso => {
        if (typeof iso !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(iso)) return null;
        const date = new Date(iso); return Number.isNaN(date.getTime()) ? null : date;
    };
    const clock = date => date.toLocaleString('ja-JP', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const el = (tag, options = {}, children = []) => {
        const node = document.createElement(tag);
        if (options.className) node.className = options.className;
        if (options.text !== undefined) node.textContent = options.text;
        Object.entries(options.attrs || {}).forEach(([name, value]) => node.setAttribute(name, value));
        children.filter(Boolean).forEach(child => node.append(child));
        return node;
    };
    const sharingSummary = sharing => {
        if (!sharing || typeof sharing !== 'object') return '';
        return [['newBytes', 'newPacks', '新規保存（詰め直しを含む）'], ['reusedBytes', 'reusedPacks', '再利用'], ['repackedBytes', 'repackedPacks', '除外境界の詰め直し']]
            .map(([size, packs, label]) => `${label} ${count(sharing[size])} バイト / ${count(sharing[packs])} パック`).join('　');
    };

    const STEPS = [['start', '開始の受付'], ['capture', '取得（サイトは保守状態）'], ['transfer', '暗号文の転送と照合'], ['publish', '公開前後の全量照合'], ['done', '完了']];
    const STEP_LABELS = { done: '完了', current: '実行中', pending: '未着手', failed: '停止', unknown: '確認できません' };
    function stepStates(state, reachable) {
        const result = Object.fromEntries(STEPS.map(([key]) => [key, 'pending']));
        if (!state) return result;
        const total = Number(state.total) || 0, completed = Number(state.completed) || 0;
        const atPublish = total > 0 && completed >= total - 1;
        let current;
        if (state.status === 'queued') current = 'start';
        else if (state.status === 'capturing') current = 'capture';
        else if (state.status === 'uploading') current = atPublish ? 'publish' : 'transfer';
        else if (state.status === 'complete') { STEPS.forEach(([key]) => { result[key] = 'done'; }); return result; }
        else current = total > 0 ? (atPublish ? 'publish' : 'transfer') : 'capture';
        for (const [key] of STEPS) {
            if (key === current) { result[key] = ['failed', 'cancelled'].includes(state.status) ? 'failed' : (reachable ? 'current' : 'unknown'); break; }
            result[key] = 'done';
        }
        return result;
    }
    const PHASES = { prepare: '除外判定の準備', scan: 'ファイルの走査と内容の確認', 'sharing-preflight': '重複判定のためのDB事前読み出し',
        capture: '保存用のDB読み出し・ファイル保存・最終照合' };
    function currentDetail(key, state, reachableNow) {
        const lines = [];
        if (key === 'capture') {
            const progress = state.progress || {};
            if (PHASES[progress.phase]) lines.push(`段階：${PHASES[progress.phase]}`);
            const counters = [['entries', '確認したエントリー', count], ['selected', '保存対象', count], ['tableCount', 'テーブル', count], ['databaseBytes', 'DB読み出し', bytes],
                ['files', '保存したファイル', count], ['fileBytes', '保存したファイル容量', bytes]].filter(([name]) => progress[name] !== undefined)
                .map(([name, label, format]) => `${label} ${format(progress[name])}`);
            if (counters.length) lines.push(counters.join('　'));
            if (reachableNow) {
                // The total is not known until the capture ends, so the bar has no value and no percentage is made up.
                lines.push(el('label', { attrs: { for: 'wrb-capture-progress' }, text: '取得の進行（総数が確定しないため割合は表示しません）' }));
                lines.push(el('progress', { attrs: { id: 'wrb-capture-progress' }, text: '取得中' }));
            }
            const updated = when(progress.updatedAt);
            if (updated) lines.push(`進捗の記録 ${clock(updated)}（記録は約15秒ごと。全体件数との比較はこの段階ではできません）`);
        } else if (key === 'transfer' || key === 'publish') {
            const total = Number(state.total) || 0, completed = Number(state.completed) || 0;
            if (total > 0) {
                lines.push(`転送済み ${count(completed)} / ${count(total)} 項目（最後の1項目は公開と照合）`);
                lines.push(el('label', { attrs: { for: 'wrb-transfer-progress' }, text: '転送済みの項目数（容量や所要時間の割合ではありません）' }));
                lines.push(el('progress', { attrs: { id: 'wrb-transfer-progress', max: String(total), value: String(Math.min(completed, total)) }, text: `${count(completed)} / ${count(total)} 項目` }));
                lines.push('項目ごとに容量が異なるため、この割合から残り時間は推定できません。');
            }
            if (key === 'publish') lines.push('公開前の全量照合、完了記録の作成、公開後の全量照合を行っています。');
        } else if (key === 'start') {
            lines.push('バックグラウンド処理の起動を待っています。');
        }
        return lines;
    }
    const CAPTURE_ROWS = [['policyContextMs', '除外判定の準備'], ['scanMs', 'ファイルの走査と内容の確認（上の準備を含む）', m => `${count(m.scanFiles)} ファイル / ${bytes(m.scanFileBytes)}`],
        ['lockWaitMs', 'DBの保護・一貫読み取りの準備'], ['databasePrepassMs', '重複判定のためのDB事前読み出し', m => bytes(m.databasePrepassBytes)],
        ['filesPrepassMs', 'ファイルID収集', m => `${count(m.filesPrepassFiles)} ファイル / ${bytes(m.filesPrepassBytes)}`],
        ['databaseCaptureMs', '保存用のDB読み出し（暗号化を含む）', m => bytes(m.databaseCaptureBytes)],
        ['filesCaptureMs', 'ファイル保存（暗号化を含む）', m => `${count(m.filesCaptureFiles)} ファイル / ${bytes(m.filesCaptureBytes)}`],
        ['filesFinalMs', '最終照合（全ファイルの再読み込み）', m => `${count(m.filesFinalEntries)} エントリー`],
        ['sharedVerifyMs', '既存パックのR2照合（取得中、上の工程と重複）', m => `${count(m.sharedVerifyPacks)} パック / ${bytes(m.sharedVerifyBytes)}`],
        ['encryptMs', '暗号化の合計（上の工程と重複）', m => `${count(m.encryptCalls)} 回 / ${bytes(m.encryptPlainBytes)}`],
        ['lockHeldMs', 'DBの保護・一貫読み取りの合計'], ['finishMs', '取得後の残りの暗号化'], ['totalMs', '取得全体', m => `${count(m.objects)} 項目`]];
    const TRANSFER_ROWS = [['sharedPacks', '既存パックの転送時確認'], ['pages', 'ページの送信と照合'], ['newPacks', '新規パックの送信と照合'], ['otherObjects', 'その他の送信と照合']];
    function measurementTable(measurements) {
        const rows = [];
        const capture = measurements?.capture || {};
        CAPTURE_ROWS.forEach(([name, label, extra]) => {
            if (capture[name] === undefined) return;
            rows.push([label, duration(capture[name]), extra ? extra(capture) : '']);
        });
        const transfer = measurements?.transfer || {};
        TRANSFER_ROWS.forEach(([kind, label]) => {
            const entry = transfer[kind]; if (!entry) return;
            rows.push([label, duration(entry.ms), `${count(entry.objects)} 項目 / ${bytes(entry.bytes)} / ${count(entry.steps)} 回`]);
        });
        const publication = transfer.publication;
        if (publication) {
            rows.push(['目録の暗号化と送信', duration(publication.rootMs), '']);
            rows.push(['公開前の全量照合と完了記録', duration(publication.publishMs), `${count(publication.objects)} 項目 / ${bytes(publication.bytes)}`]);
            rows.push(['公開後の全量照合', duration(publication.completedCheckMs), '']);
        }
        if (!rows.length) return null;
        const body = el('tbody', {}, rows.map(([label, time, extra]) => el('tr', {}, [el('th', { text: label, attrs: { scope: 'row' } }), el('td', { text: time }), el('td', { text: extra })])));
        const table = el('table', { className: 'widefat striped wrb-measure-table' }, [
            el('caption', { text: '転送の値は、成功して保存できたステップだけを足し上げています。失敗した試行の時間は含みません。' }),
            el('thead', {}, [el('tr', {}, [el('th', { text: '工程', attrs: { scope: 'col' } }), el('th', { text: '経過時間', attrs: { scope: 'col' } }), el('th', { text: '件数・容量', attrs: { scope: 'col' } })])]), body]);
        return el('details', { className: 'wrb-measure', attrs: isActive(lastState) ? {} : { open: '' } }, [el('summary', { text: '工程ごとの計測（経過時間と件数のみ）' }), el('div', { className: 'wrb-table-wrap' }, [table])]);
    }

    // reach: {reachable, checkedAt, problem} describes the last attempt to read the state, separately from the state itself.
    function render(state, reach) {
        const reachable = reach.reachable;
        const message = state ? (state.message || '') : 'まだ実行していません。';
        const summary = [el('p', { text: message })];
        if (state) {
            summary.push(el('p', { text: `状態 ${STATUS_LABELS[state.status] || '不明'}　転送 ${count(state.completed)} / ${count(state.total)} 項目` }));
            if (state.consistencyMode === 'online') summary.push(el('p', { text: '取得方法：保守なし（DBとファイルの取得時点に差がある場合があります）' }));
            const started = when(state.startedAt), finished = when(state.completedAt);
            if (started) summary.push(el('p', { className: 'wrb-muted', text: `開始 ${clock(started)}` + (finished ? `　完了 ${clock(finished)}（所要 ${duration(finished - started)}）` : '') }));
            const sharing = sharingSummary(state.sharing); if (sharing) summary.push(el('p', { text: sharing }));
        }
        panel.replaceChildren(...summary);
        if (checked) {
            checked.textContent = reach.checkedAt ? `最終確認 ${clock(reach.checkedAt)}` + (isActive(state) && reachable ? '（自動で更新しています）' : '') : 'この表示は画面を開いた時点の記録です。';
        }
        if (!detail) return;
        const parts = [];
        if (!reachable) {
            parts.push(el('div', { className: 'wrb-alert wrb-unreachable', attrs: { role: 'alert' } }, [el('p', { text: reach.problem })]));
        }
        const states = stepStates(state, reachable);
        const list = el('ol', { className: 'wrb-steps' }, STEPS.map(([key, label]) => {
            if (key === 'capture' && state?.consistencyMode === 'online') label = '取得（保守なし）';
            const status = states[key];
            const item = el('li', { className: `wrb-step wrb-step-${status}`, attrs: status === 'current' ? { 'aria-current': 'step' } : {} },
                [el('span', { className: 'wrb-step-label', text: label }), el('span', { className: 'wrb-step-status', text: STEP_LABELS[status] })]);
            if (state && ['current', 'unknown', 'failed'].includes(status)) {
                const lines = currentDetail(key, state, status === 'current');
                if (!reachable && status === 'unknown') lines.unshift('以下は最後に確認できた時点の記録です。');
                if (lines.length) item.append(el('div', { className: 'wrb-step-detail' }, lines.map(line => typeof line === 'string' ? el('p', { text: line }) : line)));
            }
            return item;
        }));
        parts.push(el('h3', { text: '工程' }), list);
        const table = measurementTable(state?.measurements);
        if (table) parts.push(table);
        detail.replaceChildren(...parts);
    }

    class Unreachable extends Error {}
    async function send(command, extra = {}) {
        const body = new URLSearchParams({ action: 'wrb_live', command, nonce: wrbLive.nonce,
            writers: document.getElementById('wrb-writers')?.checked ? '1' : '0',
            downtime: document.getElementById('wrb-downtime')?.checked ? '1' : '0', mode: mode(),
            onlineAck: document.getElementById('wrb-online-ack')?.checked ? '1' : '0', ...extra });
        let response;
        try { response = await fetch(wrbLive.url, { method: 'POST', credentials: 'same-origin', body }); }
        catch (_) { throw new Unreachable('サーバーに接続できません。通信状態を確認しています。処理の状態はこの画面から確認できていません。'); }
        // WordPress answers 503 while the site is in maintenance, which is also how it answers other temporary failures.
        if (response.status === 503) throw new Unreachable('サイトが保守状態（取得中の可能性があります）か、一時的に応答できないため、現在の状態を確認できません。処理が続いているかどうかは、この画面からは分かりません。応答が戻りしだい自動で表示します。');
        let result;
        try { result = await response.json(); } catch (_) {
            if (response.status === 403) throw new Error('画面の有効期限が切れたか、権限を確認できません。画面を再読み込みしてください。');
            throw new Unreachable('サーバーの応答を読み取れないため、現在の状態を確認できません。応答が戻りしだい自動で表示します。');
        }
        if (!response.ok || !result.success) throw new Error(result.data?.message || (command === 'media' ? '画像ファイルの確認を完了できませんでした。' : 'バックアップ処理を完了できませんでした。'));
        return result.data;
    }

    // Active runs every 5 seconds, unreachable every 15, otherwise every 60, so a run started elsewhere still appears.
    const ACTIVE_DELAY = 5000, UNREACHABLE_DELAY = 15000, IDLE_DELAY = 60000;
    const nextDelay = () => (!lastReach.reachable ? UNREACHABLE_DELAY : (isActive(lastState) ? ACTIVE_DELAY : IDLE_DELAY));
    const transition = (before, beforeReach, after, afterReach) => {
        if (beforeReach.reachable && !afterReach.reachable) return '現在の状態を確認できなくなりました。開始と再開は確認できるまで無効にしています。';
        if (!beforeReach.reachable && afterReach.reachable && !after) return '状態を再び確認できるようになりました。';
        if (!after || before?.status === after.status) return !beforeReach.reachable && afterReach.reachable ? '状態を再び確認できるようになりました。' : '';
        return { queued: 'バックアップの開始を受け付けました。', capturing: 'バックアップの取得が始まりました。', uploading: '暗号文の転送と照合が始まりました。',
            complete: 'バックアップが完了しました。', failed: 'バックアップは完了せずに停止しました。', cancelled: '未完了の実行は取り消されました。' }[after.status] || '';
    };
    let timer = null, polling = false;
    function schedule(delay) {
        clearTimeout(timer); timer = null;
        if (document.visibilityState === 'hidden') return;
        timer = setTimeout(poll, delay);
    }
    async function poll() {
        if (polling || running) { schedule(ACTIVE_DELAY); return; }
        polling = true;
        const before = lastState, beforeReach = lastReach;
        try {
            const state = await send('state');
            lastState = state && typeof state === 'object' ? state : null;
            lastReach = { reachable: true, checkedAt: new Date(), problem: '' };
        } catch (error) {
            if (!(error instanceof Unreachable)) {
                // Permission or expired nonce means the last state is no longer confirmed. Disable actions until reload.
                lastReach = { reachable: false, checkedAt: lastReach.checkedAt, problem: error.message };
                render(lastState, lastReach); updateControls(); display(error.message); return;
            }
            lastReach = { reachable: false, checkedAt: lastReach.checkedAt, problem: error.message };
        } finally { polling = false; }
        render(lastState, lastReach); updateControls();
        announce(transition(before, beforeReach, lastState, lastReach));
        schedule(nextDelay());
    }
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') schedule(0);
        else { clearTimeout(timer); timer = null; }
    });
    render(lastState, lastReach); updateControls();
    schedule(isActive(lastState) ? 0 : IDLE_DELAY);

    function showMedia(report) {
        if (!media || !mediaStatus || typeof report?.done !== 'boolean' || !Array.isArray(report.lines)) {
            throw new Error('画像ファイルの確認が未完了です。保守状態の解除後にもう一度確認してください。');
        }
        const paragraphs = report.lines.map(line => {
            const paragraph = document.createElement('p'); paragraph.textContent = line; return paragraph;
        });
        mediaStatus.replaceChildren(...paragraphs);
        mediaStatus.classList.toggle('wrb-alert', !!report.warning);
        media.dataset.wrbMediaDone = report.done ? '1' : '0';
        media.dataset.wrbMediaWarning = report.warning ? '1' : '0';
        media.dataset.wrbMediaCheckedAt = String(report.checkedAt || 0);
        if (mediaAckWrap) mediaAckWrap.hidden = !report.warning;
    }
    async function checkMedia() {
        if (mediaAck) mediaAck.checked = false;
        if (media) media.dataset.wrbMediaDone = '0';
        if (mediaStatus) mediaStatus.textContent = '公開画像の登録ファイルを確認しています。';
        let report = await send('media', { restart: '1' });
        showMedia(report);
        while (!report.done) {
            await new Promise(resolve => setTimeout(resolve, 100));
            report = await send('media', { run: report.runId });
            showMedia(report);
        }
        return report;
    }
    function mediaFailure(error) {
        if (media) media.dataset.wrbMediaDone = '0';
        if (mediaStatus) { mediaStatus.textContent = error.message + ' 検査は未完了です。バックアップは開始していません。'; mediaStatus.classList.add('wrb-alert'); }
        if (mediaAckWrap) mediaAckWrap.hidden = false;
        mediaStatus?.focus();
    }
    mediaButton?.addEventListener('click', async () => {
        if (running) return;
        setRunning(true);
        try { await checkMedia(); mediaStatus?.focus(); }
        catch (error) { mediaFailure(error); }
        finally { setRunning(false); }
    });
    async function run(command) {
        if (running) return;
        if (command === 'start' && mode() === 'maintenance' && (!document.getElementById('wrb-writers')?.checked || !document.getElementById('wrb-downtime')?.checked)) {
            display('書き手の停止と一時的な保守状態を確認してください。'); return;
        }
        if (command === 'start' && mode() === 'online' && !document.getElementById('wrb-online-ack')?.checked) {
            display('保守なし取得でDBとファイルの時点差が残る可能性を確認してください。'); return;
        }
        setRunning(true);
        if (command === 'start' && media && !mediaAck?.checked) {
            try {
                const checkedAt = Number(media.dataset.wrbMediaCheckedAt || 0);
                const fresh = media.dataset.wrbMediaDone === '1' && checkedAt > Date.now() / 1000 - 3600;
                if (!fresh) await checkMedia();
                if (media.dataset.wrbMediaWarning === '1') {
                    if (mediaAckWrap) mediaAckWrap.hidden = false;
                    display('画像ファイルに不足または未確認の注意があります。上の確認結果を読み、続行する場合はチェックを入れてください。バックアップはまだ開始していません。');
                    mediaStatus?.focus();
                    setRunning(false); return;
                }
            } catch (error) { mediaFailure(error); display('画像確認を完了できませんでした。バックアップはまだ開始していません。'); setRunning(false); return; }
        }
        display(command === 'start' ? (mode() === 'online' ? '保守なしバックアップの開始を依頼しています。' : 'バックアップの開始を依頼しています。取得中はWordPressが一時的に保守状態になり、その間この画面から状態を確認できません。') : '暗号文の転送の再開を依頼しています。');
        try {
            const state = await send(command);
            lastState = state && typeof state === 'object' ? state : lastState;
            lastReach = { reachable: true, checkedAt: new Date(), problem: '' };
        } catch (error) {
            lastReach = { reachable: false, checkedAt: lastReach.checkedAt, problem: error.message };
            display(error.message);
        }
        setRunning(false);
        render(lastState, lastReach);
        schedule(lastReach.reachable ? 4000 : UNREACHABLE_DELAY);
    }
    buttons.forEach(button => { button.addEventListener('click', () => run(button.dataset.wrbCommand)); });
})();
