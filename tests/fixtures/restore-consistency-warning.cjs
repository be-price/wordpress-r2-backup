'use strict';
// Exercise the production confirmation renderer against a tiny local DOM. No fetch or restore.
const fs = require('fs'), vm = require('vm'), assert = require('assert');
const source = fs.readFileSync(process.argv[2] + '/assets/restore.js', 'utf8');
const start = source.indexOf('  function showSummary('), end = source.indexOf('\n  async function start(', start);
assert.ok(start >= 0 && end > start, 'production renderer must exist');
const nodes = [];
const list = { replaceChildren() { nodes.length = 0; }, append(node) { nodes.push(node); } };
const confirm = { hidden: true }, label = { textContent: '' }, confirmName = { value: '', focus() {} };
const context = { Date, Number, element: (tag, className, text) => ({ tag, textContent: text }), q: selector => selector === '[data-summary]' ? list : confirm,
  form: { querySelector: () => label, elements: { confirmName } } };
vm.createContext(context); vm.runInContext(source.slice(start, end), context);
const summary = { sourceUrl: 'https://example.test', capturedAt: '2026-10-30T00:00:03Z', snapshot: '0123456789', counts: { files: 1, tables: 1 }, purpose: 'local', destination: '/tmp/example', database: { mode: 'SQLite', original: 'a', running: 'b' }, targetUrl: '', previewUrl: 'http://127.0.0.1:1234', adminLogin: 'test', name: 'sample' };
context.showSummary(summary, { available: true });
assert.ok(!nodes.some(node => String(node.textContent).includes('異なる時点')));
context.showSummary({ ...summary, consistency: { mode: 'online', snapshotAt: '2026-10-30T00:00:00Z', filesVerifiedAt: '2026-10-30T00:00:02Z' } }, { available: true });
assert.ok(nodes.some(node => String(node.textContent).includes('異なる時点')), 'online confirmation must warn about time difference');
assert.strictEqual(confirm.hidden, false);
process.stdout.write('ok\n');
