import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { createInterface } from 'node:readline';
const packageURL = new URL('../support/r2-connector/package.json', import.meta.url);
const { Miniflare, Log, LogLevel } = createRequire(packageURL)('miniflare');
const settings = JSON.parse(await readFile(process.argv[2], 'utf8'));
const writer = process.argv[3] === 'writer';
const { createHash } = await import('node:crypto');
const sha = value => createHash('sha256').update(value).digest('hex');
const config = { format: 'wrb-local-r2-service-v1', unitId: settings.unitId, serverId: settings.serverId,
  writer: { tokenSha256: sha(settings.writerToken), sourceId: settings.sourceId, generation: 1, expiresAt: settings.expiresAt },
  administrator: { tokenSha256: sha(settings.adminToken), expiresAt: settings.expiresAt } };
const mf = new Miniflare({ modules: true, compatibilityDate: '2026-07-22', host: '127.0.0.1', port: 0, inspectorPort: 0,
  ...(writer ? { scriptPath: new URL('./src/worker.mjs', packageURL).pathname,
    durableObjects: { UNITS: { className: 'UnitCoordinator', useSQLite: true } },
    durableObjectsPersist: settings.workspace + '/do', bindings: { WRB_CONFIG: JSON.stringify(config) } }
    : { script: "export default { fetch() { return new Response('Connector absent', {status:404}); } };" }),
  r2Buckets: { BUCKET: { id: 'wrb-test-bucket', s3Credentials: { accessKeyId: settings.accessKeyId, secretAccessKey: settings.secretAccessKey } } },
  r2Persist: settings.workspace + '/r2', log: new Log(LogLevel.ERROR),
  outboundService: () => new Response('Outbound disabled', { status: 403 }) });
try {
  const url = await mf.ready;
  process.stdout.write(JSON.stringify({ endpoint: url.origin }) + '\n');
  const lines = createInterface({ input: process.stdin });
  for await (const line of lines) { if (line === 'stop') break; }
  lines.close();
} finally { await mf.dispose(); }
