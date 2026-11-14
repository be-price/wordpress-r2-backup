import { ControlError, UnitCoordinator } from './unit-coordinator.mjs';
import { R2Objects } from './r2-objects.mjs';
export { UnitCoordinator };

function fields(value, names) {
  if (!value || typeof value !== 'object' || Array.isArray(value)
      || Object.keys(value).sort().join(',') !== [...names].sort().join(',')) throw new ControlError('Invalid request shape.', 400);
}
function id(value) { return typeof value === 'string' && value.length === 64 && /^[a-f0-9]+$/.test(value); }
function config(env) {
  const value = JSON.parse(env.WRB_CONFIG);
  fields(value, ['format', 'unitId', 'serverId', 'writer', 'administrator']);
  fields(value.writer, ['tokenSha256', 'sourceId', 'generation', 'expiresAt']);
  fields(value.administrator, ['tokenSha256', 'expiresAt']);
  if (value.format !== 'wrb-local-r2-service-v1' || !id(value.unitId) || !id(value.serverId)
      || !id(value.writer.tokenSha256) || !id(value.writer.sourceId) || value.writer.generation !== 1
      || !id(value.administrator.tokenSha256) || value.writer.tokenSha256 === value.administrator.tokenSha256
      || !Number.isSafeInteger(value.writer.expiresAt) || !Number.isSafeInteger(value.administrator.expiresAt)) {
    throw new ControlError('Local connector configuration is invalid.', 503);
  }
  return value;
}
async function digest(value) {
  const bytes = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(value));
  return Array.from(new Uint8Array(bytes), x => x.toString(16).padStart(2, '0')).join('');
}
function bearer(request) {
  const authorization = request.headers.get('Authorization') ?? '';
  const match = /^Bearer ([a-f0-9]{64})$/.exec(authorization);
  if (!match) throw new ControlError('A valid credential is required.', 403);
  return match[1];
}
async function json(request) {
  const reader = request.body?.getReader();
  if (!reader) throw new ControlError('A JSON request body is required.', 400);
  const chunks = []; let size = 0;
  try {
    for (;;) {
      const { value, done } = await reader.read();
      if (done) break;
      size += value.byteLength;
      if (size > 65536) { await reader.cancel(); throw new ControlError('Request body is too large.', 413); }
      chunks.push(value);
    }
  } finally { reader.releaseLock(); }
  const bytes = new Uint8Array(size); let offset = 0;
  for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.byteLength; }
  try { return JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(bytes)); }
  catch { throw new ControlError('Invalid request JSON.', 400); }
}
function control(stub, action, principal, input = {}, session) {
  return stub.fetch(new Request('http://internal/', { method: 'POST',
    body: JSON.stringify({ action, principal, input, ...(session === undefined ? {} : { session }) }) }));
}
async function authenticate(request, settings, stub) {
  const tokenHash = await digest(bearer(request));
  const now = Math.floor(Date.now() / 1000);
  let credential;
  if (tokenHash === settings.administrator.tokenSha256) {
    credential = { role: 'admin', expiresAt: settings.administrator.expiresAt };
  } else if (tokenHash === settings.writer.tokenSha256) {
    credential = { role: 'writer', ...settings.writer };
  } else {
    const result = await control(stub, 'activatedPrincipal', { role: 'admin' }, { credentialHash: tokenHash });
    if (result.status === 200) credential = await result.json();
  }
  if (!credential || credential.expiresAt <= now) throw new ControlError('Credential is invalid or expired.', 403);
  if (credential.role === 'writer') {
    if (request.headers.get('X-WRB-Source-Id') !== credential.sourceId
        || request.headers.get('X-WRB-Source-Generation') !== String(credential.generation)) {
      throw new ControlError('Credential and source binding differ.', 403);
    }
    return { principal: { role: 'writer', sourceId: credential.sourceId, generation: credential.generation }, expiresAt: credential.expiresAt };
  }
  return { principal: { role: 'admin' }, expiresAt: credential.expiresAt };
}
function session(request) {
  const value = request.headers.get('X-WRB-Session');
  if (!value || value.length > 4096) throw new ControlError('A bounded current session is required.', 400);
  try { return JSON.parse(value); } catch { throw new ControlError('Invalid session JSON.', 400); }
}

export default {
  async fetch(request, env) {
    try {
      const url = new URL(request.url);
      if (url.protocol !== 'http:' || url.hostname !== '127.0.0.1') throw new ControlError('This connector supports local testing only.', 403);
      if (url.search) throw new ControlError('Query parameters are not supported.', 400);
      const settings = config(env);
      if (request.method === 'GET' && url.pathname === '/health') return Response.json({ serverId: settings.serverId });
      if (request.headers.get('X-WRB-Unit-Id') !== settings.unitId) throw new ControlError('Credential belongs to another unit.', 403);
      const stub = env.UNITS.get(env.UNITS.idFromName(settings.unitId));
      if (request.method === 'PUT' && /^\/parts\/[a-f0-9]{64}\/[0-7]$/.test(url.pathname)) {
        return stub.fetch(new Request(url, { method: 'PUT', body: request.body,
          headers: { 'X-WRB-Part-Token': bearer(request), 'If-None-Match': request.headers.get('If-None-Match') ?? '' } }));
      }
      const { principal, expiresAt } = await authenticate(request, settings, stub);
      const path = url.pathname;
      if (path.startsWith('/admin/')) {
        if (principal.role !== 'admin') throw new ControlError('Administrator authority is required.', 403);
        if (request.method === 'GET' && path === '/admin/status') return control(stub, 'administratorStatus', principal);
        if (request.method !== 'POST') throw new ControlError('Unknown administrator route.', 404);
        const input = await json(request);
        if (path === '/admin/initialize') {
          fields(input, ['cursor']);
          return control(stub, 'initialize', principal, { unitId: settings.unitId, sourceId: settings.writer.sourceId, cursor: input.cursor });
        }
        if (path === '/admin/freeze') return control(stub, 'freeze', principal, input);
        if (path === '/admin/barrier') return control(stub, 'barrier', principal, input);
        if (path === '/admin/drain') return control(stub, 'drain', principal, input);
        if (path === '/admin/activate') {
          const { sourceId, ...rest } = input;
          if (rest.credentialHash === settings.writer.tokenSha256 || rest.credentialHash === settings.administrator.tokenSha256) {
            throw new ControlError('Activation requires a distinct new writer credential.', 400);
          }
          return control(stub, 'activate', principal, { ...rest, newSourceId: sourceId });
        }
        throw new ControlError('Unknown administrator route.', 404);
      }
      if (principal.role !== 'writer') throw new ControlError('Writer authority is required.', 403);
      if (request.method === 'GET' && path === '/control/status') return control(stub, 'status', principal);
      if (request.method === 'POST') {
        const input = await json(request);
        if (path === '/control/acquire' || path === '/control/renew') {
          if (!Number.isSafeInteger(input.ttl) || input.ttl < 1 || input.ttl > 3600) throw new ControlError('Invalid lease duration.', 400);
          input.ttl = Math.min(input.ttl, expiresAt - Math.floor(Date.now() / 1000));
          return control(stub, path.endsWith('/acquire') ? 'acquire' : 'renew', principal, input,
            path.endsWith('/acquire') ? undefined : session(request));
        }
        if (path === '/control/advance') return control(stub, 'advance', principal, input, session(request));
        if (path === '/upload-plans') return control(stub, 'planUpload', principal, input, session(request));
        if (path === '/part-grants') return control(stub, 'grantPart', principal, input, session(request));
        if (path === '/register') return control(stub, 'register', principal, input, session(request));
        if (path === '/verify') return control(stub, 'verify', principal, input, session(request));
        const complete = /^\/complete\/([a-f0-9]{64})$/.exec(path);
        if (complete) return control(stub, 'complete', principal, { snapshotId: complete[1], manifest: input }, session(request));
      }
      if (request.method === 'GET') {
        const current = await control(stub, 'status', principal);
        if (current.status !== 200) return current;
        if (path === '/has-objects') {
          for (const prefix of ['descriptors/', 'commits/', 'files/', 'databases/', 'manifests/', 'packs/', 'pages/']) {
            const page = await env.BUCKET.list({ prefix: `units/${settings.unitId}/${prefix}`, limit: 1 });
            if (page.objects.length) return Response.json({ hasObjects: true });
          }
          return Response.json({ hasObjects: false });
        }
        const completed = /^\/completed\/([a-f0-9]{64})$/.exec(path);
        if (completed) return Response.json(await new R2Objects(env.BUCKET, settings.unitId).completed(completed[1]));
      }
      throw new ControlError('Unknown connector route.', 404);
    } catch (error) {
      return Response.json({ error: error instanceof ControlError ? error.message : 'Local connector failed.' },
        { status: error instanceof ControlError ? error.status : 503 });
    }
  },
};
