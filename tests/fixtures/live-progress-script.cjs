'use strict';
// Runs assets/live.js against a minimal fake DOM, scripted fetch responses and manual timers. No network, server,
// WordPress or restore. Usage: node live-progress-script.cjs <plugin directory> <scenario>
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');

class FakeNode {
    constructor(tag) {
        this.tagName = tag.toUpperCase(); this.children = []; this.attributes = {}; this.dataset = {}; this._text = null;
        this.className = ''; this.disabled = false; this.hidden = false; this.listeners = {}; this.focusCalls = 0;
        this.classList = { toggle: (name, on) => { const set = new Set(this.className.split(' ').filter(Boolean)); (on === undefined ? !set.has(name) : on) ? set.add(name) : set.delete(name); this.className = [...set].join(' '); },
            add: name => this.classList.toggle(name, true), contains: name => this.className.split(' ').includes(name) };
    }
    append(...nodes) { if (this._text !== null) { this.children = [{ textContent: this._text }]; this._text = null; } nodes.forEach(node => this.children.push(typeof node === 'string' ? { textContent: node } : node)); }
    replaceChildren(...nodes) { this.children = []; this._text = null; this.append(...nodes); }
    set textContent(value) { this.children = []; this._text = String(value); }
    get textContent() { return this._text !== null ? this._text : this.children.map(child => child.textContent).join(''); }
    setAttribute(name, value) { this.attributes[name] = String(value); if (name === 'id') this.id = String(value); }
    getAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null; }
    hasAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attributes, name); }
    addEventListener(type, listener) { (this.listeners[type] = this.listeners[type] || []).push(listener); }
    closest() { return null; }
    focus() { this.focusCalls += 1; focusCalls += 1; }
    querySelector() { return null; }
    querySelectorAll() { return []; }
}
let focusCalls = 0;
const walk = (node, found = []) => { if (node instanceof FakeNode) { found.push(node); node.children.forEach(child => walk(child, found)); } return found; };

function page(state) {
    const byId = {};
    const make = (tag, id) => { const node = new FakeNode(tag); if (id) { node.setAttribute('id', id); byId[id] = node; } return node; };
    const progress = make('section', 'wrb-progress'); progress.dataset.wrbState = JSON.stringify(state);
    const manualMode = make('input'); manualMode.value = 'maintenance';
    const writers = make('input', 'wrb-writers'), downtime = make('input', 'wrb-downtime'), onlineAck = make('input', 'wrb-online-ack');
    const maintenanceConfirm = make('div'), onlineConfirm = make('div');
    ['wrb-live-status', 'wrb-live-checked', 'wrb-live-detail', 'wrb-live-announce'].forEach(id => make(id === 'wrb-live-announce' ? 'p' : 'div', id));
    const start = make('button'); start.dataset.wrbCommand = 'start'; start.dataset.wrbReady = '1';
    const step = make('button'); step.dataset.wrbCommand = 'step';
    // The server-rendered cancel block; its visibility matches the server rule for the initial state.
    const cancel = make('div'); const cancelSubmit = make('input'); cancelSubmit.setAttribute('type', 'submit'); cancel.append(cancelSubmit);
    cancel.hidden = !(state && ['capturing', 'uploading', 'failed'].includes(state.status)); cancelSubmit.disabled = cancel.hidden;
    cancel.querySelector = selector => (selector.includes('[type="submit"]') ? cancelSubmit : null);
    const documentListeners = {};
    const document = {
        visibilityState: 'visible',
        getElementById: id => byId[id] || null,
        createElement: tag => new FakeNode(tag),
        querySelector: selector => ({'[data-wrb-cancel]': cancel, '[name="wrb_manual_mode"]:checked': manualMode, '[data-wrb-maintenance-confirm]': maintenanceConfirm, '[data-wrb-online-confirm]': onlineConfirm}[selector] || null),
        querySelectorAll: selector => (selector === '[data-wrb-command]' ? [start, step] : (selector === '[name="wrb_manual_mode"]' ? [manualMode] : [])),
        addEventListener: (type, listener) => { (documentListeners[type] = documentListeners[type] || []).push(listener); },
        dispatch: type => (documentListeners[type] || []).forEach(listener => listener()),
    };
    return { byId, start, step, cancel, cancelSubmit, manualMode, writers, downtime, onlineAck, maintenanceConfirm, onlineConfirm, document };
}

function harness(initialState, responses) {
    const view = page(initialState);
    const timers = []; let nextId = 1; const commands = [], requests = [];
    const context = {
        document: view.document, URLSearchParams, Date, JSON, Math, Number, String, Object, Array, Set, WeakMap, Promise, Error, console,
        setTimeout: (fn, delay) => { const id = nextId++; timers.push({ id, fn, delay }); return id; },
        clearTimeout: id => { const index = timers.findIndex(timer => timer.id === id); if (index >= 0) timers.splice(index, 1); },
        fetch: async (url, init) => {
            requests.push(new URLSearchParams(init.body)); commands.push(requests[requests.length-1].get('command'));
            const next = responses.shift(); if (!next) throw new Error('No scripted response');
            if (next.network) throw new TypeError('network');
            return { status: next.status, ok: next.status >= 200 && next.status < 300, json: async () => { if (next.body === undefined) throw new SyntaxError('not json'); return next.body; } };
        },
    };
    context.window = context; context.wrbLive = { url: '/wp-admin/admin-ajax.php', nonce: 'fixture' };
    vm.createContext(context);
    vm.runInContext(fs.readFileSync(process.argv[2] + '/assets/live.js', 'utf8'), context);
    const settle = async () => { for (let i = 0; i < 20; i += 1) await new Promise(resolve => setImmediate(resolve)); };
    return {
        view, timers, commands, requests, settle,
        pendingDelays: () => timers.map(timer => timer.delay),
        fire: async () => { assert.strictEqual(timers.length, 1, 'exactly one pending check'); const timer = timers.shift(); timer.fn(); await settle(); },
        text: () => walk(view.byId['wrb-live-detail']).map(node => node.textContent).join('\n') + view.byId['wrb-live-status'].textContent,
        steps: () => walk(view.byId['wrb-live-detail']).filter(node => node.tagName === 'LI').map(node => node.className.replace('wrb-step wrb-step-', '')),
        announced: () => view.byId['wrb-live-announce'].textContent,
        find: tag => walk(view.byId['wrb-live-detail']).filter(node => node.tagName === tag.toUpperCase()),
    };
}
const ok = body => ({ status: 200, body: { success: true, data: body } });
const uploading = { status: 'uploading', message: '転送中', completed: 3, total: 10, startedAt: '2026-11-10T03:02:44Z' };
const complete = { status: 'complete', message: '完了しました。', completed: 10, total: 10, startedAt: '2026-11-10T03:02:44Z', completedAt: '2026-11-10T04:00:00Z' };

const scenarios = {
    // A completed page keeps checking at a low rate, so a run started elsewhere appears and polling speeds up.
    async 'idle-detects-new-run'() {
        const h = harness(complete, [ok(uploading)]); await h.settle();
        assert.deepStrictEqual(h.pendingDelays(), [60000], 'a finished page still checks every 60 seconds');
        assert.deepStrictEqual(h.commands, [], 'nothing is requested before the first check');
        await h.fire();
        assert.deepStrictEqual(h.commands, ['state']);
        assert.deepStrictEqual(h.steps(), ['done', 'done', 'current', 'pending', 'pending']);
        assert.strictEqual(h.announced(), '暗号文の転送と照合が始まりました。');
        assert.deepStrictEqual(h.pendingDelays(), [5000]);
        assert.strictEqual(h.view.step.disabled, false); assert.strictEqual(h.view.start.disabled, true);
    },
    // Returning to the tab always rechecks at once, whatever the last state was; a hidden tab keeps no timer.
    async 'visible-rechecks'() {
        const h = harness(complete, [ok(complete)]); await h.settle();
        h.view.document.visibilityState = 'hidden'; h.view.document.dispatch('visibilitychange');
        assert.deepStrictEqual(h.pendingDelays(), [], 'no checks while hidden');
        h.view.document.visibilityState = 'visible'; h.view.document.dispatch('visibilitychange');
        assert.deepStrictEqual(h.pendingDelays(), [0], 'an immediate check on return');
        await h.fire();
        assert.deepStrictEqual(h.commands, ['state']);
        assert.strictEqual(h.announced(), '', 'an unchanged state is not announced again');
        assert.deepStrictEqual(h.pendingDelays(), [60000]);
    },
    // A 503 or a network failure means the state is unknown: start and resume stay disabled even if the last state
    // was uploading, the step is marked as not confirmable, and they return only after a successful check.
    async 'unreachable-disables'() {
        const h = harness(uploading, [{ status: 503 }, { network: true }, ok(uploading)]); await h.settle();
        assert.deepStrictEqual(h.pendingDelays(), [0]);
        assert.strictEqual(h.view.step.disabled, false, 'resume is available from the confirmed server-rendered state');
        await h.fire();
        assert.strictEqual(h.view.step.disabled, true, 'resume is disabled while the state cannot be confirmed');
        assert.strictEqual(h.view.start.disabled, true, 'start is disabled while the state cannot be confirmed');
        assert.deepStrictEqual(h.steps(), ['done', 'done', 'unknown', 'pending', 'pending']);
        assert.ok(h.text().includes('確認できません'));
        assert.ok(!h.text().includes('続いています'), 'never claims the run continues');
        assert.strictEqual(h.announced(), '現在の状態を確認できなくなりました。開始と再開は確認できるまで無効にしています。');
        assert.deepStrictEqual(h.pendingDelays(), [15000]);
        await h.fire();
        assert.strictEqual(h.view.step.disabled, true, 'a network failure is also unconfirmed');
        await h.fire();
        assert.strictEqual(h.view.step.disabled, false, 'resume returns once the state is confirmed');
        assert.strictEqual(h.announced(), '状態を再び確認できるようになりました。');
        assert.deepStrictEqual(h.steps(), ['done', 'done', 'current', 'pending', 'pending']);
    },
    // On a finished page start is available, but not once the state cannot be confirmed (another run may have begun).
    async 'unreachable-idle-disables-start'() {
        const h = harness(complete, [{ status: 503 }, ok(complete)]); await h.settle();
        assert.strictEqual(h.view.start.disabled, false, 'start is available from the confirmed finished state');
        await h.fire();
        assert.strictEqual(h.view.start.disabled, true, 'start is disabled while the state cannot be confirmed');
        assert.strictEqual(h.view.step.disabled, true);
        assert.deepStrictEqual(h.pendingDelays(), [15000]);
        await h.fire();
        assert.strictEqual(h.view.start.disabled, false, 'start returns once the state is confirmed');
        assert.deepStrictEqual(h.pendingDelays(), [60000]);
    },
    // The cancel form follows the confirmed state: shown for a stopped unfinished run, hidden and disabled when the
    // state cannot be confirmed or the run has finished, matching what a fresh page load renders.
    async 'cancel-follows-state'() {
        const failed = { status: 'failed', message: '停止しました。', completed: 3, total: 10 };
        const h = harness(uploading, [{ status: 503 }, ok(failed), ok(complete), ok(uploading)]); await h.settle();
        const shown = () => [h.view.cancel.hidden, h.view.cancelSubmit.disabled];
        assert.deepStrictEqual(shown(), [false, false], 'an unfinished run can be cancelled');
        await h.fire();
        assert.deepStrictEqual(shown(), [true, true], 'hidden and disabled while the state cannot be confirmed');
        await h.fire();
        assert.deepStrictEqual(shown(), [false, false], 'a failed run can be cancelled');
        await h.fire();
        assert.deepStrictEqual(shown(), [true, true], 'nothing to cancel once complete');
        await h.fire();
        assert.deepStrictEqual(shown(), [false, false], 'a new unfinished run shows it again');
        const idle = harness(complete, []); await idle.settle();
        assert.strictEqual(idle.view.cancel.hidden, true); assert.strictEqual(idle.view.cancelSubmit.disabled, true);
    },
    // The transfer row for existing packs does not claim that R2 is never read: a legacy job still reads them in full.
    async 'measurement-labels'() {
        const measured = { ...complete, measurements: { version: 1, transfer: { sharedPacks: { ms: 40, steps: 1, objects: 8, bytes: 1000 } } } };
        const h = harness(measured, []); await h.settle();
        assert.ok(h.text().includes('既存パックの転送時確認'));
        assert.ok(!h.text().includes('R2は読まない'));
    },
    // Known totals use a labelled native progress element with value and max; while capturing the total is unknown,
    // so the bar has no value, and no percentage appears anywhere.
    async 'progress-semantics'() {
        const capturing = { status: 'capturing', message: '取得中', completed: 0, total: 0, progress: { phase: 'capture', files: 5 } };
        const h = harness(uploading, [ok(uploading), ok(capturing)]); await h.settle(); await h.fire();
        let bars = h.find('progress');
        assert.strictEqual(bars.length, 1);
        assert.strictEqual(bars[0].getAttribute('max'), '10'); assert.strictEqual(bars[0].getAttribute('value'), '3');
        const labels = h.find('label').filter(label => label.getAttribute('for') === bars[0].getAttribute('id'));
        assert.strictEqual(labels.length, 1, 'the bar has its own label');
        assert.ok(!h.text().includes('%'));
        await h.fire();
        bars = h.find('progress');
        assert.strictEqual(bars.length, 1);
        assert.strictEqual(bars[0].hasAttribute('value'), false, 'indeterminate while the total is unknown');
        assert.strictEqual(bars[0].hasAttribute('max'), false);
        assert.strictEqual(h.find('label').filter(label => label.getAttribute('for') === bars[0].getAttribute('id')).length, 1);
        assert.ok(!h.text().includes('%'));
    },
    // Completion is announced in the polite status region without moving the focus, and polling slows down.
    async 'completion-announced-without-focus'() {
        const h = harness(uploading, [ok(complete)]); await h.settle(); await h.fire();
        assert.strictEqual(h.announced(), 'バックアップが完了しました。');
        assert.strictEqual(focusCalls, 0, 'no focus is moved');
        assert.deepStrictEqual(h.steps(), ['done', 'done', 'done', 'done', 'done']);
        assert.deepStrictEqual(h.pendingDelays(), [60000]);
        assert.strictEqual(h.view.step.disabled, true); assert.strictEqual(h.view.start.disabled, false);
    },
    // An expired nonce or missing permission stops polling and says so, without inventing a state.
    async 'forbidden-stops'() {
        const h = harness(uploading, [{ status: 403 }]); await h.settle(); await h.fire();
        assert.ok(h.view.byId['wrb-live-status'].textContent.includes('再読み込み'));
        assert.strictEqual(h.view.start.disabled, true);
        assert.strictEqual(h.view.step.disabled, true, 'stale upload state must not enable resume');
        assert.deepStrictEqual(h.pendingDelays(), [], 'no further checks until the page is reloaded or shown again');
    },
    async 'command-error-disables'() {
        const h = harness(uploading, [{ status: 403 }]); await h.settle();
        h.view.step.listeners.click[0](); await h.settle();
        assert.deepStrictEqual(h.commands, ['step']);
        assert.strictEqual(h.view.step.disabled, true, 'a failed command must not leave stale resume available');
        assert.strictEqual(h.view.start.disabled, true);
        assert.deepStrictEqual(h.pendingDelays(), [15000]);
    },
    async 'online-requires-ack'() {
        const h = harness(complete, [ok({status:'queued', consistencyMode:'online'})]); await h.settle();
        h.view.manualMode.value = 'online'; h.view.manualMode.listeners.change[0]();
        assert.strictEqual(h.view.maintenanceConfirm.hidden, true);
        assert.strictEqual(h.view.onlineConfirm.hidden, false);
        h.view.start.listeners.click[0](); await h.settle();
        assert.deepStrictEqual(h.commands, [], 'no start without explicit risk acknowledgment');
        h.view.onlineAck.checked = true; h.view.start.listeners.click[0](); await h.settle();
        assert.deepStrictEqual(h.commands, ['start']);
        assert.strictEqual(h.requests[0].get('mode'), 'online');
        assert.strictEqual(h.requests[0].get('onlineAck'), '1');
        assert.ok(h.text().includes('保守なし'));
        assert.ok(!h.text().includes('取得（サイトは保守状態）'));
    },
};

const name = process.argv[3];
if (!scenarios[name]) { console.error('unknown scenario'); process.exit(2); }
scenarios[name]().then(() => { process.stdout.write('ok ' + name + '\n'); }, error => { console.error(error && error.stack || error); process.exit(1); });
