const HEX = /^[a-f0-9]{64}$(?![\s\S])/;
const LOGICAL = /^(?:(?:files|packs|pages)\/[a-f0-9]{64}\/[a-f0-9]{64}|(?:databases|manifests)\/[a-f0-9]{64})\.age$(?![\s\S])/;
const META_BYTES = 16 * 1024 * 1024;
const MAX_OBJECT_BYTES = 128 * 1024 * 1024;
const MAX_PART_BYTES = 16 * 1024 * 1024;
// A complete scan stops at 10,000 registered entries or 16 MiB of JSON output.
const LIST_ENTRIES = 10_000;
const encoder = new TextEncoder();
const hex = (buffer) => Array.from(new Uint8Array(buffer), (byte) => byte.toString(16).padStart(2, '0')).join('');
const fail = (message) => { throw new Error(message); };
const integer = (value, maximum) => Number.isSafeInteger(value) && value > 0 && value <= maximum;
function shape(value, keys) {
  if (!value || typeof value !== 'object' || Array.isArray(value)
    || Object.keys(value).length !== keys.length || keys.some((key) => !Object.hasOwn(value, key))) fail('Invalid object shape.');
}
function identifier(value) {
  if (typeof value !== 'string' || !HEX.test(value)) fail('Invalid identifier.');
}
function logicalKey(value) {
  if (typeof value !== 'string' || !LOGICAL.test(value)) fail('Invalid logical key.');
}
export function validateReceipt(value, maxBytes = MAX_OBJECT_BYTES) {
  shape(value, ['key', 'bytes', 'sha256']);
  logicalKey(value.key);
  const maximum = value.key.startsWith('packs/') ? Math.min(maxBytes, 34 * 1024 * 1024)
    : value.key.startsWith('pages/') ? Math.min(maxBytes, 1024 * 1024 + 65536) : maxBytes;
  if (!integer(value.bytes, maximum) || typeof value.sha256 !== 'string' || !HEX.test(value.sha256)) fail('Invalid receipt.');
  return { key: value.key, bytes: value.bytes, sha256: value.sha256 };
}
function part(value, expectedKey, maximum) {
  shape(value, ['key', 'bytes', 'sha256']);
  if (value.key !== expectedKey || !integer(value.bytes, maximum) || typeof value.sha256 !== 'string' || !HEX.test(value.sha256)) fail('Invalid part.');
  return { key: value.key, bytes: value.bytes, sha256: value.sha256 };
}
function digest() {
  const stream = new crypto.DigestStream('SHA-256');
  stream.digest.catch(() => {});
  return { stream, writer: stream.getWriter() };
}
function deadline(timeoutMs) {
  const end = Date.now() + timeoutMs;
  return async (operation) => {
    const remaining = end - Date.now();
    if (remaining <= 0) fail('R2 operation timed out.');
    let timer;
    try {
      return await Promise.race([operation(), new Promise((_, reject) => {
        timer = setTimeout(() => reject(new Error('R2 operation timed out.')), remaining);
      })]);
    } finally { clearTimeout(timer); }
  };
}
async function closeDigest(value, wait) {
  await wait(() => value.writer.close());
  return hex(await wait(() => value.stream.digest));
}
function abortDigest(value) {
  value.writer.abort().catch(() => {});
  value.writer.releaseLock();
}
function stream(iterator) {
  return new ReadableStream({
    async pull(controller) {
      try {
        const { value, done } = await iterator.next();
        if (done) controller.close();
        else controller.enqueue(value);
      } catch (error) { controller.error(error); }
    },
    async cancel() { await iterator.return(); },
  });
}

export class R2Objects {
  constructor(bucket, unitId, { maxBytes = MAX_OBJECT_BYTES, partBytes = MAX_PART_BYTES, timeoutMs = 30_000 } = {}) {
    identifier(unitId);
    if (!integer(maxBytes, MAX_OBJECT_BYTES) || !integer(partBytes, MAX_PART_BYTES)
      || !integer(timeoutMs, 30_000)) fail('Invalid R2 limits.');
    this.bucket = bucket;
    this.prefix = `units/${unitId}/`;
    this.maxBytes = maxBytes;
    this.partBytes = partBytes;
    this.timeoutMs = timeoutMs;
  }

  descriptor(value) {
    shape(value, ['format', 'version', 'receipt', 'parts']);
    if (value.format !== 'wordpress-r2-cipher-parts' || value.version !== 1) fail('Unsupported descriptor.');
    const logical = validateReceipt(value.receipt, this.maxBytes);
    if (!Array.isArray(value.parts) || value.parts.length !== Math.ceil(logical.bytes / this.partBytes)) fail('Invalid part count.');
    const uploadId = /^uploads\/([a-f0-9]{64})\/0$(?![\s\S])/.exec(value.parts[0]?.key)?.[1];
    identifier(uploadId);
    const parts = value.parts.map((value, ordinal) => {
      const checked = part(value, `uploads/${uploadId}/${ordinal}`, this.partBytes);
      if (checked.bytes !== Math.min(this.partBytes, logical.bytes - ordinal * this.partBytes)) fail('Invalid part length.');
      return checked;
    });
    return { format: 'wordpress-r2-cipher-parts', version: 1, receipt: logical, parts };
  }

  async putPart(uploadId, ordinal, expectedPart, body, beforePut = async () => {}) {
    identifier(uploadId);
    if (!Number.isSafeInteger(ordinal) || ordinal < 0 || ordinal >= Math.ceil(this.maxBytes / this.partBytes)) fail('Invalid part ordinal.');
    const expected = part(expectedPart, `uploads/${uploadId}/${ordinal}`, this.partBytes);
    if (!(body instanceof ReadableStream) || typeof beforePut !== 'function') fail('Invalid part input.');
    const wait = deadline(this.timeoutMs);
    const reader = body.getReader();
    const bytes = new Uint8Array(expected.bytes);
    let length = 0;
    try {
      while (true) {
        const { value, done } = await wait(() => reader.read());
        if (done) break;
        if (!(value instanceof Uint8Array) || length + value.byteLength > expected.bytes) fail('Part exceeds expected length.');
        bytes.set(value, length);
        length += value.byteLength;
      }
      if (length !== expected.bytes || hex(await wait(() => crypto.subtle.digest('SHA-256', bytes))) !== expected.sha256) fail('Part content mismatch.');
      await wait(beforePut);
      await this.createExact(expected.key, bytes, expected, wait);
      return expected;
    } finally {
      reader.cancel().catch(() => {});
      reader.releaseLock();
    }
  }

  async createExact(key, bytes, expected, wait) {
    await wait(() => this.bucket.put(this.prefix + key, bytes, { onlyIf: { etagDoesNotMatch: '*' } }));
    const object = await wait(() => this.bucket.get(this.prefix + key));
    for await (const _chunk of this.checkedParts([expected], expected, wait, object)) {}
  }

  async *checkedParts(parts, expected, wait, firstObject) {
    const whole = digest();
    let total = 0;
    try {
      for (let n = 0; n < parts.length; n++) {
        const expectedPart = parts[n];
        const object = n === 0 && firstObject !== undefined ? firstObject : await wait(() => this.bucket.get(this.prefix + expectedPart.key));
        if (!object || object.size !== expectedPart.bytes) fail('Stored part is missing or has the wrong length.');
        const reader = object.body.getReader();
        const current = digest();
        let bytes = 0;
        try {
          while (true) {
            const { value, done } = await wait(() => reader.read());
            if (done) break;
            if (!(value instanceof Uint8Array)) fail('Invalid stored stream.');
            bytes += value.byteLength;
            total += value.byteLength;
            if (bytes > expectedPart.bytes || total > expected.bytes) fail('Stored ciphertext exceeds its receipt.');
            await wait(() => current.writer.write(value));
            await wait(() => whole.writer.write(value));
            yield value;
          }
          if (bytes !== expectedPart.bytes || await closeDigest(current, wait) !== expectedPart.sha256) fail('Stored part content mismatch.');
        } finally {
          reader.cancel().catch(() => {});
          reader.releaseLock();
          abortDigest(current);
        }
      }
      if (total !== expected.bytes || await closeDigest(whole, wait) !== expected.sha256) fail('Stored ciphertext content mismatch.');
    } finally { abortDigest(whole); }
  }

  async verifyUpload(upload) {
    shape(upload, ['uploadId', 'receipt', 'parts']);
    identifier(upload.uploadId);
    const descriptor = this.descriptor({ format: 'wordpress-r2-cipher-parts', version: 1, receipt: upload.receipt, parts: upload.parts });
    if (descriptor.parts[0].key !== `uploads/${upload.uploadId}/0`) fail('Upload identifier mismatch.');
    for await (const _chunk of this.checkedParts(descriptor.parts, descriptor.receipt, deadline(this.timeoutMs))) {}
    return descriptor;
  }

  async metadata(key, wait) {
    const object = await wait(() => this.bucket.get(this.prefix + key));
    if (!object) return undefined;
    if (!integer(object.size, META_BYTES)) fail('Metadata size limit exceeded.');
    const reader = object.body.getReader();
    const output = new Uint8Array(object.size);
    let bytes = 0;
    try {
      while (true) {
        const { value, done } = await wait(() => reader.read());
        if (done) break;
        if (bytes + value.byteLength > output.length) fail('Metadata size mismatch.');
        output.set(value, bytes);
        bytes += value.byteLength;
      }
      if (bytes !== output.length) fail('Metadata size mismatch.');
      return JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(output));
    } finally { reader.cancel().catch(() => {}); reader.releaseLock(); }
  }

  async readDescriptor(logical) {
    logicalKey(logical);
    const wait = deadline(this.timeoutMs);
    const descriptor = await this.metadata(`descriptors/${logical}.json`, wait);
    if (descriptor === undefined) return null;
    if (await wait(() => this.bucket.head(this.prefix + logical))) fail('Ambiguous raw and descriptor object.');
    const checked = this.descriptor(descriptor);
    if (checked.receipt.key !== logical) fail('Descriptor logical key mismatch.');
    return checked;
  }

  async readLogical(value) {
    const expected = validateReceipt(value, this.maxBytes);
    const descriptor = await this.readDescriptor(expected.key);
    if (descriptor && (descriptor.receipt.bytes !== expected.bytes || descriptor.receipt.sha256 !== expected.sha256)) fail('Descriptor receipt mismatch.');
    return stream(this.checkedParts(descriptor ? descriptor.parts : [expected], expected, deadline(this.timeoutMs)));
  }

  // The publication owner passes the exact descriptor returned by verifyUpload.
  async createDescriptor(value) {
    const checked = this.descriptor(value);
    const wait = deadline(this.timeoutMs);
    if (await wait(() => this.bucket.head(this.prefix + checked.receipt.key))) fail('Ambiguous raw and descriptor object.');
    const body = encoder.encode(JSON.stringify(checked));
    if (body.byteLength > META_BYTES) fail('Metadata size limit exceeded.');
    await this.createExact(`descriptors/${checked.receipt.key}.json`, body,
      { key: `descriptors/${checked.receipt.key}.json`, bytes: body.byteLength, sha256: hex(await crypto.subtle.digest('SHA-256', body)) }, wait);
    return checked.receipt;
  }

  async createComplete(snapshotId, value) {
    identifier(snapshotId);
    const manifest = validateReceipt(value, this.maxBytes);
    if (manifest.key !== `manifests/${snapshotId}.age`) fail('Manifest does not belong to snapshot.');
    const input = await this.readLogical(manifest);
    for await (const _chunk of input) {}
    const complete = { snapshotId, manifest };
    const body = encoder.encode(JSON.stringify(complete));
    await this.createExact(`commits/${snapshotId}.json`, body,
      { key: `commits/${snapshotId}.json`, bytes: body.byteLength, sha256: hex(await crypto.subtle.digest('SHA-256', body)) }, deadline(this.timeoutMs));
    return complete;
  }

  async completed(snapshotId) {
    identifier(snapshotId);
    const record = await this.metadata(`commits/${snapshotId}.json`, deadline(this.timeoutMs));
    shape(record, ['snapshotId', 'manifest']);
    if (record.snapshotId !== snapshotId) fail('Completion snapshot mismatch.');
    const manifest = validateReceipt(record.manifest, this.maxBytes);
    if (manifest.key !== `manifests/${snapshotId}.age`) fail('Completion manifest mismatch.');
    for await (const _chunk of await this.readLogical(manifest)) {}
    return { snapshotId, manifest };
  }

  async scan(prefix, consume, budget, wait) {
    let cursor;
    do {
      const page = await wait(() => this.bucket.list({ prefix: this.prefix + prefix, limit: 1000, ...(cursor ? { cursor } : {}) }));
      for (const object of page.objects) {
        if (++budget.entries > LIST_ENTRIES) fail('Listing entry limit exceeded.');
        budget.bytes += encoder.encode(object.key).byteLength;
        if (budget.bytes > META_BYTES) fail('Listing byte limit exceeded.');
        await wait(() => consume(object.key.slice(this.prefix.length)));
      }
      if (page.truncated && (!page.cursor || page.cursor === cursor)) fail('Invalid listing cursor.');
      cursor = page.truncated ? page.cursor : undefined;
    } while (cursor);
  }

  async listCompleted() {
    const result = [];
    const budget = { entries: 0, bytes: 0 };
    await this.scan('commits/', async (key) => {
      const match = /^commits\/([a-f0-9]{64})\.json$(?![\s\S])/.exec(key);
      if (!match) fail('Invalid completion record name.');
      const record = await this.completed(match[1]);
      budget.bytes += encoder.encode(JSON.stringify(record)).byteLength;
      if (budget.bytes > META_BYTES) fail('Listing byte limit exceeded.');
      result.push(record);
    }, budget, deadline(this.timeoutMs));
    return result;
  }

  async listFileObjects(keySetId) {
    identifier(keySetId);
    const result = new Map();
    const budget = { entries: 0, bytes: 0 };
    const wait = deadline(this.timeoutMs);
    const add = async (key) => {
      logicalKey(key);
      if (result.has(key)) fail('Ambiguous raw and descriptor object.');
      const descriptor = await this.readDescriptor(key);
      let record;
      if (descriptor) record = descriptor.receipt;
      else {
        const object = await wait(() => this.bucket.get(this.prefix + key));
        if (!object || !integer(object.size, this.maxBytes)) fail('Invalid raw ciphertext.');
        const checksum = digest();
        let bytes = 0;
        const reader = object.body.getReader();
        try {
          while (true) {
            const item = await wait(() => reader.read());
            if (item.done) break;
            bytes += item.value.byteLength;
            if (bytes > object.size) fail('Raw ciphertext length mismatch.');
            await wait(() => checksum.writer.write(item.value));
          }
          if (bytes !== object.size) fail('Raw ciphertext length mismatch.');
          record = { key, bytes, sha256: await closeDigest(checksum, wait) };
        } finally { reader.cancel().catch(() => {}); reader.releaseLock(); abortDigest(checksum); }
      }
      budget.bytes += encoder.encode(JSON.stringify(record)).byteLength;
      if (budget.bytes > META_BYTES) fail('Listing byte limit exceeded.');
      result.set(key, record);
    };
    await this.scan(`descriptors/files/${keySetId}/`, async (key) => {
      if (!key.endsWith('.json')) fail('Invalid descriptor name.');
      await add(key.slice('descriptors/'.length, -'.json'.length));
    }, budget, wait);
    await this.scan(`files/${keySetId}/`, add, budget, wait);
    return Array.from(result.values()).sort((a, b) => a.key.localeCompare(b.key));
  }
}
