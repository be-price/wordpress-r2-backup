import { DurableObject } from 'cloudflare:workers';
import { R2Objects, validateReceipt } from './r2-objects.mjs';

export class ControlError extends Error {
  constructor(message, status = 409) { super(message); this.status = status; }
}

function fields(value, names) {
  if (!value || typeof value !== 'object' || Array.isArray(value)
      || Object.keys(value).sort().join(',') !== [...names].sort().join(',')) {
    throw new ControlError('Invalid control message shape.', 400);
  }
}
function identifier(value) {
  if (typeof value !== 'string' || !/^[a-f0-9]{64}$(?![\s\S])/.test(value)) throw new ControlError('Invalid identifier.', 400);
  return value;
}
function integer(value, minimum = 0, maximum = Number.MAX_SAFE_INTEGER) {
  if (!Number.isSafeInteger(value) || value < minimum || value > maximum) throw new ControlError('Invalid control integer.', 400);
  return value;
}
function cursor(value) {
  fields(value, ['indexId', 'sequence', 'commitment']);
  return { indexId: identifier(value.indexId), sequence: integer(value.sequence), commitment: identifier(value.commitment) };
}
function same(a, b) { return JSON.stringify(a) === JSON.stringify(b); }
async function sha256(value) {
  const bytes = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(value));
  return Array.from(new Uint8Array(bytes), x => x.toString(16).padStart(2, '0')).join('');
}
function boundedWait(milliseconds = 30000) {
  const end = Date.now() + milliseconds;
  return async operation => {
    const remaining = end - Date.now();
    if (remaining <= 0) throw new ControlError('Storage inspection timed out.', 503);
    let timer;
    try { return await Promise.race([operation(), new Promise((_, reject) => {
      timer = setTimeout(() => reject(new ControlError('Storage inspection timed out.', 503)), remaining);
    })]); } finally { clearTimeout(timer); }
  };
}
function randomId() { return Array.from(crypto.getRandomValues(new Uint8Array(32)), x => x.toString(16).padStart(2, '0')).join(''); }

export class UnitCoordinator extends DurableObject {
  constructor(ctx, env) { super(ctx, env); this.context = ctx; this.env = env; }
  now() { return Math.floor(Date.now() / 1000); }

  async fetch(request) {
    try {
      if (request.method === 'PUT') return await this.relay(request);
      if (request.method !== 'POST') throw new ControlError('Internal method is not allowed.', 405);
      const text = await request.text();
      if (text.length > 65536) throw new ControlError('Control message is too large.', 413);
      let body;
      try { body = JSON.parse(text); } catch { throw new ControlError('Invalid control JSON.', 400); }
      fields(body, body?.session === undefined ? ['action', 'principal', 'input'] : ['action', 'principal', 'input', 'session']);
      const actions = ['initialize', 'acquire', 'renew', 'authorize', 'advance', 'status', 'freeze', 'planUpload', 'grantPart', 'register', 'verify', 'complete', 'administratorStatus', 'barrier', 'activate', 'activatedPrincipal', 'drain'];
      if (!actions.includes(body.action)) throw new ControlError('Unknown control action.', 404);
      const result = await this[body.action](body.principal, body.input, body.session);
      return Response.json(result, { status: result?.pending === true ? 202 : 200 });
    } catch (error) {
      return Response.json({ error: error instanceof ControlError ? error.message : 'Unit control failed.' },
        { status: error instanceof ControlError ? error.status : 503 });
    }
  }

  async readState(storage) {
    const state = await storage.get('ledger');
    if (!state) throw new ControlError('Unit ledger is missing; writes are stopped.', 503);
    try {
      fields(state, ['version', 'unitId', 'sourceId', 'generation', 'head', 'fence', 'frozen', 'activeJob', 'lease', 'lastAdvance', 'freeze', 'lastActivation']);
      if (state.version !== 1 || typeof state.frozen !== 'boolean') throw new Error();
      identifier(state.unitId); identifier(state.sourceId); integer(state.generation, 1); integer(state.fence);
      state.head = cursor(state.head);
      if (state.activeJob !== null) {
        fields(state.activeJob, ['jobId', 'status']); identifier(state.activeJob.jobId);
        if (!['active', 'completed'].includes(state.activeJob.status)) throw new Error();
      }
      if (state.lease !== null) {
        fields(state.lease, ['jobId', 'processNonceHash', 'fence', 'expiresAt']); identifier(state.lease.jobId);
        identifier(state.lease.processNonceHash); integer(state.lease.fence, 1); integer(state.lease.expiresAt, 1);
      }
      if (state.lastAdvance !== null) {
        fields(state.lastAdvance, ['eventId', 'jobId', 'from', 'to']);
        identifier(state.lastAdvance.eventId); identifier(state.lastAdvance.jobId);
        cursor(state.lastAdvance.from); cursor(state.lastAdvance.to);
      }
      if (state.freeze !== null) {
        fields(state.freeze, ['freezeId', 'generation', 'readyAt', 'stamp']);
        identifier(state.freeze.freezeId); integer(state.freeze.generation, 1); integer(state.freeze.readyAt);
        if (state.freeze.stamp !== null) identifier(state.freeze.stamp);
        if (!state.frozen || state.freeze.generation !== state.generation) throw new Error();
      } else if (state.frozen) throw new Error();
      if (state.lastActivation !== null) {
        fields(state.lastActivation, ['sourceId', 'cursor', 'freezeId', 'stamp', 'credentialHash', 'expiresAt', 'generation']);
        for (const name of ['sourceId', 'freezeId', 'stamp', 'credentialHash']) identifier(state.lastActivation[name]);
        cursor(state.lastActivation.cursor); integer(state.lastActivation.expiresAt, 1); integer(state.lastActivation.generation, 2);
        if (state.lastActivation.generation !== state.generation || state.lastActivation.sourceId !== state.sourceId) throw new Error();
      }
    } catch { throw new ControlError('Unit ledger is invalid; writes are stopped.', 503); }
    return state;
  }

  async mutate(operation) {
    return this.context.storage.transaction(async storage => {
      const state = await this.readState(storage);
      const result = await operation(state, storage);
      if (JSON.stringify(state).length > 65536) throw new ControlError('Unit ledger limit reached.', 507);
      await storage.put('ledger', state);
      return result;
    });
  }

  administrator(principal) {
    if (principal?.role !== 'admin') throw new ControlError('Administrator authority is required.', 403);
    fields(principal, ['role']);
  }
  writer(state, principal, allowFrozen = false) {
    if (principal?.role !== 'writer') throw new ControlError('Writer authority is required.', 403);
    fields(principal, ['role', 'sourceId', 'generation']);
    if (principal.sourceId !== state.sourceId || principal.generation !== state.generation) throw new ControlError('Source generation is no longer current.', 403);
    if (state.frozen && !allowFrozen) throw new ControlError('Unit is frozen.');
  }
  parseSession(value) {
    fields(value, ['generation', 'jobId', 'processNonce', 'fence', 'cursor']);
    return { generation: integer(value.generation, 1), jobId: identifier(value.jobId), processNonce: identifier(value.processNonce),
      fence: integer(value.fence, 1), cursor: cursor(value.cursor) };
  }
  async authorized(state, principal, value, checkCursor = true) {
    this.writer(state, principal);
    const session = this.parseSession(value);
    const lease = state.lease;
    if (session.generation !== state.generation || !lease || state.activeJob?.status !== 'active'
        || state.activeJob.jobId !== session.jobId || lease.jobId !== session.jobId || lease.fence !== session.fence
        || lease.processNonceHash !== await sha256(session.processNonce) || this.now() >= lease.expiresAt
        || (checkCursor && !same(session.cursor, state.head))) {
      throw new ControlError('Lease, process, job or index cursor is no longer current.');
    }
    return session;
  }
  leaseView(state) {
    return { sourceId: state.sourceId, generation: state.generation, jobId: state.lease.jobId,
      fence: state.lease.fence, expiresAt: state.lease.expiresAt, cursor: state.head };
  }

  async initialize(principal, input) {
    this.administrator(principal);
    fields(input, ['unitId', 'sourceId', 'cursor']);
    const unitId = identifier(input.unitId); const sourceId = identifier(input.sourceId); const head = cursor(input.cursor);
    if (await this.context.storage.get('ledger') !== undefined) throw new ControlError('Unit ledger already exists.');
    const existing = await this.env.BUCKET.list({ prefix: `units/${unitId}/`, limit: 1 });
    if (existing.objects.length !== 0) throw new ControlError('Initialization requires an empty R2 unit.');
    const marker = await this.env.BUCKET.put(`units/${unitId}/control/initialized.json`,
      JSON.stringify({ version: 1, unitId, initialSourceId: sourceId }), { onlyIf: { etagDoesNotMatch: '*' } });
    if (!marker) throw new ControlError('Unit initialization was already started.');
    return this.context.storage.transaction(async storage => {
      if (await storage.get('ledger') !== undefined) throw new ControlError('Unit ledger already exists.');
      await storage.put('ledger', { version: 1, unitId, sourceId, generation: 1, head, fence: 0, frozen: false,
        activeJob: null, lease: null, lastAdvance: null, freeze: null, lastActivation: null });
      return { unitId, sourceId, generation: 1, head };
    });
  }

  async acquire(principal, input) {
    fields(input, ['jobId', 'processNonce', 'ttl', 'cursor']);
    const jobId = identifier(input.jobId); const processNonceHash = await sha256(identifier(input.processNonce));
    const ttl = integer(input.ttl, 1, 3600); const head = cursor(input.cursor);
    return this.mutate(async (state, storage) => {
      this.writer(state, principal);
      if (!same(head, state.head)) throw new ControlError('Index cursor differs from the unit head.');
      if (state.activeJob?.status === 'active' && state.activeJob.jobId !== jobId) throw new ControlError('Another job remains active.');
      if (await storage.get(`completed:${jobId}`) || (state.activeJob?.status === 'completed' && state.activeJob.jobId === jobId)) throw new ControlError('Completed job cannot be reacquired.');
      const now = integer(this.now()); const lease = state.lease;
      if (lease && now < lease.expiresAt) {
        if (lease.jobId !== jobId || lease.processNonceHash !== processNonceHash) throw new ControlError('Live lease belongs to another process.');
        return this.leaseView(state);
      }
      integer(state.fence + 1, 1); integer(now + ttl, 1);
      state.fence++;
      state.activeJob = { jobId, status: 'active' };
      state.lease = { jobId, processNonceHash, fence: state.fence, expiresAt: now + ttl };
      return this.leaseView(state);
    });
  }

  async renew(principal, input, session) {
    fields(input, ['ttl']); const ttl = integer(input.ttl, 1, 3600);
    return this.mutate(async state => {
      await this.authorized(state, principal, session);
      state.lease.expiresAt = Math.max(state.lease.expiresAt, integer(this.now() + ttl, 1));
      return this.leaseView(state);
    });
  }
  async authorize(principal, input, session) {
    fields(input, []);
    return this.mutate(async state => { await this.authorized(state, principal, session); return this.leaseView(state); });
  }
  async advance(principal, input, value) {
    fields(input, ['eventId', 'from', 'to']);
    const eventId = identifier(input.eventId); const from = cursor(input.from); const to = cursor(input.to);
    return this.mutate(async state => {
      const session = await this.authorized(state, principal, value, false);
      const event = { eventId, jobId: session.jobId, from, to };
      if (same(event, state.lastAdvance) && same(state.head, to) && (same(session.cursor, from) || same(session.cursor, to))) return to;
      if (!same(from, state.head) || !same(session.cursor, from) || from.indexId !== to.indexId
          || to.sequence !== from.sequence + 1 || from.commitment === to.commitment || state.lastAdvance?.eventId === eventId) {
        throw new ControlError('Index advance does not match the current head.');
      }
      state.lastAdvance = event; state.head = to; return to;
    });
  }

  async status(principal, input) {
    fields(input, []);
    const state = await this.readState(this.context.storage);
    this.writer(state, principal, true);
    const { freeze, lastActivation, ...view } = state;
    if (view.lease) {
      const { processNonceHash, ...lease } = view.lease;
      view.lease = lease;
    }
    return { ...view, publications: await this.publications() };
  }

  async freeze(principal, input) {
    this.administrator(principal); fields(input, ['expectedGeneration']);
    const expected = integer(input.expectedGeneration, 1); const freezeId = randomId();
    return this.mutate(async (state, storage) => {
      if (state.generation !== expected) throw new ControlError('Source generation changed before freeze.');
      if (state.frozen && state.freeze) return this.freezeView(state.freeze);
      state.frozen = true; state.fence = integer(state.fence + 1, 1);
      const grants = await this.rows(storage, 'grant:');
      state.freeze = { freezeId, generation: state.generation, readyAt: Math.max(this.now(), state.lease?.expiresAt ?? 0,
        ...Array.from(grants.values(), grant => integer(grant.expiresAt, 1))), stamp: null };
      return this.freezeView(state.freeze);
    });
  }

  async rows(storage, prefix) {
    const values = await storage.list({ prefix, limit: 10001 });
    if (values.size > 10000) throw new ControlError('Unit table limit reached.', 507);
    return values;
  }
  async planUpload(principal, input, value) {
    fields(input, ['receipt', 'parts']);
    let receipt;
    try { receipt = validateReceipt(input.receipt); } catch { throw new ControlError('Invalid object receipt.', 400); }
    if (!Array.isArray(input.parts) || input.parts.length !== Math.ceil(receipt.bytes / 16777216)) throw new ControlError('Invalid part count.', 400);
    const partSpecs = input.parts.map((part, ordinal) => {
      fields(part, ['bytes', 'sha256']);
      const expected = Math.min(16777216, receipt.bytes - ordinal * 16777216);
      if (part.bytes !== expected) throw new ControlError('Invalid part length.', 400);
      return { bytes: expected, sha256: identifier(part.sha256) };
    });
    const candidateKey = `candidate:${await sha256(receipt.key)}`;
    const uploadId = randomId();
    return this.mutate(async (state, storage) => {
      const session = await this.authorized(state, principal, value);
      const previousId = await storage.get(candidateKey);
      if (previousId) {
        const previous = await storage.get(`upload:${previousId}`);
        if (!previous || previous.jobId !== session.jobId || previous.sourceId !== state.sourceId || previous.generation !== state.generation
            || !same(previous.receipt, receipt) || !same(previous.parts.map(({ bytes, sha256 }) => ({ bytes, sha256 })), partSpecs)) {
          throw new ControlError('Existing logical key has another fixed candidate.');
        }
        return { uploadId: previous.uploadId, receipt: previous.receipt, parts: previous.parts };
      }
      if ((await this.rows(storage, 'upload:')).size >= 10000) throw new ControlError('Upload table limit reached.', 507);
      const parts = partSpecs.map((part, ordinal) => ({ key: `uploads/${uploadId}/${ordinal}`, ...part }));
      const upload = { uploadId, sourceId: state.sourceId, generation: state.generation, jobId: session.jobId, receipt, parts };
      await storage.put({ [candidateKey]: uploadId, [`upload:${uploadId}`]: upload });
      return { uploadId, receipt, parts };
    });
  }
  async grantPart(principal, input, value) {
    fields(input, ['uploadId', 'ordinal']); identifier(input.uploadId); integer(input.ordinal, 0, 7);
    const token = randomId(); const tokenHash = await sha256(token);
    return this.mutate(async (state, storage) => {
      const session = await this.authorized(state, principal, value);
      const upload = await this.upload(storage, input.uploadId);
      if (!upload || upload.sourceId !== state.sourceId || upload.generation !== state.generation || upload.jobId !== session.jobId
          || !upload.parts[input.ordinal]) throw new ControlError('Part does not belong to the current job.');
      const grants = await this.rows(storage, 'grant:');
      for (const [key, grant] of grants) if (this.now() >= grant.expiresAt) await storage.delete(key);
      if (grants.size >= 10000) throw new ControlError('Grant table limit reached.', 507);
      const expiresAt = Math.min(this.now() + 30, state.lease.expiresAt);
      const part = upload.parts[input.ordinal];
      await storage.put(`grant:${tokenHash}`, { sourceId: state.sourceId, generation: state.generation, jobId: session.jobId,
        fence: session.fence, processNonceHash: state.lease.processNonceHash, cursor: state.head,
        uploadId: upload.uploadId, ordinal: input.ordinal, part, expiresAt });
      return { token, path: `/parts/${upload.uploadId}/${input.ordinal}`, bytes: part.bytes, sha256: part.sha256,
        expiresAt, headers: { 'If-None-Match': '*' } };
    });
  }
  checkGrant(state, grant) {
    if (grant) {
      try {
        fields(grant, ['sourceId', 'generation', 'jobId', 'fence', 'processNonceHash', 'cursor', 'uploadId', 'ordinal', 'part', 'expiresAt']);
        for (const name of ['sourceId', 'jobId', 'processNonceHash', 'uploadId']) identifier(grant[name]);
        integer(grant.generation, 1); integer(grant.fence, 1); integer(grant.ordinal, 0, 7); integer(grant.expiresAt, 1); cursor(grant.cursor);
        fields(grant.part, ['key', 'bytes', 'sha256']); integer(grant.part.bytes, 1, 16777216); identifier(grant.part.sha256);
        if (grant.part.key !== `uploads/${grant.uploadId}/${grant.ordinal}`) throw new Error();
      } catch { throw new ControlError('Part grant ledger is invalid.', 503); }
    }
    if (!grant || state.frozen || grant.sourceId !== state.sourceId || grant.generation !== state.generation
        || state.activeJob?.status !== 'active' || state.activeJob.jobId !== grant.jobId
        || !state.lease || state.lease.jobId !== grant.jobId || state.lease.fence !== grant.fence
        || state.lease.processNonceHash !== grant.processNonceHash || !same(state.head, grant.cursor)
        || this.now() >= grant.expiresAt || this.now() >= state.lease.expiresAt) {
      throw new ControlError('Part grant is expired or no longer current.');
    }
  }
  async relay(request) {
    const token = request.headers.get('X-WRB-Part-Token'); identifier(token);
    if (request.headers.get('If-None-Match') !== '*') throw new ControlError('Part creation condition is required.', 400);
    const grantKey = `grant:${await sha256(token)}`; const relayKey = `relay:${randomId()}`;
    const { grant, unitId } = await this.mutate(async (state, storage) => {
      const grant = await storage.get(grantKey);
      this.checkGrant(state, grant);
      if (new URL(request.url).pathname !== `/parts/${grant.uploadId}/${grant.ordinal}`) throw new ControlError('Part grant path differs.', 403);
      await storage.put(relayKey, { part: grant.part, phase: 'receiving' });
      return { grant, unitId: state.unitId };
    });
    let started = false; let succeeded = false;
    try {
      await this.r2Objects(unitId).putPart(grant.uploadId, grant.ordinal, grant.part, request.body, async () => {
        await this.mutate(async (state, storage) => {
          this.checkGrant(state, grant);
          await storage.put(relayKey, { part: grant.part, phase: 'putting' });
        });
        started = true;
      });
      succeeded = true;
      await this.context.storage.transaction(async storage => {
        for (const [key, relay] of await this.rows(storage, 'relay:')) {
          if (same(relay.part, grant.part)) await storage.delete(key);
        }
      });
      return Response.json(grant.part);
    } catch (error) {
      if (error instanceof ControlError) throw error;
      throw new ControlError('Part could not be validated or stored.', 422);
    } finally {
      if (!started || succeeded) await this.context.storage.delete(relayKey);
    }
  }

  r2Objects(unitId) { return new R2Objects(this.env.BUCKET, unitId); }
  freezeView(freeze) { const { stamp, ...view } = freeze; return view; }
  activationView(activation) { const { credentialHash, ...view } = activation; return view; }
  receipt(value) {
    try { return validateReceipt(value); } catch { throw new ControlError('Invalid object receipt.', 400); }
  }
  async upload(storage, uploadId) {
    const upload = await storage.get(`upload:${uploadId}`);
    try {
      fields(upload, ['uploadId', 'sourceId', 'generation', 'jobId', 'receipt', 'parts']);
      if (upload.uploadId !== uploadId) throw new Error();
      identifier(upload.sourceId); integer(upload.generation, 1); identifier(upload.jobId);
      const objects = this.r2Objects((await this.readState(storage)).unitId);
      objects.descriptor({ format: 'wordpress-r2-cipher-parts', version: 1, receipt: upload.receipt, parts: upload.parts });
      if (upload.parts[0].key !== `uploads/${uploadId}/0`) throw new Error();
    } catch { throw new ControlError('Upload ledger is missing or invalid.', 503); }
    return upload;
  }
  ownsUpload(state, session, upload) {
    if (upload.sourceId !== state.sourceId || upload.generation !== state.generation || upload.jobId !== session.jobId) {
      throw new ControlError('Upload does not belong to the current job.');
    }
  }
  async dependency(storage, state, session, receipt) {
    const key = `dependency:${state.generation}:${session.jobId}:${await sha256(receipt.key)}`;
    const value = { sourceId: state.sourceId, generation: state.generation, jobId: session.jobId, receipt };
    const previous = await storage.get(key);
    if (previous && !same(previous, value)) throw new ControlError('Dependency receipt changed.');
    if (!previous && (await this.rows(storage, 'dependency:')).size >= 10000) throw new ControlError('Dependency limit reached.', 507);
    await storage.put(key, value);
  }
  async dependencies(storage, state, session) {
    const values = await this.rows(storage, `dependency:${state.generation}:${session.jobId}:`);
    const result = [];
    for (const value of values.values()) {
      try {
        fields(value, ['sourceId', 'generation', 'jobId', 'receipt']);
        if (value.sourceId !== state.sourceId || value.generation !== state.generation || value.jobId !== session.jobId) throw new Error();
        validateReceipt(value.receipt);
      } catch { throw new ControlError('Dependency ledger is invalid.', 503); }
      result.push(value.receipt);
    }
    return result.sort((a, b) => a.key.localeCompare(b.key));
  }
  async checkedOutbox(storage) {
    const rows = await this.rows(storage, 'outbox:');
    for (const [key, row] of rows) {
      try {
        fields(row, ['id', 'sourceId', 'generation', 'jobId', 'cursor', 'descriptor', 'body', 'state', 'result', 'completionLease']);
        identifier(row.id); identifier(row.sourceId); integer(row.generation, 1); identifier(row.jobId); cursor(row.cursor);
        if (key !== `outbox:${row.id}` || !['pending', 'emitted'].includes(row.state) || typeof row.body !== 'string' || row.body.length > 65536) throw new Error();
        if (row.descriptor?.kind === 'object') {
          fields(row.descriptor, ['kind', 'receipt']); validateReceipt(row.descriptor.receipt);
          const body = this.r2Objects((await this.readState(storage)).unitId).descriptor(JSON.parse(row.body));
          if (!same(body.receipt, row.descriptor.receipt) || row.id !== await sha256(`object:${body.receipt.key}`) || row.completionLease !== null) throw new Error();
        } else {
          fields(row.descriptor, ['kind', 'snapshotId', 'manifest']);
          if (row.descriptor.kind !== 'complete') throw new Error();
          identifier(row.descriptor.snapshotId); validateReceipt(row.descriptor.manifest);
          if (row.descriptor.manifest.key !== `manifests/${row.descriptor.snapshotId}.age`
            || row.id !== await sha256(`complete:${row.descriptor.snapshotId}`)
            || row.body !== JSON.stringify({ snapshotId: row.descriptor.snapshotId, manifest: row.descriptor.manifest })) throw new Error();
          fields(row.completionLease, ['jobId', 'processNonceHash', 'fence', 'expiresAt']);
          identifier(row.completionLease.processNonceHash); integer(row.completionLease.fence, 1); integer(row.completionLease.expiresAt, 1);
          if (row.completionLease.jobId !== row.jobId) throw new Error();
        }
        const expected = row.descriptor.kind === 'object' ? row.descriptor.receipt : { snapshotId: row.descriptor.snapshotId, manifest: row.descriptor.manifest };
        if (row.state === 'pending' ? row.result !== null : !same(row.result, expected)) throw new Error();
      } catch { throw new ControlError('Publication ledger is invalid; writes are stopped.', 503); }
    }
    return rows;
  }
  async decide(storage, state, session, id, descriptor, body) {
    const existing = (await this.checkedOutbox(storage)).get(`outbox:${id}`);
    if (existing) {
      if (existing.sourceId !== state.sourceId || existing.generation !== state.generation || existing.jobId !== session.jobId
          || !same(existing.descriptor, descriptor) || existing.body !== body) throw new ControlError('Publication differs from its fixed decision.');
      return existing;
    }
    if ((await this.rows(storage, 'outbox:')).size >= 10000) throw new ControlError('Publication limit reached.', 507);
    const row = { id, sourceId: state.sourceId, generation: state.generation, jobId: session.jobId, cursor: state.head,
      descriptor, body, state: 'pending', result: null, completionLease: descriptor.kind === 'complete' ? { ...state.lease } : null };
    await storage.put(`outbox:${id}`, row);
    await storage.setAlarm(Date.now() + 1000);
    return row;
  }
  async register(principal, input, value) {
    fields(input, ['uploadId']); identifier(input.uploadId);
    const upload = await this.mutate(async (state, storage) => {
      const session = await this.authorized(state, principal, value);
      const upload = await this.upload(storage, input.uploadId); this.ownsUpload(state, session, upload); return upload;
    });
    const state = await this.readState(this.context.storage);
    const descriptor = await this.r2Objects(state.unitId).verifyUpload({ uploadId: upload.uploadId, receipt: upload.receipt, parts: upload.parts });
    const id = await sha256(`object:${upload.receipt.key}`);
    await this.mutate(async (state, storage) => {
      const session = await this.authorized(state, principal, value); this.ownsUpload(state, session, upload);
      await this.decide(storage, state, session, id, { kind: 'object', receipt: upload.receipt }, JSON.stringify(descriptor));
      await this.dependency(storage, state, session, upload.receipt);
    });
    await this.drainPending();
    return this.publicationResult(id);
  }
  async verify(principal, input, value) {
    const receipt = this.receipt(input);
    const state = await this.readState(this.context.storage);
    await this.authorized(state, principal, value);
    await this.verifyReceipts(this.r2Objects(state.unitId), [receipt]);
    await this.mutate(async (state, storage) => {
      const session = await this.authorized(state, principal, value);
      await this.dependency(storage, state, session, receipt);
    });
    return { verified: true };
  }
  async verifyReceipts(objects, receipts) {
    const wait = boundedWait();
    for (const receipt of receipts) {
      const body = await wait(() => objects.readLogical(receipt));
      const reader = body.getReader();
      try { while (!(await wait(() => reader.read())).done) {} }
      finally { reader.cancel().catch(() => {}); reader.releaseLock(); }
    }
  }
  async complete(principal, input, value) {
    fields(input, ['snapshotId', 'manifest']); const snapshotId = identifier(input.snapshotId); const manifest = this.receipt(input.manifest);
    if (manifest.key !== `manifests/${snapshotId}.age`) throw new ControlError('Manifest does not belong to snapshot.', 400);
    const id = await sha256(`complete:${snapshotId}`); const descriptor = { kind: 'complete', snapshotId, manifest };
    const previous = (await this.checkedOutbox(this.context.storage)).get(`outbox:${id}`);
    const initial = await this.readState(this.context.storage);
    this.writer(initial, principal);
    if (previous?.state === 'emitted' && initial.activeJob?.status === 'completed') {
      const session = this.parseSession(value); const lease = previous.completionLease;
      if (previous.sourceId !== initial.sourceId || previous.generation !== initial.generation || session.generation !== initial.generation
        || previous.jobId !== session.jobId || initial.activeJob.jobId !== session.jobId || !same(previous.descriptor, descriptor)
        || !same(session.cursor, initial.head) || !same(previous.cursor, initial.head) || initial.fence !== session.fence
        || lease.fence !== session.fence || lease.processNonceHash !== await sha256(session.processNonce) || this.now() >= lease.expiresAt) {
        throw new ControlError('Completed request is no longer current.');
      }
      const actual = await this.r2Objects(initial.unitId).completed(snapshotId);
      if (!same(actual, previous.result) || this.now() >= lease.expiresAt) throw new ControlError('Completed request recovery failed.');
      const latest = await this.readState(this.context.storage); this.writer(latest, principal);
      if (latest.frozen || latest.fence !== session.fence || !same(latest.head, session.cursor)) throw new ControlError('Completed request changed during recovery.');
      return actual;
    }
    const session = await this.authorized(initial, principal, value);
    const dependencies = await this.dependencies(this.context.storage, initial, session);
    if (!dependencies.some(r => same(r, manifest)) || !dependencies.some(r => r.key === `databases/${snapshotId}.age`)) {
      throw new ControlError('The job has no verified database and manifest dependencies.');
    }
    const manifestRow = (await this.checkedOutbox(this.context.storage)).get(`outbox:${await sha256(`object:${manifest.key}`)}`);
    if (!manifestRow || manifestRow.state !== 'emitted' || manifestRow.generation !== initial.generation || manifestRow.jobId !== session.jobId
        || !same(manifestRow.result, manifest)) throw new ControlError('Manifest publication is not confirmed for this job.');
    await this.verifyReceipts(this.r2Objects(initial.unitId), dependencies);
    await this.mutate(async (state, storage) => {
      const session = await this.authorized(state, principal, value);
      if (!same(dependencies, await this.dependencies(storage, state, session))) throw new ControlError('Job dependencies changed during completion.');
      const publications = await this.checkedOutbox(storage);
      for (const [key] of await this.rows(storage, 'upload:')) {
        const upload = await this.upload(storage, key.slice('upload:'.length));
        if (upload.sourceId !== state.sourceId || upload.generation !== state.generation || upload.jobId !== session.jobId) continue;
        const publication = publications.get(`outbox:${await sha256(`object:${upload.receipt.key}`)}`);
        if (!publication || publication.state !== 'emitted' || publication.sourceId !== state.sourceId
            || publication.generation !== state.generation || publication.jobId !== session.jobId
            || !same(publication.result, upload.receipt) || !dependencies.some(receipt => same(receipt, upload.receipt))) {
          throw new ControlError('A planned job candidate has not been published and verified.');
        }
      }
      for (const row of publications.values()) {
        if (row.generation === state.generation && row.jobId === session.jobId && row.descriptor.kind === 'object' && row.state !== 'emitted') {
          throw new ControlError('Job object publications remain pending.');
        }
      }
      await this.decide(storage, state, session, id, descriptor, JSON.stringify({ snapshotId, manifest }));
    });
    await this.drainPending();
    return this.publicationResult(id);
  }
  async publicationResult(id) {
    const row = (await this.checkedOutbox(this.context.storage)).get(`outbox:${id}`);
    if (!row) throw new ControlError('Publication decision is missing.', 503);
    return row.state === 'emitted' ? row.result : { pending: true, publicationId: id };
  }
  async publications() {
    const result = {};
    for (const row of (await this.checkedOutbox(this.context.storage)).values()) {
      result[row.id] = { generation: row.generation, jobId: row.jobId, cursor: row.cursor, descriptor: row.descriptor, result: row.result };
    }
    return result;
  }
  async drain(principal, input) { this.administrator(principal); fields(input, []); return this.drainPending(); }
  async alarm() { await this.drainPending(); }
  async drainPending() {
    if (this.draining) return this.draining;
    this.draining = this.emitPending();
    try { return await this.draining; } finally { this.draining = null; }
  }
  async emitPending() {
    await this.readState(this.context.storage);
    const rows = await this.checkedOutbox(this.context.storage);
    const wait = boundedWait();
    for (const row of rows.values()) {
      if (row.state !== 'pending') continue;
      const state = await this.readState(this.context.storage);
      let result;
      try {
        const objects = this.r2Objects(state.unitId);
        if (row.descriptor.kind === 'object') {
          result = await wait(() => objects.createDescriptor(JSON.parse(row.body)));
          const stored = await wait(() => objects.readDescriptor(row.descriptor.receipt.key));
          if (!stored || JSON.stringify(stored) !== row.body || !same(result, row.descriptor.receipt)) throw new Error('Descriptor differs.');
          await wait(() => this.verifyReceipts(objects, [result]));
        } else {
          result = await wait(() => objects.createComplete(row.descriptor.snapshotId, row.descriptor.manifest));
          if (!same(result, JSON.parse(row.body)) || !same(result, await wait(() => objects.completed(row.descriptor.snapshotId)))) throw new Error('Completion differs.');
        }
      } catch { continue; }
      await this.mutate(async (state, storage) => {
        const stored = (await this.checkedOutbox(storage)).get(`outbox:${row.id}`);
        if (!stored || stored.body !== row.body || !same(stored.descriptor, row.descriptor)) throw new ControlError('Publication decision changed.', 503);
        stored.state = 'emitted'; stored.result = result; await storage.put(`outbox:${row.id}`, stored);
        if (row.descriptor.kind === 'complete') {
          await storage.put(`completed:${row.jobId}`, { generation: row.generation });
          if (state.generation === row.generation && state.activeJob?.jobId === row.jobId) {
            state.activeJob.status = 'completed'; state.lease = null;
          }
        }
      });
    }
    const pending = [...(await this.checkedOutbox(this.context.storage)).values()].filter(row => row.state === 'pending').length;
    if (pending) await this.context.storage.setAlarm(Date.now() + 1000);
    return { pending: pending > 0, count: pending };
  }
  async administratorStatus(principal, input) {
    this.administrator(principal); fields(input, []);
    const state = await this.readState(this.context.storage);
    return { unitId: state.unitId, sourceId: state.sourceId, generation: state.generation, frozen: state.frozen, freeze: state.freeze,
      activation: state.lastActivation ? this.activationView(state.lastActivation) : null };
  }
  async activatedPrincipal(principal, input) {
    this.administrator(principal); fields(input, ['credentialHash']); identifier(input.credentialHash);
    const state = await this.readState(this.context.storage); const activation = state.lastActivation;
    if (!activation || activation.credentialHash !== input.credentialHash || this.now() >= activation.expiresAt) return null;
    return { role: 'writer', unitId: state.unitId, sourceId: activation.sourceId, generation: activation.generation, expiresAt: activation.expiresAt };
  }
  frozenState(state, freezeId) {
    if (!state.frozen || !state.freeze || state.freeze.freezeId !== freezeId) throw new ControlError('Frozen source differs.');
  }
  async settled(state) {
    if (this.now() < state.freeze.readyAt) return false;
    const rows = await this.rows(this.context.storage, 'relay:');
    const wait = boundedWait();
    for (const [key, relay] of rows) {
      try {
        fields(relay, ['part', 'phase']); fields(relay.part, ['key', 'bytes', 'sha256']);
        identifier(relay.part.sha256); integer(relay.part.bytes, 1, 16777216);
        if (!/^uploads\/[a-f0-9]{64}\/[0-7]$(?![\s\S])/.test(relay.part.key) || !['receiving', 'putting'].includes(relay.phase)) throw new Error();
      } catch { throw new ControlError('Relay ledger is invalid.', 503); }
      if (relay.phase === 'receiving') return false;
      const object = await wait(() => this.env.BUCKET.get(`units/${state.unitId}/${relay.part.key}`));
      if (!object) return false;
      const objects = this.r2Objects(state.unitId);
      for await (const _chunk of objects.checkedParts([relay.part], relay.part, wait, object)) {}
      await this.context.storage.delete(key);
    }
    return true;
  }
  async inventoryStamp(unitId) {
    const prefix = `units/${unitId}/`; const result = [];
    let entries = 0; let outputBytes = 0; let next;
    const wait = boundedWait();
    const objects = this.r2Objects(unitId);
    do {
      const page = await wait(() => this.env.BUCKET.list({ prefix, limit: 1000, ...(next ? { cursor: next } : {}) }));
      for (const item of page.objects) {
        if (++entries > 10000) throw new ControlError('Inventory entry limit reached.', 507);
        const key = item.key.slice(prefix.length);
        if (key === 'control/initialized.json' || /^uploads\/[a-f0-9]{64}\/[0-7]$(?![\s\S])/.test(key)) continue;
        const descriptor = /^descriptors\/((?:(?:files|packs|pages)\/[a-f0-9]{64}\/[a-f0-9]{64}|(?:databases|manifests)\/[a-f0-9]{64})\.age)\.json$(?![\s\S])/.exec(key);
        const commit = /^commits\/([a-f0-9]{64})\.json$(?![\s\S])/.exec(key);
        const raw = /^(?:(?:files|packs|pages)\/[a-f0-9]{64}\/[a-f0-9]{64}|(?:databases|manifests)\/[a-f0-9]{64})\.age$(?![\s\S])/.test(key);
        if (!descriptor && !commit && !raw) throw new ControlError('Unknown registered inventory key.', 503);
        const maximum = raw ? (key.startsWith('packs/') ? 35651584 : key.startsWith('pages/') ? 1114112 : 134217728) : 16777216;
        const stored = await wait(() => this.env.BUCKET.get(item.key));
        if (!stored || stored.size < 1 || stored.size > maximum) throw new ControlError('Inventory object is missing or too large.', 503);
        const checksum = new crypto.DigestStream('SHA-256'); checksum.digest.catch(() => {});
        const writer = checksum.getWriter(); const reader = stored.body.getReader(); let bytes = 0;
        try {
          while (true) {
            const chunk = await wait(() => reader.read()); if (chunk.done) break;
            bytes += chunk.value.byteLength; if (bytes > stored.size) throw new ControlError('Inventory object size mismatch.', 503);
            await wait(() => writer.write(chunk.value));
          }
          if (bytes !== stored.size) throw new ControlError('Inventory object size mismatch.', 503);
          await wait(() => writer.close());
          const sha = Array.from(new Uint8Array(await wait(() => checksum.digest)), value => value.toString(16).padStart(2, '0')).join('');
          const entry = { key, bytes, sha256: sha };
          outputBytes += new TextEncoder().encode(JSON.stringify(entry)).length;
          if (outputBytes > 16777216) throw new ControlError('Inventory byte limit reached.', 507);
          result.push(entry);
          if (descriptor) {
            const checked = await wait(() => objects.readDescriptor(descriptor[1]));
            if (!checked) throw new ControlError('Descriptor is missing.', 503);
            await wait(async () => { for await (const _chunk of await objects.readLogical(checked.receipt)) {} });
          } else if (commit) await wait(() => objects.completed(commit[1]));
          else if (await wait(() => this.env.BUCKET.head(prefix + `descriptors/${key}.json`))) throw new ControlError('Ambiguous raw and descriptor inventory.', 503);
        } finally { reader.cancel().catch(() => {}); reader.releaseLock(); writer.abort().catch(() => {}); writer.releaseLock(); }
      }
      if (page.truncated && (!page.cursor || page.cursor === next)) throw new ControlError('Inventory cursor is invalid.', 503);
      next = page.truncated ? page.cursor : undefined;
    } while (next);
    return sha256(JSON.stringify(result.sort((a, b) => a.key.localeCompare(b.key))));
  }
  async barrier(principal, input) {
    this.administrator(principal); fields(input, ['freezeId']); identifier(input.freezeId);
    let state = await this.readState(this.context.storage); this.frozenState(state, input.freezeId);
    if (this.now() < state.freeze.readyAt) return { pending: true, count: 0 };
    const drain = await this.drainPending();
    state = await this.readState(this.context.storage); this.frozenState(state, input.freezeId);
    if (drain.pending || !await this.settled(state)) return { pending: true, count: drain.count };
    const stamp = await this.inventoryStamp(state.unitId);
    return this.mutate(async (state, storage) => {
      this.frozenState(state, input.freezeId);
      if (state.freeze.stamp !== null && state.freeze.stamp !== stamp) throw new ControlError('Frozen inventory changed.');
      if ([...(await this.checkedOutbox(storage)).values()].some(row => row.state === 'pending')) throw new ControlError('Publication is still pending.');
      state.freeze.stamp = stamp; return state.freeze;
    });
  }
  async activate(principal, input) {
    this.administrator(principal); fields(input, ['freezeId', 'stamp', 'newSourceId', 'cursor', 'credentialHash', 'expiresAt']);
    for (const name of ['freezeId', 'stamp', 'newSourceId', 'credentialHash']) identifier(input[name]);
    const head = cursor(input.cursor); integer(input.expiresAt, 1);
    const request = { sourceId: input.newSourceId, cursor: head, freezeId: input.freezeId, stamp: input.stamp,
      credentialHash: input.credentialHash, expiresAt: input.expiresAt };
    const initial = await this.readState(this.context.storage);
    if (initial.lastActivation && !initial.frozen) {
      const { generation, ...previous } = initial.lastActivation;
      if (same(previous, request)) return this.activationView(initial.lastActivation);
    }
    this.frozenState(initial, input.freezeId);
    if (initial.freeze.stamp !== input.stamp || this.now() < initial.freeze.readyAt || input.newSourceId === initial.sourceId
      || head.indexId === initial.head.indexId || this.now() >= input.expiresAt) throw new ControlError('Activation differs from the verified freeze.');
    const drain = await this.drainPending();
    if (drain.pending || !await this.settled(initial)) return { pending: true, count: drain.count };
    const stamp = await this.inventoryStamp(initial.unitId);
    if (stamp !== input.stamp) throw new ControlError('Frozen inventory changed.');
    return this.mutate(async (state, storage) => {
      this.frozenState(state, input.freezeId);
      if (state.freeze.stamp !== input.stamp || state.generation !== initial.generation || state.sourceId !== initial.sourceId
          || this.now() >= input.expiresAt || [...(await this.checkedOutbox(storage)).values()].some(row => row.state === 'pending')
          || (await this.rows(storage, 'relay:')).size) throw new ControlError('Frozen state changed during activation.');
      state.generation = integer(state.generation + 1, 2); state.sourceId = input.newSourceId; state.head = head;
      state.activeJob = null; state.lastAdvance = null; state.lease = null; state.frozen = false; state.freeze = null;
      state.lastActivation = { ...request, generation: state.generation };
      return this.activationView(state.lastActivation);
    });
  }
}
